<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBankQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExamAttemptService
{
    public function __construct(
        private readonly ExamDeliveryModeService $deliveryModeService,
        private readonly ExamResultFeedbackService $examResultFeedbackService,
    ) {
    }

    public function refreshMetrics(ExamAttempt $attempt, bool $finalize = false): ExamAttempt
    {
        $answers = $attempt->answers()
            ->select('is_correct')
            ->get();

        $answeredCount = $answers->count();
        $correctAnswers = $answers->where('is_correct', true)->count();
        $scorePercent = $attempt->total_items > 0
            ? round(($correctAnswers / $attempt->total_items) * 100, 2)
            : 0.0;

        $attempt->answered_count = $answeredCount;
        $attempt->correct_answers = $correctAnswers;

        if ($finalize) {
            $attempt->status = ExamAttempt::STATUS_SUBMITTED;
            $attempt->submitted_at = $attempt->submitted_at ?? now();
            $attempt->score_percent = $scorePercent;
        }

        if ($attempt->status === ExamAttempt::STATUS_SUBMITTED && is_null($attempt->score_percent)) {
            $attempt->score_percent = $scorePercent;
        }

        $attempt->save();

        return $attempt->refresh();
    }

    /**
     * Whether a typed answer to an open-ended question is right: it matches
     * the expected answer, or its label, ignoring case and spacing.
     */
    public function isCorrectOpenAnswer(QuestionBankQuestion $question, ?string $answerText): bool
    {
        $normalize = fn (?string $value): string => Str::lower((string) preg_replace('/\s+/', ' ', trim((string) $value)));
        $submitted = $normalize($answerText);

        if ($submitted === '') {
            return false;
        }

        return ($normalize($question->answer_text) !== '' && $submitted === $normalize($question->answer_text))
            || ($normalize($question->answer_label) !== '' && $submitted === $normalize($question->answer_label));
    }

    /**
     * Marks every exam answer to $question again, as the answer key now
     * stands, and works out the score of each attempt whose marks changed
     * (handed-in ones included: the score in the reports follows the key).
     *
     * @return array{answers: int, attempts: int, attempt_ids: list<int>}
     */
    public function regradeQuestion(QuestionBankQuestion $question): array
    {
        $question->loadMissing('options:id,question_bank_question_id,is_correct');
        $correctOptionIds = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $openEnded = $question->question_type === QuestionBankQuestion::TYPE_OPEN_ENDED;

        $changed = ExamAttemptAnswer::query()
            ->where('question_bank_question_id', $question->id)
            ->get(['id', 'exam_attempt_id', 'question_bank_option_id', 'answer_text', 'is_correct'])
            ->filter(function (ExamAttemptAnswer $answer) use ($correctOptionIds, $openEnded, $question): bool {
                $right = $openEnded
                    ? $this->isCorrectOpenAnswer($question, $answer->answer_text)
                    : in_array((int) $answer->question_bank_option_id, $correctOptionIds, true);

                if ((bool) $answer->is_correct === $right) {
                    return false;
                }

                $answer->is_correct = $right;

                return true;
            });

        if ($changed->isEmpty()) {
            return ['answers' => 0, 'attempts' => 0, 'attempt_ids' => []];
        }

        $attemptIds = $changed->pluck('exam_attempt_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        DB::transaction(function () use ($changed, $attemptIds): void {
            $changed->each(fn (ExamAttemptAnswer $answer) => $answer->save());

            ExamAttempt::query()->whereIn('id', $attemptIds)->get()->each(function (ExamAttempt $attempt): void {
                $correct = $attempt->answers()->where('is_correct', true)->count();
                $attempt->correct_answers = $correct;

                if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
                    $attempt->score_percent = $attempt->total_items > 0
                        ? round(($correct / $attempt->total_items) * 100, 2)
                        : 0.0;
                }

                $attempt->save();
            });
        });

        return ['answers' => $changed->count(), 'attempts' => count($attemptIds), 'attempt_ids' => $attemptIds];
    }

    public function autoSubmitExpiredAttempt(ExamAttempt $attempt): ExamAttempt
    {
        if (
            $attempt->status !== ExamAttempt::STATUS_IN_PROGRESS
            || is_null($attempt->expires_at)
            || now()->lessThanOrEqualTo($attempt->expires_at)
        ) {
            return $attempt;
        }

        // It ended at its deadline, not whenever somebody next looked at it:
        // a student who closed the tab is submitted at the time they ran out.
        $attempt->submitted_at = $attempt->expires_at;

        return $this->refreshMetrics($attempt, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAttemptPayload(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'exam:id,title,subject,question_bank_id,total_items,duration_minutes,scheduled_at,schedule_start_at,schedule_end_at,ends_at_close,delivery_mode,one_take_only,shuffle_questions,results_visibility_mode,answers_shown_after',
            'exam.questionBanks:id,title,subject,total_items',
            'room:id,name,code',
            'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
            'answers',
        ]);

        $resolvedQuestionBanks = $attempt->exam?->resolvedQuestionBanks() ?? collect();
        $resolvedQuestionBankIds = $resolvedQuestionBanks
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $deliveryMode = $this->deliveryModeService->normalize($attempt->exam?->delivery_mode);
        $isSubmitted = $attempt->status === ExamAttempt::STATUS_SUBMITTED;
        $visibilityMode = $attempt->exam?->results_visibility_mode ?? 'hidden';
        $showPerQuestionFeedback = $isSubmitted && (bool) $attempt->exam?->answersShownFor($attempt);
        // Visible results whose answers wait for the student's last attempt.
        $answersHeldBack = $isSubmitted && $visibilityMode === 'visible' && !$showPerQuestionFeedback;
        $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');
        $feedback = $isSubmitted
            ? $this->examResultFeedbackService->buildForAttempt($attempt)
            : null;

        $questions = $attempt->attemptQuestions
            ->sortBy('item_number')
            ->values()
            ->map(function (ExamAttemptQuestion $attemptQuestion) use (
                $answersByQuestionId,
                $isSubmitted,
                $showPerQuestionFeedback
            ): ?array {
                $question = $attemptQuestion->question;

                if (!$question) {
                    return null;
                }

                $answer = $answersByQuestionId->get($question->id);
                $hasAnswer = !is_null($answer);

                return [
                    'question_id' => $question->id,
                    'item_number' => $attemptQuestion->item_number,
                    'is_bookmarked' => (bool) $attemptQuestion->is_bookmarked,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'options' => $question->options->map(fn ($option) => [
                        'id' => $option->id,
                        'label' => $option->option_label,
                        'text' => $option->option_text,
                        'is_correct' => $showPerQuestionFeedback ? (bool) $option->is_correct : null,
                    ])->values(),
                    'answer' => [
                        'selected_option_id' => $answer?->question_bank_option_id,
                        'answer_text' => $answer?->answer_text,
                        'is_correct' => $showPerQuestionFeedback && $hasAnswer ? $answer?->is_correct : null,
                    ],
                    'correct_answer' => $showPerQuestionFeedback
                        ? [
                            'label' => $question->answer_label,
                            'text' => $question->answer_text,
                        ]
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $remainingSeconds = null;

        if ($attempt->status === ExamAttempt::STATUS_IN_PROGRESS && $attempt->expires_at) {
            $remainingSeconds = max(0, now()->diffInSeconds($attempt->expires_at, false));
        }

        // Every sitting of this exam by this student, oldest first, so the
        // result page can offer the other one. The latest is the one that
        // counts in the student's figures and the teacher's reports.
        $sittings = ExamAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where('exam_id', $attempt->exam_id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get(['id', 'score_percent', 'submitted_at']);

        return [
            'sittings' => $sittings->values()->map(fn (ExamAttempt $sitting, int $index): array => [
                'id' => $sitting->id,
                'number' => $index + 1,
                'score_percent' => $sitting->score_percent,
                'submitted_at' => $sitting->submitted_at,
                'counts' => $index === $sittings->count() - 1,
            ])->all(),
            'attempt' => [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'total_items' => $attempt->total_items,
                'duration_minutes' => $attempt->duration_minutes,
                'answered_count' => $attempt->answered_count,
                'correct_answers' => $attempt->correct_answers,
                'score_percent' => $attempt->score_percent,
                'started_at' => $attempt->started_at,
                'expires_at' => $attempt->expires_at,
                'submitted_at' => $attempt->submitted_at,
                'remaining_seconds' => $remainingSeconds,
                'extra_minutes' => (int) $attempt->extra_minutes,
                'answers_held_back' => $answersHeldBack,
            ],
            'exam' => [
                'id' => $attempt->exam?->id ?? $attempt->exam_id,
                'title' => $attempt->exam?->title,
                'subject' => $attempt->exam?->subject,
                'question_bank_id' => $attempt->exam?->question_bank_id,
                'question_bank_ids' => $resolvedQuestionBankIds,
                'question_banks' => $resolvedQuestionBanks
                    ->map(fn ($bank) => [
                        'id' => (int) $bank->id,
                        'title' => $bank->title,
                        'subject' => $bank->subject,
                        'total_items' => (int) ($bank->total_items ?? 0),
                    ])
                    ->values()
                    ->all(),
                'total_items' => $attempt->exam?->total_items,
                'duration_minutes' => $attempt->exam?->duration_minutes,
                'scheduled_at' => $attempt->exam?->scheduled_at,
                'schedule_start_at' => $attempt->exam?->schedule_start_at,
                'schedule_end_at' => $attempt->exam?->schedule_end_at,
                'ends_at_close' => (bool) ($attempt->exam?->ends_at_close ?? false),
                'delivery_mode' => $deliveryMode,
                'one_take_only' => (bool) ($attempt->exam?->one_take_only ?? false),
                'shuffle_questions' => (bool) ($attempt->exam?->shuffle_questions ?? false),
                'results_visibility_mode' => $attempt->exam?->results_visibility_mode ?? 'hidden',
                'answers_shown_after' => $attempt->exam?->answers_shown_after ?? Exam::ANSWERS_AFTER_EACH_ATTEMPT,
            ],
            'room' => [
                'id' => $attempt->room?->id ?? $attempt->room_id,
                'name' => $attempt->room?->name,
                'code' => $attempt->room?->code,
            ],
            'feedback' => $feedback,
            'questions' => $questions,
        ];
    }
}
