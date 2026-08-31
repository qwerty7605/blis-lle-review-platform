<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExamResultFeedbackService
{
    private const PASSING_SCORE = 75.0;
    private const TOPIC_MIN_ITEMS = 3;

    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildForAttempt(ExamAttempt $attempt): ?array
    {
        if ($attempt->status !== ExamAttempt::STATUS_SUBMITTED) {
            return null;
        }

        $attempt->loadMissing([
            'exam:id,subject',
            'student:id',
        ]);

        $fallbackSubject = trim((string) ($attempt->exam?->subject ?? ''));
        $subjectPerformance = $this->buildSubjectPerformance($attempt, $fallbackSubject);
        $topicPerformance = $this->buildTopicPerformance($attempt, $fallbackSubject);
        $primaryFocus = $this->selectPrimaryFocus($subjectPerformance, $topicPerformance);
        $topicWeakness = $primaryFocus && ($primaryFocus['basis'] ?? null) === 'topic'
            ? $primaryFocus
            : null;
        $secondaryFocuses = $this->buildSecondaryFocuses($primaryFocus, $subjectPerformance, $topicPerformance);
        $strength = $this->selectStrength($primaryFocus, $subjectPerformance, $topicPerformance);
        $historicalReference = $attempt->student instanceof User
            ? $this->buildHistoricalReference($attempt->student, $primaryFocus)
            : null;
        $subjectBreakdown = $this->sortWeakAreas($subjectPerformance);
        $repeatedWeakKeys = $attempt->student instanceof User
            ? $this->repeatedWeakKeys($attempt->student, $topicPerformance)
            : [];
        $topicBreakdown = $this->buildTopicBreakdown($subjectPerformance, $topicPerformance, $repeatedWeakKeys);

        return [
            'summary' => $this->buildSummary($attempt, $primaryFocus),
            'pass_status' => $this->buildPassStatus($attempt),
            'subject_breakdown' => $subjectBreakdown,
            'topic_breakdown' => $topicBreakdown,
            'topic_weakness' => $topicWeakness,
            'readiness_level' => $this->buildReadinessLevel($attempt),
            'primary_focus' => $primaryFocus,
            'secondary_focuses' => $secondaryFocuses,
            'strength' => $strength,
            'historical_reference' => $historicalReference,
            'recommended_actions' => $this->buildRecommendedActions(
                $attempt,
                $primaryFocus,
                $strength,
                $historicalReference,
            ),
        ];
    }

    /**
     * @return array{is_passing: bool, passing_score: float, delta: float}
     */
    protected function buildPassStatus(ExamAttempt $attempt): array
    {
        $score = round((float) ($attempt->score_percent ?? 0), 2);

        return [
            'is_passing' => $score >= self::PASSING_SCORE,
            'passing_score' => self::PASSING_SCORE,
            'delta' => round($score - self::PASSING_SCORE, 2),
        ];
    }

    /**
     * A topic/subtopic counts as "repeated incorrect" when it's also weak
     * across the student's broader history (DashboardAnalyticsService
     * already aggregates every submitted attempt), not just in this one
     * attempt — reused rather than tracked separately. Checked at
     * subtopic granularity when a row has one, falling back to topic
     * granularity otherwise.
     *
     * @param  array<int, array<string, mixed>>  $topicPerformance
     * @return array<int, string> areaKey() values (see areaKey())
     */
    protected function repeatedWeakKeys(User $student, array $topicPerformance): array
    {
        if ($topicPerformance === []) {
            return [];
        }

        $weakThreshold = 60.0;

        $weakTopicIds = collect($this->dashboardAnalyticsService->buildTopicWeaknessPerformance($student))
            ->filter(fn (array $topic): bool => (float) ($topic['score'] ?? 100.0) < $weakThreshold
                && (int) ($topic['attempted_count'] ?? 0) >= self::TOPIC_MIN_ITEMS)
            ->pluck('topic_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        $weakSubtopicIds = collect($this->dashboardAnalyticsService->buildSubtopicWeaknessPerformance($student))
            ->filter(fn (array $subtopic): bool => (float) ($subtopic['score'] ?? 100.0) < $weakThreshold
                && (int) ($subtopic['attempted_count'] ?? 0) >= self::TOPIC_MIN_ITEMS)
            ->pluck('subtopic_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        return collect($topicPerformance)
            ->filter(function (array $row) use ($weakTopicIds, $weakSubtopicIds): bool {
                $subtopicId = (int) ($row['subtopic_id'] ?? 0);

                if ($subtopicId > 0) {
                    return $weakSubtopicIds->contains($subtopicId);
                }

                return $weakTopicIds->contains((int) ($row['topic_id'] ?? 0));
            })
            ->map(fn (array $row): string => $this->areaKey($row))
            ->values()
            ->all();
    }

    /**
     * Composite identity for a topic/subtopic row -- two rows under the
     * same topic but different subtopics must never collapse into one.
     *
     * @param  array<string, mixed>  $row
     */
    protected function areaKey(array $row): string
    {
        return sprintf('%d:%d', (int) ($row['topic_id'] ?? 0), (int) ($row['subtopic_id'] ?? 0));
    }

    /**
     * Every topic touched by this attempt, grouped by subject, with a
     * Strong/Average/Weak/Needs Review level label — the spec's worked
     * example needs every topic listed, not just the single weakest pick
     * that primary_focus/secondary_focuses surface.
     *
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<int, array<string, mixed>>  $topicPerformance
     * @param  array<int, string>  $repeatedWeakKeys
     * @return array<int, array{subject: string, topics: array<int, array<string, mixed>>}>
     */
    protected function buildTopicBreakdown(array $subjectPerformance, array $topicPerformance, array $repeatedWeakKeys): array
    {
        $eligibleTopics = collect($topicPerformance)
            ->filter(fn (array $topic): bool => (int) ($topic['total_items'] ?? 0) >= self::TOPIC_MIN_ITEMS);

        if ($eligibleTopics->isEmpty()) {
            return [];
        }

        $subjectOrder = collect($subjectPerformance)
            ->pluck('subject')
            ->unique()
            ->values()
            ->all();

        return $eligibleTopics
            ->groupBy('subject')
            ->sortBy(fn ($topics, $subject) => array_search($subject, $subjectOrder, true) ?: PHP_INT_MAX)
            ->map(function (Collection $topics, string $subject) use ($repeatedWeakKeys): array {
                $sortedTopics = collect($this->sortWeakAreas($topics->all()))
                    ->map(function (array $topic) use ($repeatedWeakKeys): array {
                        return array_merge($topic, [
                            'level' => $this->topicLevel((float) ($topic['score_percent'] ?? 0.0)),
                            'repeated_weak' => in_array($this->areaKey($topic), $repeatedWeakKeys, true),
                        ]);
                    })
                    ->values()
                    ->all();

                return [
                    'subject' => $subject,
                    'topics' => $sortedTopics,
                ];
            })
            ->values()
            ->all();
    }

    protected function topicLevel(float $scorePercent): string
    {
        if ($scorePercent >= 80.0) {
            return 'strong';
        }

        if ($scorePercent >= 60.0) {
            return 'average';
        }

        if ($scorePercent >= 40.0) {
            return 'weak';
        }

        return 'needs_review';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildSubjectPerformance(ExamAttempt $attempt, string $fallbackSubject): array
    {
        $rows = DB::table('exam_attempt_questions')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_questions.question_bank_question_id')
            ->leftJoin('exam_attempt_answers', function ($join) use ($attempt) {
                $join->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id')
                    ->where('exam_attempt_answers.exam_attempt_id', '=', $attempt->id);
            })
            ->where('exam_attempt_questions.exam_attempt_id', $attempt->id)
            ->selectRaw("
                question_bank_questions.subject as subject,
                COUNT(*) as total_items,
                SUM(CASE WHEN exam_attempt_answers.id IS NOT NULL THEN 1 ELSE 0 END) as answered_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
            ")
            ->groupBy('question_bank_questions.subject')
            ->get();

        return collect($rows)
            ->map(function ($row) use ($fallbackSubject): array {
                return $this->formatAreaStats(
                    'subject',
                    trim((string) ($row->subject ?? '')) ?: $fallbackSubject,
                    null,
                    null,
                    (int) ($row->total_items ?? 0),
                    (int) ($row->answered_count ?? 0),
                    (int) ($row->correct_count ?? 0),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildTopicPerformance(ExamAttempt $attempt, string $fallbackSubject): array
    {
        $rows = DB::table('exam_attempt_questions')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_questions.question_bank_question_id')
            ->join('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->leftJoin('subtopics', 'subtopics.id', '=', 'question_bank_questions.subtopic_id')
            ->leftJoin('exam_attempt_answers', function ($join) use ($attempt) {
                $join->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id')
                    ->where('exam_attempt_answers.exam_attempt_id', '=', $attempt->id);
            })
            ->where('exam_attempt_questions.exam_attempt_id', $attempt->id)
            ->selectRaw("
                topics.id as topic_id,
                topics.name as topic,
                subtopics.id as subtopic_id,
                subtopics.name as subtopic,
                COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject) as subject,
                COUNT(*) as total_items,
                SUM(CASE WHEN exam_attempt_answers.id IS NOT NULL THEN 1 ELSE 0 END) as answered_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
            ")
            ->groupBy('topics.id', 'topics.name', 'subtopics.id', 'subtopics.name', 'topics.subject', 'question_bank_questions.subject')
            ->get();

        return collect($rows)
            ->map(function ($row) use ($fallbackSubject): array {
                return $this->formatAreaStats(
                    'topic',
                    trim((string) ($row->subject ?? '')) ?: $fallbackSubject,
                    (int) ($row->topic_id ?? 0),
                    (string) ($row->topic ?? ''),
                    (int) ($row->total_items ?? 0),
                    (int) ($row->answered_count ?? 0),
                    (int) ($row->correct_count ?? 0),
                    $row->subtopic_id !== null ? (int) $row->subtopic_id : null,
                    $row->subtopic !== null ? (string) $row->subtopic : null,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<int, array<string, mixed>>  $topicPerformance
     * @return array<string, mixed>|null
     */
    protected function selectPrimaryFocus(array $subjectPerformance, array $topicPerformance): ?array
    {
        $eligibleTopics = array_values(array_filter(
            $topicPerformance,
            fn (array $topic): bool => (int) ($topic['total_items'] ?? 0) >= self::TOPIC_MIN_ITEMS
        ));

        if ($eligibleTopics !== []) {
            return $this->sortWeakAreas($eligibleTopics)[0] ?? null;
        }

        return $this->sortWeakAreas($subjectPerformance)[0] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<int, array<string, mixed>>  $topicPerformance
     * @return array<int, array<string, mixed>>
     */
    protected function buildSecondaryFocuses(?array $primaryFocus, array $subjectPerformance, array $topicPerformance): array
    {
        $candidates = $primaryFocus && ($primaryFocus['basis'] ?? null) === 'topic'
            ? array_values(array_filter(
                $topicPerformance,
                fn (array $topic): bool => (int) ($topic['total_items'] ?? 0) >= self::TOPIC_MIN_ITEMS
            ))
            : $subjectPerformance;

        if ($candidates === []) {
            return [];
        }

        return collect($this->sortWeakAreas($candidates))
            ->reject(fn (array $area): bool => $this->isSameFocus($area, $primaryFocus))
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     * @param  array<int, array<string, mixed>>  $topicPerformance
     * @return array<string, mixed>|null
     */
    protected function selectStrength(?array $primaryFocus, array $subjectPerformance, array $topicPerformance): ?array
    {
        if ($primaryFocus && ($primaryFocus['basis'] ?? null) === 'topic') {
            $topicCandidates = collect($this->sortStrongAreas(array_values(array_filter(
                $topicPerformance,
                fn (array $topic): bool => (int) ($topic['total_items'] ?? 0) >= self::TOPIC_MIN_ITEMS
            ))))
                ->reject(fn (array $area): bool => $this->isSameFocus($area, $primaryFocus))
                ->values();

            if ($topicCandidates->isNotEmpty()) {
                return $topicCandidates->first();
            }

            $subjectCandidates = collect($this->sortStrongAreas($subjectPerformance))
                ->reject(fn (array $area): bool => (string) ($area['label'] ?? '') === (string) ($primaryFocus['subject'] ?? ''))
                ->values();

            return $subjectCandidates->first();
        }

        return collect($this->sortStrongAreas($subjectPerformance))
            ->reject(fn (array $area): bool => $this->isSameFocus($area, $primaryFocus))
            ->values()
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @return array<string, mixed>|null
     */
    protected function buildHistoricalReference(User $student, ?array $primaryFocus): ?array
    {
        $weakArea = $this->dashboardAnalyticsService->buildWeakAreaRecommendation($student);

        if ($weakArea === []) {
            return null;
        }

        $basis = (string) ($weakArea['basis'] ?? 'subject');
        $subject = trim((string) ($weakArea['subject'] ?? ''));
        $topic = trim((string) ($weakArea['topic'] ?? ''));
        $topicId = isset($weakArea['topic_id']) ? (int) $weakArea['topic_id'] : null;
        $subtopic = trim((string) ($weakArea['subtopic'] ?? ''));
        $subtopicId = isset($weakArea['subtopic_id']) ? (int) $weakArea['subtopic_id'] : null;

        $labelParts = array_filter([$subject, $topic, $subtopic], fn (string $part): bool => $part !== '');
        $label = $labelParts !== [] ? implode(' • ', $labelParts) : 'Uncategorized';

        return [
            'basis' => $basis,
            'subject' => $subject,
            'topic_id' => $topicId,
            'topic' => $topic !== '' ? $topic : null,
            'subtopic_id' => $subtopicId,
            'subtopic' => $subtopic !== '' ? $subtopic : null,
            'label' => $label,
            'matches_primary_focus' => $this->matchesWeakArea($primaryFocus, $weakArea),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReadinessLevel(ExamAttempt $attempt): array
    {
        $score = round((float) ($attempt->score_percent ?? 0), 2);

        if ($score >= 90) {
            return [
                'level' => 'exam_ready',
                'label' => 'Exam Ready',
                'message' => 'Your result is in a strong range for formal exam readiness.',
            ];
        }

        if ($score >= self::PASSING_SCORE) {
            return [
                'level' => 'near_ready',
                'label' => 'Near Ready',
                'message' => 'You are passing, but a few weak areas still need reinforcement.',
            ];
        }

        if ($score >= 60) {
            return [
                'level' => 'developing',
                'label' => 'Developing',
                'message' => 'You are building readiness, but your weaker areas need more targeted review.',
            ];
        }

        return [
            'level' => 'needs_reinforcement',
            'label' => 'Needs Reinforcement',
            'message' => 'Focus on the weakest concepts first before your next official assessment.',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @return array<string, mixed>
     */
    protected function buildSummary(ExamAttempt $attempt, ?array $primaryFocus): array
    {
        $score = round((float) ($attempt->score_percent ?? 0), 2);
        $focusLabel = (string) ($primaryFocus['label'] ?? 'this exam');

        if ($score >= 90) {
            return [
                'tone' => 'excellent',
                'label' => 'Outstanding result',
                'headline' => 'Strong finish.',
                'message' => $primaryFocus
                    ? sprintf('You performed well overall. The weakest slice of this attempt was %s, so keep that area polished.', $focusLabel)
                    : 'You performed well overall. Keep reviewing to maintain this level.',
            ];
        }

        if ($score >= self::PASSING_SCORE) {
            return [
                'tone' => 'passing',
                'label' => 'Passing result',
                'headline' => 'You passed this attempt.',
                'message' => $primaryFocus
                    ? sprintf('You passed, but %s pulled your score down the most.', $focusLabel)
                    : 'You passed, but there are still a few concepts worth tightening.',
            ];
        }

        if ($score >= 60) {
            return [
                'tone' => 'review',
                'label' => 'Close, but needs review',
                'headline' => 'You are within reach.',
                'message' => $primaryFocus
                    ? sprintf('You are close, but %s needs targeted review before the next attempt.', $focusLabel)
                    : 'You answered a fair share correctly, but several concepts still need review.',
            ];
        }

        return [
            'tone' => 'needs-work',
            'label' => 'Needs more review',
            'headline' => 'Build this area back up first.',
            'message' => $primaryFocus
                ? sprintf('%s needs immediate attention before your next attempt.', $focusLabel)
                : 'Focus on the concepts you missed and use the result breakdown to guide your next review.',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @param  array<string, mixed>|null  $strength
     * @param  array<string, mixed>|null  $historicalReference
     * @return array<int, string>
     */
    protected function buildRecommendedActions(
        ExamAttempt $attempt,
        ?array $primaryFocus,
        ?array $strength,
        ?array $historicalReference,
    ): array {
        $actions = [];

        if ($primaryFocus) {
            $actions[] = sprintf(
                'Prioritize %s first. You got %d of %d item(s) correct in this area.',
                (string) ($primaryFocus['label'] ?? 'this area'),
                (int) ($primaryFocus['correct_count'] ?? 0),
                (int) ($primaryFocus['total_items'] ?? 0),
            );
        }

        $missedCount = max(0, (int) ($attempt->total_items ?? 0) - (int) ($attempt->answered_count ?? 0));
        if ($missedCount >= max(2, (int) ceil(max(1, (int) ($attempt->total_items ?? 0)) * 0.2))) {
            $actions[] = sprintf(
                'Improve pacing on the next attempt. %d item(s) were left unanswered.',
                $missedCount
            );
        } elseif ($primaryFocus && (int) ($primaryFocus['incorrect_count'] ?? 0) > 0) {
            $actions[] = ($primaryFocus['basis'] ?? null) === 'topic' && !empty($primaryFocus['leaf_label'])
                ? sprintf(
                    'Revisit the core ideas behind %s and compare why your selected answers missed the key concept.',
                    (string) $primaryFocus['leaf_label']
                )
                : sprintf(
                    'Revisit the core ideas in %s and compare why your selected answers missed the key concept.',
                    (string) ($primaryFocus['subject'] ?? 'this subject')
                );
        }

        if ($primaryFocus) {
            $actions[] = sprintf(
                'Review your notes, handouts, or reference materials for %s before the next exam.',
                (string) ($primaryFocus['subject'] ?? 'this subject')
            );
        }

        if ((bool) ($historicalReference['matches_primary_focus'] ?? false)) {
            $actions[] = 'This same area also appears in your recent performance trend, so keep it at the top of your review list.';
        } elseif ($strength) {
            $actions[] = sprintf(
                'Keep using %s as a stable area while you reinforce weaker concepts.',
                (string) ($strength['label'] ?? 'your stronger areas')
            );
        }

        return collect($actions)
            ->filter(fn ($action): bool => is_string($action) && trim($action) !== '')
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAreaStats(
        string $basis,
        string $subject,
        ?int $topicId,
        ?string $topic,
        int $totalItems,
        int $answeredCount,
        int $correctCount,
        ?int $subtopicId = null,
        ?string $subtopic = null,
    ): array {
        $normalizedSubject = trim($subject) !== '' ? trim($subject) : 'Uncategorized';
        $normalizedTopic = trim((string) ($topic ?? ''));
        $normalizedSubtopic = trim((string) ($subtopic ?? ''));
        $incorrectCount = max($answeredCount - $correctCount, 0);
        $missedCount = max($totalItems - $answeredCount, 0);
        // Topic then subtopic -- never the subtopic alone, the topic stays
        // visible as the parent of the subtopic it's under.
        $leafLabel = $normalizedSubtopic !== ''
            ? ($normalizedTopic !== '' ? $normalizedTopic . ' → ' . $normalizedSubtopic : $normalizedSubtopic)
            : $normalizedTopic;

        return [
            'basis' => $basis,
            'label' => $basis === 'topic' && $leafLabel !== ''
                ? $normalizedSubject . ' • ' . $leafLabel
                : $normalizedSubject,
            'subject' => $normalizedSubject,
            'topic_id' => $basis === 'topic' && $topicId ? $topicId : null,
            'topic' => $basis === 'topic' && $normalizedTopic !== '' ? $normalizedTopic : null,
            'subtopic_id' => $basis === 'topic' && $subtopicId ? $subtopicId : null,
            'subtopic' => $basis === 'topic' && $normalizedSubtopic !== '' ? $normalizedSubtopic : null,
            'leaf_label' => $basis === 'topic' ? $leafLabel : null,
            'total_items' => $totalItems,
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'incorrect_count' => $incorrectCount,
            'missed_count' => $missedCount,
            'score_percent' => $totalItems > 0
                ? round(($correctCount / $totalItems) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $areas
     * @return array<int, array<string, mixed>>
     */
    protected function sortWeakAreas(array $areas): array
    {
        usort($areas, function (array $left, array $right): int {
            $scoreComparison = ((float) ($left['score_percent'] ?? 0.0)) <=> ((float) ($right['score_percent'] ?? 0.0));
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $missedComparison = ((int) ($right['missed_count'] ?? 0)) <=> ((int) ($left['missed_count'] ?? 0));
            if ($missedComparison !== 0) {
                return $missedComparison;
            }

            $incorrectComparison = ((int) ($right['incorrect_count'] ?? 0)) <=> ((int) ($left['incorrect_count'] ?? 0));
            if ($incorrectComparison !== 0) {
                return $incorrectComparison;
            }

            $totalComparison = ((int) ($right['total_items'] ?? 0)) <=> ((int) ($left['total_items'] ?? 0));
            if ($totalComparison !== 0) {
                return $totalComparison;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return array_values($areas);
    }

    /**
     * @param  array<int, array<string, mixed>>  $areas
     * @return array<int, array<string, mixed>>
     */
    protected function sortStrongAreas(array $areas): array
    {
        usort($areas, function (array $left, array $right): int {
            $scoreComparison = ((float) ($right['score_percent'] ?? 0.0)) <=> ((float) ($left['score_percent'] ?? 0.0));
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $correctComparison = ((int) ($right['correct_count'] ?? 0)) <=> ((int) ($left['correct_count'] ?? 0));
            if ($correctComparison !== 0) {
                return $correctComparison;
            }

            $missedComparison = ((int) ($left['missed_count'] ?? 0)) <=> ((int) ($right['missed_count'] ?? 0));
            if ($missedComparison !== 0) {
                return $missedComparison;
            }

            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return array_values($areas);
    }

    /**
     * @param  array<string, mixed>|null  $left
     * @param  array<string, mixed>|null  $right
     */
    protected function isSameFocus(?array $left, ?array $right): bool
    {
        if (!$left || !$right) {
            return false;
        }

        $leftBasis = (string) ($left['basis'] ?? 'subject');
        $rightBasis = (string) ($right['basis'] ?? 'subject');

        if ($leftBasis !== $rightBasis) {
            return false;
        }

        if ($leftBasis === 'topic') {
            $sameTopic = (int) ($left['topic_id'] ?? 0) > 0
                && (int) ($left['topic_id'] ?? 0) === (int) ($right['topic_id'] ?? 0);

            // Two rows under the same topic but different subtopics are
            // different focuses -- only collapse them when the subtopic
            // (or absence of one) also matches.
            return $sameTopic && (int) ($left['subtopic_id'] ?? 0) === (int) ($right['subtopic_id'] ?? 0);
        }

        return (string) ($left['subject'] ?? '') === (string) ($right['subject'] ?? '');
    }

    /**
     * @param  array<string, mixed>|null  $primaryFocus
     * @param  array<string, mixed>  $weakArea
     */
    protected function matchesWeakArea(?array $primaryFocus, array $weakArea): bool
    {
        if (!$primaryFocus) {
            return false;
        }

        $primaryBasis = (string) ($primaryFocus['basis'] ?? 'subject');
        $weakBasis = (string) ($weakArea['basis'] ?? 'subject');

        // A subtopic-level weak area is still "the same focus" as a
        // topic-level primary focus when they share a topic_id — the
        // result page only breaks down to topic granularity, so match on
        // whichever level both sides actually have in common.
        if ($primaryBasis === 'topic' && in_array($weakBasis, ['topic', 'subtopic'], true)) {
            return (int) ($primaryFocus['topic_id'] ?? 0) > 0
                && (int) ($primaryFocus['topic_id'] ?? 0) === (int) ($weakArea['topic_id'] ?? 0);
        }

        if ($primaryBasis !== $weakBasis) {
            return false;
        }

        return (string) ($primaryFocus['subject'] ?? '') === trim((string) ($weakArea['subject'] ?? ''));
    }
}
