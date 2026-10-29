<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Support\LleTaxonomy;
use Illuminate\Support\Str;

/**
 * A student's full analytics payload: scores by subject, history, the LET
 * readiness estimate, and the weak-area readings.
 *
 * Two endpoints serve it: the student reading their own (/student/analytics)
 * and staff or an admin reading a student's (/students/{student}/analytics).
 * One builder means the teacher sees exactly the numbers the student does.
 */
class StudentAnalyticsService
{
    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
    ) {
    }

    public function build(User $student): array
    {
        // The six board subjects under their official names (old spellings
        // folded in by LleTaxonomy), plus Archival Studies, which some
        // programmes still teach as a separate subject.
        $canonicalSubjects = [...LleTaxonomy::subjectNames(), 'Archival Studies'];

        $normalizeSubject = function (?string $subject): ?string {
            $normalized = LleTaxonomy::normalize($subject);

            if ($normalized === '') {
                return null;
            }

            if (in_array($normalized, ['archival studies', 'archives'], true)) {
                return 'Archival Studies';
            }

            return LleTaxonomy::canonicalSubject($subject) ?? trim((string) $subject);
        };

        $attempts = ExamAttempt::query()
            ->with([
                'exam:id,title,subject,question_bank_id,total_items,duration_minutes',
                'exam.questionBank:id,subject',
                'exam.questionBanks:id,subject',
                'room:id,name,code',
            ])
            ->where('user_id', $student->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ExamAttempt $attempt) use ($normalizeSubject) {
                $exam = $attempt->exam;

                $rawSubject = trim((string) ($exam?->subject ?? ''));
                if ($rawSubject === '' && $exam) {
                    $rawSubject = (string) (Exam::summarizeQuestionBankSubject($exam->resolvedQuestionBanks()) ?? '');
                }

                $resolvedSubject = $normalizeSubject($rawSubject);

                return [
                    'attempt_id' => (int) $attempt->id,
                    'exam_id' => (int) $attempt->exam_id,
                    'title' => $exam?->title ?? 'Untitled Exam',
                    'subject' => $resolvedSubject ?? ($rawSubject !== '' ? $rawSubject : 'Uncategorized'),
                    'score_percent' => is_null($attempt->score_percent)
                        ? null
                        : round((float) $attempt->score_percent, 2),
                    'answered_count' => (int) $attempt->answered_count,
                    'correct_answers' => (int) $attempt->correct_answers,
                    'total_items' => (int) $attempt->total_items,
                    'duration_minutes' => (int) $attempt->duration_minutes,
                    'submitted_at' => $attempt->submitted_at,
                    'room' => [
                        'id' => (int) ($attempt->room?->id ?? 0),
                        'name' => $attempt->room?->name,
                        'code' => $attempt->room?->code,
                    ],
                ];
            })
            ->values();

        /*
         * An exam may be taken twice. Every sitting is kept and listed (Exam
         * History), but one score per exam is what the figures are made from: the
         * latest sitting, which is also what the teacher's reports and the grade
         * export use. Counting both made one exam taken twice read as two exams,
         * and averaged the retake with the attempt it was meant to improve on.
         */
        $allAttempts = $attempts;
        $attempts = $allAttempts->unique('exam_id')->values();

        $sittingsPerExam = $allAttempts->countBy('exam_id');
        $sittingsSeen = [];

        // Newest first, so the first sitting met for an exam is its latest.
        $examHistory = $allAttempts
            ->map(function (array $attempt) use ($sittingsPerExam, &$sittingsSeen): array {
                $examId = $attempt['exam_id'];
                $total = (int) ($sittingsPerExam[$examId] ?? 1);
                $sittingsSeen[$examId] = ($sittingsSeen[$examId] ?? 0) + 1;

                return [
                    ...$attempt,
                    'attempt_number' => $total - $sittingsSeen[$examId] + 1,
                    'attempts_total' => $total,
                    'counts' => $sittingsSeen[$examId] === 1,
                ];
            })
            ->values();

        $scoreValues = $attempts
            ->pluck('score_percent')
            ->filter(fn ($score) => !is_null($score))
            ->values();

        $subjectProfiles = collect($canonicalSubjects)
            ->map(function (string $subjectLabel) use ($attempts) {
                $subjectAttempts = $attempts
                    ->filter(fn (array $attempt) => $attempt['subject'] === $subjectLabel)
                    ->values();

                $scores = $subjectAttempts
                    ->pluck('score_percent')
                    ->filter(fn ($score) => !is_null($score))
                    ->values();

                $latestScore = $scores->first();
                $previousScore = $scores->skip(1)->first();
                $trendDelta = !is_null($latestScore) && !is_null($previousScore)
                    ? round((float) $latestScore - (float) $previousScore, 1)
                    : null;

                if ($scores->isEmpty()) {
                    $performanceBand = 'not_started';
                    $trend = 'none';
                } else {
                    $referenceScore = !is_null($latestScore)
                        ? (float) $latestScore
                        : (float) $scores->avg();

                    if ($referenceScore >= 85) {
                        $performanceBand = 'strong';
                    } elseif ($referenceScore >= 75) {
                        $performanceBand = 'developing';
                    } else {
                        $performanceBand = 'focus';
                    }

                    if (is_null($trendDelta) || abs($trendDelta) < 1) {
                        $trend = 'steady';
                    } elseif ($trendDelta > 0) {
                        $trend = 'up';
                    } else {
                        $trend = 'down';
                    }
                }

                return [
                    'label' => $subjectLabel,
                    'attempts_count' => $subjectAttempts->count(),
                    'average_score_percent' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 1)
                        : null,
                    'latest_score_percent' => !is_null($latestScore)
                        ? round((float) $latestScore, 1)
                        : null,
                    'best_score_percent' => $scores->isNotEmpty()
                        ? round((float) $scores->max(), 1)
                        : null,
                    'trend' => $trend,
                    'trend_delta' => $trendDelta,
                    'performance_band' => $performanceBand,
                ];
            })
            ->values();

        $latestAttempt = $attempts->first();
        $previousAttempt = $attempts->skip(1)->first();
        $latestScorePercent = $latestAttempt['score_percent'] ?? null;
        $latestDelta = !is_null($latestScorePercent) && !is_null($previousAttempt['score_percent'] ?? null)
            ? round((float) $latestScorePercent - (float) $previousAttempt['score_percent'], 1)
            : null;

        $focusSubjectsCount = $subjectProfiles
            ->filter(fn (array $subject) => $subject['performance_band'] === 'focus')
            ->count();

        $history = $attempts
            ->take(8)
            ->reverse()
            ->values()
            ->map(function (array $attempt, int $index) {
                return [
                    ...$attempt,
                    'label' => 'A' . ($index + 1),
                ];
            });

        // Locked until one semester has all six subject exams; then an early
        // warning (years 1 to 3) or a board readiness prediction (year 4), from
        // the latest complete semester. See BoardExamOutlook.
        $predictiveModel = app(\App\Services\MachineLearning\BoardExamOutlook::class)->for($student);

        return [
            'stats' => [
                'completed_attempts' => $attempts->count(),
                'average_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->avg(), 1)
                    : null,
                'latest_score_percent' => !is_null($latestScorePercent)
                    ? round((float) $latestScorePercent, 1)
                    : null,
                'latest_score_delta' => $latestDelta,
                'focus_subjects_count' => $focusSubjectsCount,
                'passing_threshold' => 75,
            ],
            'subjects' => $subjectProfiles,
            'history' => $history,
            'recent_attempts' => $attempts->take(6)->values(),
            // Every sitting, retakes included, newest first.
            'exam_history' => $examHistory->take(300)->values(),
            'predictive_model' => $predictiveModel,
            // Same subject -> topic -> subtopic weak-area resolution "Review Your
            // Weak Areas" already uses (see DashboardAnalyticsService), reused
            // here so "What To Review Next" can name the actual weakest
            // subtopic, not just the subject it lives under.
            'weak_area' => $this->dashboardAnalyticsService->buildWeakAreaRecommendation($student) ?: null,
            // The same resolution widened into a ranked queue (worst first) for
            // the Analytics page's study plan, with a Review Bot link target per
            // row.
            'study_plan' => $this->dashboardAnalyticsService->buildStudyPlan($student),
            // The readings above turned into things to actually do -- sized,
            // justified, and only ever offered when the practice behind them
            // exists. See StudyActionPlanner.
            'action_plan' => $this->dashboardAnalyticsService->buildActionPlan($student),
            // Topic and sub-topic mastery (Bayesian Knowledge Tracing, one
            // update per exam) grouped under each subject's percent correct.
            'mastery' => $this->dashboardAnalyticsService->buildMasteryBySubject($student),
        ];
    }
}
