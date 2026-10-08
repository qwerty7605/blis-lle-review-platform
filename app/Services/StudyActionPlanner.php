<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use Illuminate\Support\Facades\DB;

/**
 * Turns the analytics readings into things a student can actually go and do.
 *
 * The distinction this class exists to draw: "Authority Control, 0 of 3
 * correct, grasp 17%" is a description of the past, and a student reading it
 * still has to work out what to do about it.
 *
 * Each action is written the way a person explaining it would lay it out: a
 * one-line summary of the situation, then what happened, why it is worth doing
 * now, and the move itself. Broken into parts rather than run together as a
 * paragraph, so the page can be scanned at the headline level and read down
 * into only where the student wants to know why something is being suggested.
 *
 * Alongside the advice each action carries:
 *
 *   title   -- the topic or subject by name, with `subject` saying where a
 *              topic belongs (empty when the title is itself a subject)
 *   summary -- one plain sentence on why it is listed; the student page shows
 *              this in place of the full breakdown
 *   effort  -- what it costs, because "4 items" means nothing without "3 min"
 *   cta     -- somewhere to click, or null when the honest answer is that
 *              nothing is available yet
 *   mastery -- the tracing estimate the advice was derived from, so the claim
 *              in the prose can be checked against the model behind it
 *
 * An action is only ever emitted when the thing it asks for actually exists --
 * a drill is never suggested for an area with no published questions behind
 * it, which is the difference between advice and a dead end.
 */
class StudyActionPlanner
{
    /** Items in a suggested drill, when the bank can cover it. */
    private const DRILL_SIZE = 10;

    /** Below this there is not enough published material to call it a drill. */
    private const MIN_DRILL_SIZE = 3;

    /** Rough pace for the effort estimate. Deliberately round, not precise. */
    private const SECONDS_PER_ITEM = 45;

    private const PASSING_SCORE = 75.0;

    private const MAX_ACTIONS = 4;

