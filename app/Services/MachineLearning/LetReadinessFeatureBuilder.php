<?php

namespace App\Services\MachineLearning;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the 15 LetPredictionService input features from a student's own
 * submitted exam attempt history, since this system doesn't track official
 * semester GPA — only practice/mock exam results (see ml/README.md).
 *
 * `sem1`..`sem8` are approximated by splitting the student's submitted,
 * scored attempts into 8 equal-sized chronological buckets (oldest first)
 * and averaging each bucket's score. This is a stand-in for "one mock
 * result per semester" — it is only as accurate as the assumption that a
 * student's attempts are spread reasonably evenly across their 8
 * semesters, which is why a minimum attempt count is required before a
 * prediction is considered meaningful (see MIN_ATTEMPTS_FOR_PREDICTION).
 *
 * `mock_catclass`..`mock_selacq` and `mock_total` reuse the same
 * per-subject averages already computed for the student's Analytics page
 * (the six canonical BLIS subjects), so they stay consistent with what
 * the student sees elsewhere.
 */
class LetReadinessFeatureBuilder
{
    private const MIN_ATTEMPTS_FOR_PREDICTION = 8;

    private const SUBJECT_TO_FEATURE = [
        'Cataloging and Classification' => 'mock_catclass',
        'Indexing and Abstracting' => 'mock_indexabs',
        'Information Technology' => 'mock_it',
        'Reference Services' => 'mock_reference',
        'Library Management' => 'mock_libmgmt',
        'Selection and Acquisition' => 'mock_selacq',
    ];

    public const SUBJECT_FEATURE_LABELS = [
        'sem1' => 'Semester 1 Mock Average',
        'sem2' => 'Semester 2 Mock Average',
        'sem3' => 'Semester 3 Mock Average',
        'sem4' => 'Semester 4 Mock Average',
        'sem5' => 'Semester 5 Mock Average',
        'sem6' => 'Semester 6 Mock Average',
        'sem7' => 'Semester 7 Mock Average',
        'sem8' => 'Semester 8 Mock Average',
        'mock_catclass' => 'Cataloging & Classification',
        'mock_indexabs' => 'Indexing & Abstracting',
        'mock_it' => 'Information Technology',
        'mock_reference' => 'Reference Services',
        'mock_libmgmt' => 'Library Management',
        'mock_selacq' => 'Selection & Acquisition',
        'mock_total' => 'Overall Mock Average',
    ];

    /**
     * The same subject spellings the student analytics page folds together
     * (see $buildStudentAnalytics in routes/api/student.php), so a graduate's
     * training record is cut from the numbers their prediction was.
     */
    private const SUBJECT_ALIASES = [
        'cataloging and classification' => 'Cataloging and Classification',
        'cataloguing and classification' => 'Cataloging and Classification',
        'cataloging' => 'Cataloging and Classification',
        'indexing and abstracting' => 'Indexing and Abstracting',
        'indexing' => 'Indexing and Abstracting',
        'information technology' => 'Information Technology',
        'it' => 'Information Technology',
        'reference' => 'Reference Services',
        'reference services' => 'Reference Services',
        'library management' => 'Library Management',
        'selection and acquisition' => 'Selection and Acquisition',
        'selection' => 'Selection and Acquisition',
    ];

    /**
     * The features for one student, read straight from their exam history.
     *
     * $until leaves out anything submitted later. A graduate's record has to
     * stop at the day they sat the board exam: mock exams taken afterwards
     * (by someone preparing to retake it, say) are not what the result was
     * earned on.
     *
     * @return array{ready: bool, reason?: string, features?: array<string, float>}
     */
    public function forStudent(User $student, ?CarbonInterface $until = null): array
    {
        $attempts = ExamAttempt::query()
            ->with(['exam:id,subject,question_bank_id', 'exam.questionBank:id,subject', 'exam.questionBanks:id,subject'])
            ->where('user_id', $student->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->when($until !== null, fn ($query) => $query->where('submitted_at', '<=', $until))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ExamAttempt $attempt): array {
                $exam = $attempt->exam;

                $subject = trim((string) ($exam?->subject ?? ''));
                if ($subject === '' && $exam) {
                    $subject = (string) (Exam::summarizeQuestionBankSubject($exam->resolvedQuestionBanks()) ?? '');
                }

                $normalized = Str::of($subject)->lower()->replace('&', 'and')->replace('/', ' ')->replace('-', ' ')->squish()->toString();

                return [
                    'subject' => self::SUBJECT_ALIASES[$normalized] ?? $subject,
                    'score_percent' => is_null($attempt->score_percent) ? null : round((float) $attempt->score_percent, 2),
                    'submitted_at' => $attempt->submitted_at,
                ];
            })
            ->values();

