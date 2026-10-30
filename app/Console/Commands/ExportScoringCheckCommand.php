<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBankQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Exports a sample of submitted exam attempts that recorded their items, so
 * scoring can be checked by hand: for every item dealt, the answer key, the
 * student's answer and what was stored, and for every attempt the stored
 * score beside the score worked out again from the key.
 *
 * Scoring as the app does it (ExamAttemptService::refreshMetrics()): an item
 * is right when the chosen choice is marked correct (or, for an open-ended
 * item, the typed answer equals the key); a blank is wrong; the score is
 * correct / items dealt x 100, to two places.
 *
 *   php artisan scoring:export                 30 attempts, picked with seed 1
 *   php artisan scoring:export --count=50 --seed=7
 *   php artisan scoring:export --exam=12       only that exam's attempts
 *
 * Writes two CSV files under storage/app/private/scoring-check/ and prints
 * the attempts. Students appear by student ID only. Nothing is changed.
 */
class ExportScoringCheckCommand extends Command
{
    protected $signature = 'scoring:export
        {--count=30 : How many submitted attempts}
        {--seed=1 : The same seed picks the same attempts again}
        {--exam=* : Only attempts of these exam ids}';

    protected $description = 'Export submitted attempts with each item, the key, the answer and the stored score, to re-check scoring by hand';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $examIds = array_map('intval', (array) $this->option('exam'));

        $ids = ExamAttempt::query()
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->when($examIds !== [], fn ($query) => $query->whereIn('exam_id', $examIds))
            // Only attempts that recorded their items: there is nothing to
            // check by hand in a score stored on its own.
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('exam_attempt_questions')
                ->whereColumn('exam_attempt_questions.exam_attempt_id', 'exam_attempts.id'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            $this->warn('No submitted attempts to export.');

            return self::SUCCESS;
        }

        mt_srand((int) $this->option('seed'));
        shuffle($ids);
        $picked = array_slice($ids, 0, $count);
        sort($picked);

        $attempts = ExamAttempt::query()
            ->whereIn('id', $picked)
            ->with([
                'student:id,student_id',
                'exam:id,title,exam_type',
                'attemptQuestions' => fn ($query) => $query->orderBy('item_number'),
                'attemptQuestions.question.options',
                'answers.selectedOption',
            ])
            ->orderBy('id')
            ->get();

        $itemRows = [];
        $attemptRows = [];

        foreach ($attempts as $attempt) {
            $answers = $attempt->answers->keyBy('question_bank_question_id');
            $correctByKey = 0;
            $mismatches = 0;

            foreach ($attempt->attemptQuestions as $dealt) {
                $question = $dealt->question;
                $answer = $answers->get($dealt->question_bank_question_id);
                $rightByKey = $answer !== null && $question !== null && $this->isRightByKey($question, $answer);
                $correctByKey += $rightByKey ? 1 : 0;
                $stored = $answer?->is_correct;

                if ((bool) $stored !== $rightByKey) {
                    $mismatches++;
                }

                $itemRows[] = [
                    $attempt->id,
                    $attempt->student?->student_id,
                    $attempt->exam_id,
                    $attempt->exam?->title,
                    $dealt->item_number,
                    $dealt->question_bank_question_id,
                    $question?->question_type,
                    $question ? $this->oneLine($question->question_text) : '(question deleted)',
                    $question ? $this->answerKey($question) : '',
                    $answer ? $this->studentAnswer($answer) : '(blank)',
                    $answer === null ? 'blank' : ($stored ? 'right' : 'wrong'),
                    $rightByKey ? 'right' : 'wrong',
                    (bool) $stored === $rightByKey ? 'yes' : 'NO',
                ];
            }

            $dealtCount = $attempt->attemptQuestions->count();
            $total = (int) $attempt->total_items;
            $recomputed = $total > 0 ? round($correctByKey / $total * 100, 2) : 0.0;
            $scoreMatches = abs((float) $attempt->score_percent - $recomputed) < 0.005
                && (int) $attempt->correct_answers === $correctByKey;

            $attemptRows[] = [
                $attempt->id,
                $attempt->student?->student_id,
                $attempt->exam_id,
                $attempt->exam?->title,
                Exam::TYPE_LABELS[$attempt->exam?->exam_type] ?? $attempt->exam?->exam_type,
                $attempt->submitted_at?->toDateTimeString(),
                $total,
                $dealtCount,
                (int) $attempt->answered_count,
                (int) $attempt->correct_answers,
                $attempt->score_percent === null ? '' : number_format((float) $attempt->score_percent, 2, '.', ''),
                $correctByKey,
                number_format($recomputed, 2, '.', ''),
                $scoreMatches && $mismatches === 0 && $dealtCount === $total ? 'yes' : 'NO',
            ];
        }