    public function __construct(
        private readonly QuestionMappingService $questionMapping,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $studyPlan  weakest-first, from buildStudyPlan()
     * @param  array<int, array<string, mixed>>  $subjectPerformance  from buildSubjectWeaknessPerformance()
     * @return list<array<string, mixed>>
     */
    public function build(array $studyPlan, array $subjectPerformance): array
    {
        $published = $this->publishedCounts();

        $actions = array_filter([
            $this->reverseASlide($studyPlan, $published),
            $this->drillWeakestArea($studyPlan, $published),
            $this->closeTheGapToPassing($subjectPerformance, $published),
            $this->fillABlindSpot($subjectPerformance, $published),
            $this->flagMissingPractice($studyPlan),
        ]);

        // Never repeat the same area twice; the second mention is always the
        // weaker suggestion of the two.
        $seen = [];
        $unique = [];

        foreach ($actions as $action) {
            $fingerprint = $action['area_key'] ?? spl_object_hash((object) $action);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $unique[] = $action;
        }

        return array_slice($unique, 0, self::MAX_ACTIONS);
    }

    /**
     * Ground already held and now being lost. Ranked first because it is the
     * only reading here that is time-sensitive -- the student knew this once,
     * and the cheapest moment to fix it is before it slides further.
     *
     * @param  array<int, array<string, mixed>>  $studyPlan
     * @param  array<string, int>  $published
     * @return array<string, mixed>|null
     */
    private function reverseASlide(array $studyPlan, array $published): ?array
    {
        foreach ($studyPlan as $row) {
            if (($row['mastery']['trend'] ?? null) !== 'slipping') {
                continue;
            }

            $size = $this->drillSizeFor($row, $published);

            if ($size === null) {
                continue;
            }

            return [
                'key' => 'reverse_slide',
                'tone' => 'urgent',
                'area_key' => $this->areaKey($row),
                'title' => (string) $row['label'],
                'subject' => (string) ($row['subject'] ?? ''),
                'summary' => 'You used to answer these correctly, but your recent answers were wrong.',
                'advice' => $this->advice(
                    "You are losing ground on {$row['label']}.",
                    [
                        $this->block('What happened', [
                            "You were answering questions on it correctly earlier on, in {$row['subject']}.",
                            'Your more recent answers have gone the other way.',
                            sprintf(
                                'Your traced grasp has fallen to about %s%%, from %d answered %s.',
                                $this->percent($row['mastery']['mastery_percent'] ?? null),
                                (int) ($row['attempted_count'] ?? 0),
                                ((int) ($row['attempted_count'] ?? 0)) === 1 ? 'item' : 'items',
                            ),
                        ]),
                        $this->block('Why start here', [
                            'This is ground you already had once, so it costs less to win back than learning a weak topic from scratch.',
                        ]),
                        $this->block('Do this', [
                            sprintf('Run a %d-item drill on it before you move on to anything else.', $size),
                        ]),
                    ],
                ),
                'effort' => $this->effortFor($size),
                'cta_label' => "Start {$size}-item drill",
                'cta_route' => 'dashboard-review-bot',
                'cta_query' => $this->reviewQuery($row),
                'mastery' => $row['mastery'] ?? null,
            ];
        }

        return null;
    }

    /**
     * The main case: the area least likely to be known, where practice exists.
     *
     * @param  array<int, array<string, mixed>>  $studyPlan
     * @param  array<string, int>  $published
     * @return array<string, mixed>|null
     */
    private function drillWeakestArea(array $studyPlan, array $published): ?array
    {
        foreach ($studyPlan as $row) {
            $size = $this->drillSizeFor($row, $published);

            if ($size === null) {
                continue;
            }

            $attempted = (int) ($row['attempted_count'] ?? 0);
            $correct = (int) ($row['correct_count'] ?? 0);

            return [
                'key' => 'drill_weak_area',
                'tone' => 'primary',
                'area_key' => $this->areaKey($row),
                'title' => (string) $row['label'],
                'subject' => (string) ($row['subject'] ?? ''),
                'summary' => sprintf(
                    'You got %d of %d %s right. This is your lowest-scoring topic.',
                    $correct,
                    $attempted,
                    $attempted === 1 ? 'question' : 'questions',
                ),
                'advice' => $this->advice(
                    "{$row['label']} is the weakest area in your results.",
                    [
                        $this->block('What happened', [
                            sprintf(
                                'You have answered %d %s on it, in %s.',
                                $attempted,
                                $attempted === 1 ? 'question' : 'questions',
                                $row['subject'],
                            ),
                            sprintf(
                                '%s come back correct.',
                                $correct === 0 ? 'None have' : ($correct === 1 ? 'Only one has' : "Only {$correct} have"),
                            ),
                            sprintf(
                                'That puts your traced grasp of it at about %s%%.',
                                $this->percent($row['mastery']['mastery_percent'] ?? null),
                            ),
                        ]),
                        $this->block('Why start here', [
                            'Nothing else in your results is weaker, so a session here shifts your overall standing more than one anywhere else would.',
                        ]),
                        $this->block('Do this', [
                            sprintf('Run a %d-item drill on it, then come back and see whether this page still ranks it first.', $size),
                        ]),
                    ],
                ),
                'effort' => $this->effortFor($size),
                'cta_label' => "Start {$size}-item drill",
                'cta_route' => 'dashboard-review-bot',
                'cta_query' => $this->reviewQuery($row),
                'mastery' => $row['mastery'] ?? null,
            ];
        }

        return null;
    }

    /**
     * The quickest win available: whichever subject sits closest underneath the
     * passing line. Worth naming separately from the weakest area, because the
     * cheapest point to gain is rarely in the place you are worst.
     *
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<string, int>  $published
     * @return array<string, mixed>|null
     */
    private function closeTheGapToPassing(array $subjectPerformance, array $published): ?array
    {
        $closest = collect($subjectPerformance)
            ->filter(fn (array $row): bool => ($row['score'] ?? null) !== null
                && (float) $row['score'] < self::PASSING_SCORE
                && (int) ($row['attempted_count'] ?? 0) >= 3)
            ->sortByDesc('score')
            ->first();

        if (!is_array($closest)) {
            return null;
        }

        $subject = (string) $closest['label'];
        $available = $published["subject:{$subject}"] ?? 0;

        if ($available < self::MIN_DRILL_SIZE) {
            return null;
        }

        $gap = round(self::PASSING_SCORE - (float) $closest['score'], 1);
        $size = min(self::DRILL_SIZE, $available);

        return [
            'key' => 'close_the_gap',
            'tone' => 'opportunity',
            'area_key' => "subject:{$subject}",
            'title' => $subject,
            'subject' => '',
            'summary' => sprintf(
                'You average %s%% here, %s points below the 75%% passing mark. It is the subject you are closest to passing.',
                $this->percent($closest['score']),
                $gap,
            ),
            'advice' => $this->advice(
                "{$subject} is the closest you are to passing a subject.",
                [
                    $this->block('Where you stand', [
                        sprintf('You are averaging %s%% across the questions you have answered in it.', $this->percent($closest['score'])),
                        sprintf('That leaves you %s points short of the 75%% pass mark.', $gap),
                    ]),
                    $this->block('Why start here', [
                        'Of the subjects you are currently failing, this is the one nearest to turning around, so points here are cheaper than points anywhere else.',
                    ]),
                    $this->block('Do this', [
                        sprintf('Run a %d-item session on %s.', $size, $subject),
                    ]),
                ],
            ),
            'effort' => $this->effortFor($size),
            'cta_label' => "Practise {$subject}",
            'cta_route' => 'dashboard-review-bot',
            'cta_query' => ['subject' => $subject],
            'mastery' => null,
        ];
    }

    /**
     * A core subject with no answered items at all. It is absent from every
     * other reading on the page precisely because there is no data, which
     * makes it the one weakness the analytics cannot see.
     *
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<string, int>  $published
     * @return array<string, mixed>|null
     */
    private function fillABlindSpot(array $subjectPerformance, array $published): ?array
    {
        $answered = collect($subjectPerformance)
            ->pluck('label')
            ->map(fn ($label): string => (string) $label)
            ->all();

        foreach (array_keys($this->questionMapping->curatedTopics()) as $subject) {
            if (in_array($subject, $answered, true)) {
                continue;
            }

            $available = $published["subject:{$subject}"] ?? 0;
            $hasPractice = $available >= self::MIN_DRILL_SIZE;

            return [
                'key' => 'fill_blind_spot',
                'tone' => 'info',
                'area_key' => "subject:{$subject}",
                'title' => (string) $subject,
                'subject' => '',
                'summary' => $hasPractice
                    ? 'You have not answered any questions in this subject yet, so there is no score for it.'
                    : 'You have not answered any questions in this subject yet, and no practice is published for it. Ask your teacher when it will be covered.',
                'advice' => $this->advice(
                    "You have no results at all in {$subject}.",
                    [
                        $this->block('What happened', [
                            'You have not answered a single question in this subject.',
                            'It is missing from every reading on this page, including your readiness estimate.',
                        ]),
                        $this->block('Why it matters', [
                            'A subject with no data cannot be flagged as weak, so this is the one gap your analytics cannot see.',
                        ]),
                        $this->block('Do this', $hasPractice
                            ? [sprintf(
                                'Sit a %d-item set to get a baseline. The rest of this page starts accounting for the subject straight after.',
                                min(self::DRILL_SIZE, $available),
                            )]
                            : ['There is no published practice for it yet, so ask your teacher when the subject will be covered.']),
                    ],
                ),
                'effort' => $hasPractice ? $this->effortFor(min(self::DRILL_SIZE, $available)) : null,
                'cta_label' => $hasPractice ? "Practise {$subject}" : null,
                'cta_route' => $hasPractice ? 'dashboard-review-bot' : null,
                'cta_query' => $hasPractice ? ['subject' => $subject] : null,
                'mastery' => null,
            ];
        }

        return null;
    }

    /**
     * The honest dead end: the student's weakest area has nothing published
     * behind it. Saying so is better than silently recommending the
     * second-weakest area as though it were the real answer.
     *
     * @param  array<int, array<string, mixed>>  $studyPlan
     * @return array<string, mixed>|null
     */
    private function flagMissingPractice(array $studyPlan): ?array
    {
        $weakest = $studyPlan[0] ?? null;

        if (!is_array($weakest) || ($weakest['review_ready'] ?? false)) {
            return null;
        }

        return [
            'key' => 'practice_not_published',
            'tone' => 'info',
            'area_key' => $this->areaKey($weakest),
            'title' => (string) $weakest['label'],
            'subject' => (string) ($weakest['subject'] ?? ''),
            'summary' => 'This is your weakest topic, but there are no practice questions for it yet. Go over your marked exam and tell your teacher.',
            'advice' => $this->advice(
                "Your weakest area has no practice set yet.",
                [
                    $this->block('What happened', [
                        sprintf(
                            '%s in %s is the weakest area in your results.',
                            $weakest['label'],
                            $weakest['subject'] ?? 'your results',
                        ),
                        'Your teacher has not published any review questions for it.',
                    ]),
                    $this->block('Why it matters', [
                        'There is no drill to send you to, so this is the one weak spot you cannot fix inside the app right now.',
                    ]),
                    $this->block('Do this', [
                        'Go back over the questions you got wrong on your marked exam paper for this area.',
                        'Mention to your teacher that it has no practice set yet.',
                    ]),
                ],
            ),
            'effort' => null,
            'cta_label' => null,
            'cta_route' => null,
            'cta_query' => null,
            'mastery' => null,
        ];
    }

    /**
     * How many items a drill on this area can honestly offer, or null when
     * there is not enough published material to suggest one at all.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $published
     */
    private function drillSizeFor(array $row, array $published): ?int
    {
        if (!($row['review_ready'] ?? false)) {
            return null;
        }

        $subtopicId = $row['subtopic_id'] ?? null;
        $topicId = $row['topic_id'] ?? null;

        $available = $subtopicId !== null
            ? ($published["subtopic:{$subtopicId}"] ?? 0)
            : 0;

        if ($available < self::MIN_DRILL_SIZE && $topicId !== null) {
            $available = $published["topic:{$topicId}"] ?? 0;
        }

        return $available >= self::MIN_DRILL_SIZE
            ? min(self::DRILL_SIZE, $available)
            : null;
    }

    /**
     * One suggestion, broken into the parts a person explaining it would use:
     * a one-line summary, then what happened, why it is worth doing, and the
     * move itself. Broken up rather than run together because a student
     * scanning this page should be able to take the headline alone, or read
     * down into the reasoning if they want to know why it is being suggested.
     *
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

    private function effortFor(int $items): string
    {
        $minutes = (int) max(1, round(($items * self::SECONDS_PER_ITEM) / 60));

        return "about {$minutes} min";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function reviewQuery(array $row): array
    {
        return array_filter([
            'subject' => (string) ($row['subject'] ?? ''),
            'topic_id' => isset($row['topic_id']) && $row['topic_id'] !== null ? (string) $row['topic_id'] : null,
            'subtopic_id' => isset($row['subtopic_id']) && $row['subtopic_id'] !== null ? (string) $row['subtopic_id'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function areaKey(array $row): string
    {
        return ($row['subtopic_id'] ?? null) !== null
            ? "subtopic:{$row['subtopic_id']}"
            : "topic:" . ($row['topic_id'] ?? '0');
    }

    private function percent(mixed $value): string
    {
        return $value === null ? '—' : (string) round((float) $value);
    }

    /**
     * Published Review Bot stock, counted at every level an action might ask
     * for it, so a drill is only ever offered when the questions exist.
     *
     * @return array<string, int>
     */
    private function publishedCounts(): array
    {
        $rows = DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->selectRaw('subject, topic_id, subtopic_id, COUNT(*) as total')
            ->groupBy('subject', 'topic_id', 'subtopic_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $total = (int) $row->total;

            if (!empty($row->subject)) {
                $key = "subject:{$row->subject}";
                $counts[$key] = ($counts[$key] ?? 0) + $total;
            }

            if ($row->topic_id !== null) {
                $key = "topic:{$row->topic_id}";
                $counts[$key] = ($counts[$key] ?? 0) + $total;
            }

            if ($row->subtopic_id !== null) {
                $key = "subtopic:{$row->subtopic_id}";
                $counts[$key] = ($counts[$key] ?? 0) + $total;
            }
        }

        return $counts;
    }
}
