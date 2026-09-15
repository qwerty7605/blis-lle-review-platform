<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The teacher-side counterpart to StudyActionPlanner.
 *
 * Teachers already get numbers -- pass rates, score distributions, a ranked
 * list of struggling students -- and are then left to work out what to do with
 * them. This turns the same readings into moves, in the same shape the student
 * side uses, so both audiences read one consistent kind of suggestion.
 *
 * The rules are ordered by who is blocked by what:
 *
 *  1. a weak topic with no published practice   -- students are being told, on
 *     their own dashboard, that their teacher has not published anything for
 *     their worst area. Nothing else here is actively stuck like that.
 *  2. an item almost everyone missed            -- a suspect answer key poisons
 *     every analytic downstream of it, including the advice students are given
 *  3. a weak topic that already has practice    -- publishing is not the fix,
 *     so this one is a teaching decision rather than an app action
 *  4. students falling behind                   -- individual follow-up
 *  5. unreviewed machine tags                   -- quality of the mapping the
 *     rest of the analytics are built on
 */
class TeacherActionPlanner
{
    /** A cohort topic under this is worth acting on. */
    private const WEAK_TOPIC_SCORE = 60.0;

    /** At or below this correct rate an item looks miskeyed rather than hard. */
    private const SUSPECT_ITEM_CORRECT_RATE = 0.15;

    /** Fewer answers than this and a low correct rate is just a small sample. */
    private const SUSPECT_ITEM_MIN_ANSWERS = 5;

    /** Students averaging under this are the ones to follow up with. */
    private const STUDENT_CONCERN_SCORE = 60.0;

    /** Below this many published items a topic counts as having no practice. */
    private const MIN_PRACTICE_ITEMS = 3;

    private const MAX_ACTIONS = 5;

    /**
     * @param  array<string, mixed>  $analytics  from buildStaffAnalytics()
     * @return list<array<string, mixed>>
     */
    public function build(User $staff, array $analytics): array
    {
        $topics = $this->asRows($analytics['topic_performance'] ?? []);
        $students = $this->asRows($analytics['students_needing_attention'] ?? []);
        $published = $this->publishedCountsByTopic();

        $weakTopics = array_values(array_filter(
            $topics,
            fn (array $row): bool => ($row['score'] ?? null) !== null
                && (float) $row['score'] < self::WEAK_TOPIC_SCORE,
        ));

        $uncovered = array_values(array_filter(
            $weakTopics,
            fn (array $row): bool => ($published[(int) $row['topic_id']] ?? 0) < self::MIN_PRACTICE_ITEMS,
        ));

        $covered = array_values(array_filter(
            $weakTopics,
            fn (array $row): bool => ($published[(int) $row['topic_id']] ?? 0) >= self::MIN_PRACTICE_ITEMS,
        ));

        $actions = array_filter([
            $this->publishMissingPractice($uncovered[0] ?? null),
            $this->checkSuspectItem($staff),
            $this->reteachTopic($covered[0] ?? null, $published),
            $this->followUpStudents($students),
            $this->confirmMachineTags($staff),
        ]);

        return array_slice(array_values($actions), 0, self::MAX_ACTIONS);
    }

    /**
     * @param  array<string, mixed>|null  $topic
     * @return array<string, mixed>|null
     */
    private function publishMissingPractice(?array $topic): ?array
    {
        if ($topic === null) {
            return null;
        }

        return [
            'key' => 'publish_practice',
            'tone' => 'urgent',
            'area_key' => "topic:{$topic['topic_id']}",
            'advice' => $this->advice(
                "Your class has nothing to practise {$topic['label']} with.",
                [
                    $this->block('What the data shows', [
                        sprintf(
                            'Across %s, your students are averaging %s%% on %s.',
                            $topic['subject'],
                            $this->percent($topic['score']),
                            $topic['label'],
                        ),
                        sprintf('That is %d correct out of %d answers.', (int) $topic['correct_count'], (int) $topic['attempted_count']),
                        'There are no published Review Bot questions for it.',
                    ]),
                    $this->block('Why this first', [
                        'Students whose weakest area this is are currently being told on their own dashboard that no practice set exists, so this is the only thing on the list that has them actively stuck.',
                    ]),
                    $this->block('Do this', [
                        'Open the Review Bot generator, pick the Library file this topic came from, and publish a batch. Generation is one click and the questions are built from your own vetted items.',
                    ]),
                ],
            ),
            'effort' => 'one click, then review',
            'cta_label' => 'Open Review Bot generator',
            'cta_route' => 'dashboard-review-bot-generator',
            'cta_query' => null,
        ];
    }