        $scores = $attempts->pluck('score_percent')->filter(fn ($score) => !is_null($score))->values();

        $subjectProfiles = collect(array_keys(self::SUBJECT_TO_FEATURE))
            ->map(function (string $label) use ($attempts): array {
                $subjectScores = $attempts
                    ->filter(fn (array $attempt) => $attempt['subject'] === $label)
                    ->pluck('score_percent')
                    ->filter(fn ($score) => !is_null($score));

                return [
                    'label' => $label,
                    'average_score_percent' => $subjectScores->isNotEmpty() ? round((float) $subjectScores->avg(), 1) : null,
                ];
            });

        return $this->build($attempts, $subjectProfiles, $scores->isNotEmpty() ? (float) $scores->avg() : null);
    }

    /**
     * @param  Collection  $attempts  Each item: ['score_percent' => float|null, 'submitted_at' => mixed, 'subject' => string]
     * @param  Collection  $subjectProfiles  Each item: ['label' => string, 'average_score_percent' => float|null]
     * @param  float|null  $overallAverageScore  Student's overall average score across all submitted attempts
     * @return array{ready: bool, reason?: string, features?: array<string, float>}
     */
    public function build(Collection $attempts, Collection $subjectProfiles, ?float $overallAverageScore): array
    {
        $scored = $attempts
            ->filter(fn (array $attempt) => !is_null($attempt['score_percent'] ?? null))
            ->sortBy('submitted_at')
            ->values();

        if ($scored->count() < self::MIN_ATTEMPTS_FOR_PREDICTION) {
            return [
                'ready' => false,
                'reason' => sprintf(
                    'Finish at least %d graded exams to estimate 8 semesters of mock results (you have %d so far).',
                    self::MIN_ATTEMPTS_FOR_PREDICTION,
                    $scored->count(),
                ),
            ];
        }

        $bucketCount = self::MIN_ATTEMPTS_FOR_PREDICTION;
        $total = $scored->count();
        $buckets = array_fill(0, $bucketCount, []);

        foreach ($scored->values() as $index => $attempt) {
            $bucketIndex = (int) min(
                $bucketCount - 1,
                floor($index * $bucketCount / $total),
            );
            $buckets[$bucketIndex][] = (float) $attempt['score_percent'];
        }

        $semesterFeatures = [];
        foreach ($buckets as $i => $scores) {
            // Guaranteed non-empty: with >= 8 scored attempts spread across 8
            // buckets by the floor() distribution above, every bucket gets >= 1.
            $semesterFeatures['sem' . ($i + 1)] = round(array_sum($scores) / count($scores), 2);
        }

        $mockFeatures = [];
        foreach (self::SUBJECT_TO_FEATURE as $label => $featureKey) {
            $profile = $subjectProfiles->firstWhere('label', $label);
            $average = $profile['average_score_percent'] ?? null;

            if (is_null($average)) {
                return [
                    'ready' => false,
                    'reason' => "Finish at least one graded exam in \"{$label}\" to estimate your exam outcome.",
                ];
            }

            $mockFeatures[$featureKey] = (float) $average;
        }

        if (is_null($overallAverageScore)) {
            return [
                'ready' => false,
                'reason' => 'Finish more graded exams to estimate an overall mock score.',
            ];
        }

        $mockFeatures['mock_total'] = (float) $overallAverageScore;

        return [
            'ready' => true,
            'features' => array_merge($semesterFeatures, $mockFeatures),
        ];
    }
}
