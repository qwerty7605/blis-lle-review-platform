<?php

namespace App\Console\Commands;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Services\Library\DocxQuestionParser;
use App\Services\QuestionClassification\LocalQuestionClassifier;
use App\Services\QuestionClassification\TopicKeywordDictionary;
use App\Services\QuestionMappingService;
use App\Support\LleTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Tags a set of questions with the TOS keywords and says, per subject, how
 * many got a sub-topic, how many went to "Other / Unclassified" and how many
 * would wait for a teacher's review (a topic match below the confidence the
 * tagger trusts). Nothing is saved: it is for judging the keyword list
 * (TopicKeywordDictionary), on Library sets in the database or on .docx
 * question files read the way the Library import reads them.
 *
 *   php artisan tos:keyword-report --docx=docs/testing/Sample-Cataloging-10-items.docx
 *   php artisan tos:keyword-report --bank=12 --bank=15 --details
 *   php artisan tos:keyword-report --all-banks
 */
class TosKeywordReportCommand extends Command
{
    protected $signature = 'tos:keyword-report
        {--bank=* : A Library question set id}
        {--all-banks : Every Library question set in the database}
        {--docx=* : A .docx question file}
        {--subject= : The subject of the --docx files (detected from the questions when left out)}
        {--details : List every question with where it went}';

    protected $description = 'Report how the TOS keyword list tags a set of questions';

    private const SUBTOPIC = 'subtopic';
    private const OTHER = 'other';
    private const REVIEW = 'review';

    public function handle(LocalQuestionClassifier $classifier, QuestionMappingService $mapping, DocxQuestionParser $parser): int
    {
        /** @var array<int, array{source: string, subject: string, question: QuestionBankQuestion}> $items */
        $items = [];

        foreach ($this->banks() as $bank) {
            $subject = LleTaxonomy::canonicalSubject($bank->subject);

            if ($subject === null) {
                $this->warn(sprintf('Set #%d "%s" has no board subject ("%s"); left out.', $bank->id, $bank->title, $bank->subject));

                continue;
            }

            foreach ($bank->assessmentQuestions as $question) {
                $items[] = ['source' => "set #{$bank->id}", 'subject' => $subject, 'question' => $question];
            }
        }

        foreach ((array) $this->option('docx') as $path) {
            try {
                $parsed = $parser->parse((string) $path)['questions'];
            } catch (Throwable $exception) {
                $this->error(sprintf('Could not read %s: %s', $path, $exception->getMessage()));

                return self::FAILURE;
            }

            $subject = LleTaxonomy::canonicalSubject($this->option('subject'))
                ?? $mapping->suggestSubjectForParsedQuestions($parsed)['subject'];

            if ($subject === null || LleTaxonomy::subjectKey($subject) === null) {
                $this->warn(sprintf('No board subject for %s; pass --subject. Left out.', $path));

                continue;
            }

            foreach ($parsed as $index => $row) {
                $items[] = ['source' => basename((string) $path) . ' #' . ($index + 1), 'subject' => $subject, 'question' => $this->unsavedQuestion($row, $subject)];
            }
        }

        if ($items === []) {
            $this->warn('No questions to tag. Pass --bank, --all-banks or --docx.');

            return self::FAILURE;
        }

        $tally = [];
        $details = [];

        foreach ($items as $item) {
            $result = $classifier->classifyTopic($item['question'], $item['subject']);
            $outcome = match (true) {
                $result->unclassified => self::OTHER,
                $result->confidence < LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD => self::REVIEW,
                default => self::SUBTOPIC,
            };

            $tally[$item['subject']] ??= [self::SUBTOPIC => 0, self::OTHER => 0, self::REVIEW => 0];
            $tally[$item['subject']][$outcome]++;

            $details[] = [
                $item['source'],
                $this->shorten((string) $item['question']->question_text, 60),
                match ($outcome) {
                    self::OTHER => LleTaxonomy::OTHER_TOPIC,
                    default => $result->subtopic ?? $result->topic ?? '',
                },
                $outcome === self::REVIEW ? sprintf('review (%.2f)', $result->confidence) : ($outcome === self::OTHER ? 'other' : sprintf('%.2f', $result->confidence)),
            ];
        }

        $rows = [];
        $totals = [self::SUBTOPIC => 0, self::OTHER => 0, self::REVIEW => 0];

        foreach (LleTaxonomy::subjectNames() as $subject) {
            if (!isset($tally[$subject])) {
                continue;
            }

            $counts = $tally[$subject];
            $rows[] = [$subject, array_sum($counts), $counts[self::SUBTOPIC], $counts[self::OTHER], $counts[self::REVIEW]];

            foreach ($counts as $outcome => $count) {
                $totals[$outcome] += $count;
            }
        }

        $rows[] = ['All subjects', array_sum($totals), $totals[self::SUBTOPIC], $totals[self::OTHER], $totals[self::REVIEW]];

        $this->table(['Subject', 'Questions', 'Got a sub-topic', 'Went to Other', 'Await review'], $rows);
        $this->line(sprintf(
            'Await review: the best topic match was below %.2f confidence. Keyword list: %d phrases over %d sub-topics.',
            LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD,
            TopicKeywordDictionary::phraseCount(),
            LleTaxonomy::subtopicCount(),
        ));

        if ($this->option('details')) {
            $this->table(['Question', 'Text', 'Tagged', 'Confidence'], $details);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, QuestionBank>
     */
    private function banks(): Collection
    {
        $ids = array_map('intval', (array) $this->option('bank'));

        if ($ids === [] && !$this->option('all-banks')) {
            return new Collection();
        }

        return QuestionBank::query()
            ->excludingReviewBotBanks()
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->with('assessmentQuestions.options')
            ->orderBy('id')
            ->get();
    }

    /**
     * A parsed question as an unsaved row, which is all the tagger reads.
     *
     * @param  array<string, mixed>  $row
     */
    private function unsavedQuestion(array $row, string $subject): QuestionBankQuestion
    {
        $question = new QuestionBankQuestion([
            'question_text' => (string) ($row['text'] ?? ''),
            'answer_text' => $row['answer_text'] ?? null,
            'subject' => $subject,
        ]);

        $question->setRelation('options', collect($row['options'] ?? [])->map(fn (array $option): QuestionBankOption => new QuestionBankOption([
            'option_label' => $option['label'] ?? null,
            'option_text' => (string) ($option['text'] ?? ''),
            'is_correct' => (bool) ($option['is_correct'] ?? false),
        ])));

        return $question;
    }

    private function shorten(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
