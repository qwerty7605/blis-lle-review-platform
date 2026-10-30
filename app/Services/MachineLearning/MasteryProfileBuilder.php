<?php

namespace App\Services\MachineLearning;

use App\Models\ExamAttempt;
use App\Models\User;
use App\Support\LleTaxonomy;
use Illuminate\Support\Facades\DB;

/**
 * Turns a student's exams into Bayesian Knowledge Tracing estimates for each
 * TOS topic and sub-topic (KnowledgeTracingService), one learning opportunity
 * per exam.
 *
 * What it reads, for every exam the student has submitted: the latest
 * sitting, and in it every item the student was dealt (exam_attempt_questions),
 * correct or not -- a blank counts as wrong, exactly as scoring counts it
 * (ExamAttemptService: correct answers over the attempt's items). Exams are
 * read in the order those sittings were submitted. Inside one exam the order
 * of answers is not read at all: items are shuffled and nobody is told
 * mid-exam whether they were right.
 *
 * "Other / Unclassified" items and untagged items are left out: they count
 * toward their subject's score, never toward a topic or sub-topic. Subjects
 * are not traced; a subject shows its percent correct (latestSittingItems()
 * feeds both). Review Bot sessions are not read.
 *
 * Guess rate comes per item from its own option count, so a true/false item
 * is weaker evidence than a four-option one.
 */
class MasteryProfileBuilder
{
    /**
     * Last student read, kept so the callers that run in one request --
     * weak_area, study_plan and the mastery panel on /api/student/analytics --
     * do not each pull the student's whole history. Deliberately a single
     * entry rather than a map: it covers the back-to-back case without
     * growing unbounded in a long-lived queue worker.
     *
     * @var array{id: int, items: list<array<string, mixed>>, profile: ?array<string, mixed>}|null
     */
    private ?array $last = null;

    public function __construct(
        private readonly KnowledgeTracingService $knowledgeTracing,
    ) {
    }

    /**
     * @return array{
     *     subjects: array<string, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     subtopics: array<int, array<string, mixed>>
     * } `subjects` is always empty: subjects are not traced.
     */
    public function build(User $student): array
    {
        $items = $this->latestSittingItems($student);

        if ($this->last['profile'] ?? null) {
            return $this->last['profile'];
        }

        $profile = [
            'subjects' => [],
            'topics' => $this->traceSkills($items, 'topic_id'),
            'subtopics' => $this->traceSkills($items, 'subtopic_id'),
        ];

        $this->last['profile'] = $profile;

        return $profile;
    }

    /**
     * Every item of every exam's latest submitted sitting, exams oldest
     * first, each with its subject, topic, sub-topic, whether it was
     * answered correctly (a blank is not) and its number of options.
     *
     * @return list<array{attempt_id: int, submitted_at: string, subject: string, topic_id: ?int, topic: ?string, topic_subject: ?string, other: bool, subtopic_id: ?int, subtopic: ?string, correct: bool, option_count: ?int}>
     */
    public function latestSittingItems(User $student): array
    {
        if ($this->last !== null && $this->last['id'] === $student->id) {
            return $this->last['items'];
        }

        $attempts = ExamAttempt::latestSubmittedPerExam($student->id);
        $order = $attempts->pluck('id')->flip();

        $optionCounts = DB::table('question_bank_options')
            ->select('question_bank_question_id', DB::raw('COUNT(*) as option_count'))
            ->groupBy('question_bank_question_id');

        $rows = $attempts->isEmpty() ? collect() : DB::table('exam_attempt_questions')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_questions.question_bank_question_id')
            ->leftJoin('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->leftJoin('subtopics', 'subtopics.id', '=', 'question_bank_questions.subtopic_id')
            ->leftJoin('exam_attempt_answers', function ($join): void {
                $join->on('exam_attempt_answers.exam_attempt_id', '=', 'exam_attempt_questions.exam_attempt_id')
                    ->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id');
            })
            ->leftJoinSub($optionCounts, 'option_counts', 'option_counts.question_bank_question_id', '=', 'question_bank_questions.id')
            ->whereIn('exam_attempt_questions.exam_attempt_id', $attempts->pluck('id')->all())
            ->orderBy('exam_attempt_questions.exam_attempt_id')
            ->orderBy('exam_attempt_questions.item_number')
            ->get([
                'exam_attempt_questions.exam_attempt_id',
                'question_bank_questions.subject',
                'question_bank_questions.topic_id',
                'topics.name as topic_name',
                'topics.subject as topic_subject',
                'question_bank_questions.subtopic_id',
                'subtopics.name as subtopic_name',
                'exam_attempt_answers.is_correct',
                'option_counts.option_count',
            ]);

        $submittedAt = $attempts->mapWithKeys(fn (ExamAttempt $attempt): array => [$attempt->id => $attempt->submitted_at->toDateTimeString()]);

        $items = $rows
            ->sortBy(fn ($row): int => (int) $order[(int) $row->exam_attempt_id])
            ->map(function ($row) use ($submittedAt): array {
                $subject = trim((string) ($row->subject ?? ''));

                return [
                    'attempt_id' => (int) $row->exam_attempt_id,
                    'submitted_at' => (string) $submittedAt[(int) $row->exam_attempt_id],
                    'subject' => $subject !== '' ? $subject : (trim((string) ($row->topic_subject ?? '')) ?: 'Uncategorized'),
                    'topic_id' => $row->topic_id === null ? null : (int) $row->topic_id,
                    'topic' => $row->topic_name,
                    'topic_subject' => $row->topic_subject,
                    'other' => LleTaxonomy::isOther($row->topic_name),
                    'subtopic_id' => $row->subtopic_id === null ? null : (int) $row->subtopic_id,
                    'subtopic' => $row->subtopic_name,
                    'correct' => (bool) $row->is_correct,
                    'option_count' => $row->option_count === null ? null : (int) $row->option_count,
                ];
            })
            ->values()
            ->all();

        $this->last = ['id' => $student->id, 'items' => $items, 'profile' => null];

        return $items;
    }

    /**
     * One trace per skill at one level: the skill's items grouped by exam, in
     * exam order. Untagged and "Other / Unclassified" items are not a skill.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function traceSkills(array $items, string $key): array
    {
        $exams = [];

        foreach ($items as $item) {
            $skill = $item[$key] ?? null;

            if ($skill === null || $item['other']) {
                continue;
            }

            $exams[$skill][$item['attempt_id']][] = [
                'correct' => $item['correct'],
                'option_count' => $item['option_count'],
            ];
        }

        return array_map(
            fn (array $byExam): array => $this->knowledgeTracing->traceExams(array_values($byExam)),
            $exams,
        );
    }
}