    /**
     * An item nearly every student got wrong. Genuinely hard items exist, but
     * at this rate a miskeyed answer is the likelier explanation -- and if the
     * key is wrong, every topic score and every piece of student advice built
     * on it is wrong too.
     *
     * @return array<string, mixed>|null
     */
    private function checkSuspectItem(User $staff): ?array
    {
        $row = DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->where('exams.created_by', $staff->id)
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->groupBy('question_bank_questions.id', 'question_bank_questions.item_number', 'question_bank_questions.question_text', 'exams.title')
            ->havingRaw('COUNT(*) >= ?', [self::SUSPECT_ITEM_MIN_ANSWERS])
            ->havingRaw('SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) <= COUNT(*) * ?', [self::SUSPECT_ITEM_CORRECT_RATE])
            ->selectRaw('
                question_bank_questions.id as question_id,
                question_bank_questions.item_number,
                question_bank_questions.question_text,
                exams.title as exam_title,
                COUNT(*) as answered,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct
            ')
            ->orderByRaw('SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*) ASC')
            ->first();

        if ($row === null) {
            return null;
        }

        $answered = (int) $row->answered;
        $correct = (int) $row->correct;

        return [
            'key' => 'check_answer_key',
            'tone' => 'primary',
            'area_key' => "question:{$row->question_id}",
            'advice' => $this->advice(
                sprintf('Item %d in "%s" may be miskeyed.', (int) $row->item_number, $row->exam_title),
                [
                    $this->block('What the data shows', [
                        sprintf(
                            'Of the %d students who answered it, %s got it right.',
                            $answered,
                            $correct === 0 ? 'none' : ($correct === 1 ? 'one' : (string) $correct),
                        ),
                        sprintf('"%s"', $this->excerpt((string) $row->question_text)),
                    ]),
                    $this->block('Why this matters', [
                        'Genuinely hard items exist, but at this rate a wrong answer key is the likelier explanation. If it is miskeyed, every topic score built on it is wrong, and so is the study advice your students are being given off the back of it.',
                    ]),
                    $this->block('Do this', [
                        'Open the item in the Library and check the marked answer against the source material.',
                    ]),
                ],
            ),
            'effort' => 'about 2 min',
            'cta_label' => 'Open the Library',
            'cta_route' => 'dashboard-library',
            'cta_query' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $topic
     * @param  array<int, int>  $published
     * @return array<string, mixed>|null
     */
    private function reteachTopic(?array $topic, array $published): ?array
    {
        if ($topic === null) {
            return null;
        }

        $available = $published[(int) $topic['topic_id']] ?? 0;

        return [
            'key' => 'reteach_topic',
            'tone' => 'opportunity',
            'area_key' => "topic:{$topic['topic_id']}",
            'advice' => $this->advice(
                "{$topic['label']} is not landing with the class.",
                [
                    $this->block('What the data shows', [
                        sprintf(
                            'Your students are averaging %s%% on it, across %d answers in %s.',
                            $this->percent($topic['score']),
                            (int) $topic['attempted_count'],
                            $topic['subject'],
                        ),
                        sprintf('There are already %d published review questions for it.', $available),
                    ]),
                    $this->block('Why this matters', [
                        'Practice material is not the gap here, so more questions will not fix it on their own. A cohort-wide score this low usually points at the teaching of the concept rather than the amount of drilling.',
                    ]),
                    $this->block('Do this', [
                        'Cover the topic again before the next mock exam, then check whether this number moves.',
                    ]),
                ],
            ),
            'effort' => 'next session',
            'cta_label' => null,
            'cta_route' => null,
            'cta_query' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $students
     * @return array<string, mixed>|null
     */
    private function followUpStudents(array $students): ?array
    {
        $behind = array_values(array_filter(
            $students,
            fn (array $row): bool => ($row['average_score_percent'] ?? null) !== null
                && (float) $row['average_score_percent'] < self::STUDENT_CONCERN_SCORE,
        ));

        if ($behind === []) {
            return null;
        }

        $names = array_slice(array_map(
            fn (array $row): string => sprintf('%s (%s%%)', $row['name'], $this->percent($row['average_score_percent'])),
            $behind,
        ), 0, 3);

        return [
            'key' => 'follow_up_students',
            'tone' => 'info',
            'area_key' => 'students',
            'advice' => $this->advice(
                sprintf(
                    '%d %s far enough behind to need individual follow-up.',
                    count($behind),
                    count($behind) === 1 ? 'student is' : 'students are',
                ),
                [
                    $this->block('Who', $names),
                    $this->block('Why this matters', [
                        'A class average can sit near passing while individual students are well under it. These are the ones a cohort-level number hides.',
                    ]),
                    $this->block('Do this', [
                        'Open the student directory to see each one\'s subject breakdown and exam history before you talk to them.',
                    ]),
                ],
            ),
            'effort' => null,
            'cta_label' => 'Open student directory',
            'cta_route' => 'dashboard-students',
            'cta_query' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function confirmMachineTags(User $staff): ?array
    {
        $pending = (int) DB::table('question_bank_questions')
            ->join('question_banks', 'question_banks.id', '=', 'question_bank_questions.question_bank_id')
            ->where('question_banks.created_by', $staff->id)
            ->where('question_bank_questions.tag_status', QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW)
            ->count();

        if ($pending === 0) {
            return null;
        }

        return [
            'key' => 'confirm_tags',
            'tone' => 'info',
            'area_key' => 'tags',
            'advice' => $this->advice(
                sprintf('%d of your questions carry topics nobody has checked.', $pending),
                [
                    $this->block('What the data shows', [
                        sprintf('%d questions in your Library are still marked as pending review.', $pending),
                        'Their topics were assigned by the classifier, not confirmed by you.',
                    ]),
                    $this->block('Why this matters', [
                        'These tags already count toward every weak-area reading on the system, your reports and your students\' study advice included. Where a tag is wrong, the advice built on it points at the wrong topic.',
                    ]),
                    $this->block('Do this', [
                        'Open the Library and confirm or correct the pending tags on those banks.',
                    ]),
                ],
            ),
            'effort' => null,
            'cta_label' => 'Review tags in the Library',
            'cta_route' => 'dashboard-library',
            'cta_query' => null,
        ];
    }

    /**
     * Published Review Bot stock per topic, so "no practice exists" is a fact
     * rather than an assumption.
     *
     * @return array<int, int>
     */
    private function publishedCountsByTopic(): array
    {
        return DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->whereNotNull('topic_id')
            ->selectRaw('topic_id, COUNT(*) as total')
            ->groupBy('topic_id')
            ->pluck('total', 'topic_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * @param  mixed  $rows
     * @return list<array<string, mixed>>
     */
    private function asRows(mixed $rows): array
    {
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function advice(string $lead, array $blocks): array
    {
        return [
            'lead' => $lead,
            'blocks' => array_values(array_filter($blocks, fn (array $block): bool => $block['points'] !== [])),
        ];
    }

    /**
     * @param  list<string>  $points
     * @return array<string, mixed>
     */
    private function block(string $label, array $points): array
    {
        return [
            'label' => $label,
            'points' => array_values(array_filter($points, fn (string $point): bool => trim($point) !== '')),
        ];
    }

    private function excerpt(string $text, int $limit = 110): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($clean) > $limit
            ? mb_substr($clean, 0, $limit - 1) . '…'
            : $clean;
    }

    private function percent(mixed $value): string
    {
        return $value === null ? '—' : (string) round((float) $value);
    }
}
