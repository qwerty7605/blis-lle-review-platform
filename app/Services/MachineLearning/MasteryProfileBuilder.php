<?php

namespace App\Services\MachineLearning;

use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a student's answer history into Bayesian Knowledge Tracing estimates,
 * one per skill, at each of the three levels the analytics already speak in:
 * subject, topic, subtopic.
 *
 * The one thing that matters here is ordering. BKT reads a *sequence* -- the
 * whole reason it can tell a student climbing out of a weak topic apart from
 * one falling into it is that it sees which answers came first. So responses
 * are pulled in chronological order and kept that way; a grouped aggregate
 * (the shape the rest of DashboardAnalyticsService uses) would destroy exactly
 * the information the model exists to read.
 *
 * Guess rate comes per item from its own option count, so a true/false item is
 * correctly treated as much weaker evidence than a four-option one.
 */
class MasteryProfileBuilder
{
    /**
     * Last profile built, kept so the two callers that run in one request --
     * weak_area and study_plan on /api/student/analytics -- do not each pull
     * the student's whole answer history. Deliberately a single entry rather
     * than a map: it covers the back-to-back case without growing unbounded in
     * a long-lived queue worker.
     *
     * @var array{id: int, profile: array<string, mixed>}|null
     */
    private ?array $lastProfile = null;

    public function __construct(
        private readonly KnowledgeTracingService $knowledgeTracing,
    ) {
    }

    /**
     * @return array{
     *     subjects: array<string, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     subtopics: array<int, array<string, mixed>>
     * }
     */
    public function build(User $student): array
    {
        if ($this->lastProfile !== null && $this->lastProfile['id'] === $student->id) {
            return $this->lastProfile['profile'];
        }

        $responses = $this->fetchOrderedResponses($student);

        $profile = [
            'subjects' => $this->traceGroups($responses, 'subject'),
            'topics' => $this->traceGroups($responses, 'topic_id'),
            'subtopics' => $this->traceGroups($responses, 'subtopic_id'),
        ];

        $this->lastProfile = ['id' => $student->id, 'profile' => $profile];

        return $profile;
    }

    /**
     * Every graded answer this student has given, oldest first, carrying the
     * mapping and option count each response needs.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchOrderedResponses(User $student): array
    {
        $optionCounts = DB::table('question_bank_options')
            ->select('question_bank_question_id', DB::raw('COUNT(*) as option_count'))
            ->groupBy('question_bank_question_id');

        return DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->leftJoin('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->leftJoinSub($optionCounts, 'option_counts', 'option_counts.question_bank_question_id', '=', 'question_bank_questions.id')
            ->where('exam_attempts.user_id', $student->id)
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->orderBy('exam_attempt_answers.answered_at')
            ->orderBy('exam_attempt_answers.id')
            ->get([
                'exam_attempt_answers.is_correct',
                'question_bank_questions.topic_id',
                'question_bank_questions.subtopic_id',
                'option_counts.option_count',
                DB::raw("COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject, 'Uncategorized') as subject"),
            ])
            ->map(fn ($row): array => [
                'correct' => (bool) $row->is_correct,
                'subject' => (string) $row->subject,
                'topic_id' => $row->topic_id === null ? null : (int) $row->topic_id,
                'subtopic_id' => $row->subtopic_id === null ? null : (int) $row->subtopic_id,
                'option_count' => $row->option_count === null ? null : (int) $row->option_count,
            ])
            ->all();
    }

    /**
     * Group the ordered responses by one key and trace each group. Responses
     * with no value for that key (an unmapped item has no topic) are skipped
     * rather than pooled into a phantom skill.
     *
     * @param  list<array<string, mixed>>  $responses
     * @return array<array-key, array<string, mixed>>
     */
    private function traceGroups(array $responses, string $key): array
    {
        $sequences = [];

        foreach ($responses as $response) {
            $group = $response[$key] ?? null;

            if ($group === null || $group === '') {
                continue;
            }

            $sequences[$group][] = [
                'correct' => $response['correct'],
                'option_count' => $response['option_count'],
            ];
        }

        return array_map(
            fn (array $sequence): array => $this->knowledgeTracing->trace($sequence),
            $sequences,
        );
    }
}
