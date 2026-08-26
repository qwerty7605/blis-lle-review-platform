<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\User;
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

        return [
            'summary' => $this->buildSummary($attempt, $primaryFocus),
            'subject_breakdown' => $subjectBreakdown,
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
            ->leftJoin('exam_attempt_answers', function ($join) use ($attempt) {
                $join->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id')
                    ->where('exam_attempt_answers.exam_attempt_id', '=', $attempt->id);
            })
            ->where('exam_attempt_questions.exam_attempt_id', $attempt->id)
            ->selectRaw("
                topics.id as topic_id,
                topics.name as topic,
                COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject) as subject,
                COUNT(*) as total_items,
                SUM(CASE WHEN exam_attempt_answers.id IS NOT NULL THEN 1 ELSE 0 END) as answered_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
            ")
            ->groupBy('topics.id', 'topics.name', 'topics.subject', 'question_bank_questions.subject')
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
        $label = $basis === 'topic' && $topic !== ''
            ? ($subject !== '' ? $subject . ' • ' . $topic : $topic)
            : ($subject !== '' ? $subject : 'Uncategorized');

        return [
            'basis' => $basis,
            'subject' => $subject,
            'topic_id' => $topicId,
            'topic' => $topic !== '' ? $topic : null,
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
            $actions[] = ($primaryFocus['basis'] ?? null) === 'topic' && !empty($primaryFocus['topic'])
                ? sprintf(
                    'Revisit the core ideas behind %s and compare why your selected answers missed the key concept.',
                    (string) $primaryFocus['topic']
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
    ): array {
        $normalizedSubject = trim($subject) !== '' ? trim($subject) : 'Uncategorized';
        $normalizedTopic = trim((string) ($topic ?? ''));
        $incorrectCount = max($answeredCount - $correctCount, 0);
        $missedCount = max($totalItems - $answeredCount, 0);

        return [
            'basis' => $basis,
            'label' => $basis === 'topic' && $normalizedTopic !== ''
                ? $normalizedSubject . ' • ' . $normalizedTopic
                : $normalizedSubject,
            'subject' => $normalizedSubject,
            'topic_id' => $basis === 'topic' && $topicId ? $topicId : null,
            'topic' => $basis === 'topic' && $normalizedTopic !== '' ? $normalizedTopic : null,
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
            return (int) ($left['topic_id'] ?? 0) > 0
                && (int) ($left['topic_id'] ?? 0) === (int) ($right['topic_id'] ?? 0);
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

        $weakBasis = (string) ($weakArea['basis'] ?? 'subject');
        if ((string) ($primaryFocus['basis'] ?? 'subject') !== $weakBasis) {
            return false;
        }

        if ($weakBasis === 'topic') {
            return (int) ($primaryFocus['topic_id'] ?? 0) > 0
                && (int) ($primaryFocus['topic_id'] ?? 0) === (int) ($weakArea['topic_id'] ?? 0);
        }

        return (string) ($primaryFocus['subject'] ?? '') === trim((string) ($weakArea['subject'] ?? ''));
    }
}