        $stamp = now()->format('Ymd-His');
        $itemsPath = "scoring-check/scoring-{$stamp}-items.csv";
        $attemptsPath = "scoring-check/scoring-{$stamp}-attempts.csv";

        $disk = Storage::disk('local');
        $disk->put($itemsPath, $this->csv([
            'attempt_id', 'student_id', 'exam_id', 'exam', 'item_number', 'question_id', 'question_type', 'question',
            'answer_key', 'student_answer', 'stored_result', 'result_by_key', 'agrees',
        ], $itemRows));
        $disk->put($attemptsPath, $this->csv([
            'attempt_id', 'student_id', 'exam_id', 'exam', 'exam_type', 'submitted_at', 'total_items', 'items_dealt',
            'stored_answered', 'stored_correct', 'stored_score_percent', 'correct_by_key', 'score_by_key', 'agrees',
        ], $attemptRows));

        $this->table(
            ['Attempt', 'Student ID', 'Exam', 'Items', 'Dealt', 'Stored correct', 'Stored score', 'By key', 'Score by key', 'Agrees'],
            array_map(fn (array $row): array => [$row[0], $row[1], $row[3], $row[6], $row[7], $row[9], $row[10], $row[11], $row[12], $row[13]], $attemptRows),
        );

        $disagreeing = count(array_filter($attemptRows, fn (array $row): bool => $row[13] !== 'yes'));
        $this->line(sprintf('%d attempts, %d items. Written to:', count($attemptRows), count($itemRows)));
        $this->line('  ' . $disk->path($attemptsPath));
        $this->line('  ' . $disk->path($itemsPath));

        if ($disagreeing > 0) {
            $this->warn("{$disagreeing} attempt(s) where the stored figures and the key disagree: see the rows marked NO.");
        }

        return self::SUCCESS;
    }

    /**
     * Whether the answer is right by the key, worked out from the question
     * rather than read from what was stored.
     */
    private function isRightByKey(QuestionBankQuestion $question, $answer): bool
    {
        if ($question->question_type === QuestionBankQuestion::TYPE_OPEN_ENDED) {
            $typed = $this->normalize($answer->answer_text);

            return $typed !== ''
                && ($typed === $this->normalize($question->answer_text) || $typed === $this->normalize($question->answer_label));
        }

        $correct = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id);

        return $answer->question_bank_option_id !== null && $correct->contains((int) $answer->question_bank_option_id);
    }

    private function answerKey(QuestionBankQuestion $question): string
    {
        if ($question->question_type === QuestionBankQuestion::TYPE_OPEN_ENDED) {
            return trim(($question->answer_label ? $question->answer_label . '. ' : '') . (string) $question->answer_text);
        }

        $correct = $question->options->where('is_correct', true);

        return $correct->isEmpty()
            ? '(no choice marked correct)'
            : $correct->map(fn ($option): string => $option->option_label . '. ' . $this->oneLine($option->option_text))->implode(' | ');
    }

    private function studentAnswer($answer): string
    {
        if ($answer->selectedOption) {
            return $answer->selectedOption->option_label . '. ' . $this->oneLine($answer->selectedOption->option_text);
        }

        return $this->oneLine((string) $answer->answer_text);
    }

    /** The same comparison the exam page uses for a typed answer. */
    private function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    private function oneLine(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    private function csv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        // A byte-order mark, so Excel reads names with accents correctly.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }
}
