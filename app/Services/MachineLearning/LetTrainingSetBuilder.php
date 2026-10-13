<?php

namespace App\Services\MachineLearning;

use App\Models\LleResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gathers what the board exam model learns from: the base dataset, and one
 * row for each graduate whose real board result has been recorded.
 *
 * A graduate's row is the six subject scores of their last complete semester
 * and the result they got. That semester has to be in their final school year
 * here, which is their fourth: a complete third-year semester followed by an
 * incomplete fourth year gives no row, because the model reads the scores a
 * student has on the way to the board exam, not ones from a year earlier.
 *
 * Nothing the model itself predicted is ever a row. A row needs an
 * lle_results record, and those are typed in from the real results.
 */
class LetTrainingSetBuilder
{
    public const SOURCE_GRADUATE = 'graduate';

    public function __construct(
        private readonly LetReadinessFeatureBuilder $features,
        private readonly LetBaseDataset $base,
    ) {
    }

    /**
     * @return array{
     *   base: array<int, array{key: string, features: array<string, ?float>, label: string, source: string}>,
     *   graduates: array<int, array{key: string, features: array<string, ?float>, label: string, source: string}>,
     *   skipped: int
     * } `skipped` counts graduates with a result but no complete semester in their final year to describe them by
     */
    public function build(): array
    {
        $graduates = [];
        $skipped = 0;

        // The first sitting is the one the exams were preparation for. A
        // later retake is a real result too, but its row would be the same
        // student's same scores, counted twice.
        $sittings = LleResult::query()
            ->with('student')
            ->orderBy('exam_year')
            ->orderBy('id')
            ->get()
            ->unique('user_id');

        foreach ($sittings as $sitting) {
            $student = $sitting->student;

            // Demo accounts carry invented outcomes; they must not teach the
            // model anything about real students.
            if (!$student || $student->role !== User::ROLE_STUDENT || $student->is_demo) {
                continue;
            }

            $until = $sitting->taken_at
                ? Carbon::parse($sitting->taken_at)->endOfDay()
                : Carbon::create((int) $sitting->exam_year, 12, 31)->endOfDay();

            $scores = $this->finalYearScores($student, $until);

            if ($scores === null) {
                $skipped++;

                continue;
            }

            $graduates[] = [
                'key' => 'student:' . $student->id,
                'features' => LetReadinessFeatureBuilder::features($scores),
                'label' => $sitting->isPass() ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL,
                'source' => self::SOURCE_GRADUATE,
            ];
        }

        return [
            'base' => $this->base->exists() ? $this->base->rows() : [],
            'graduates' => $graduates,
            'skipped' => $skipped,
        ];
    }

    /**
     * The scores of the student's last complete semester before $until, if
     * that semester is in the last school year they sat exams in.
     *
     * @return array<string, ?float>|null
     */
    private function finalYearScores(User $student, Carbon $until): ?array
    {
        $semesters = $this->features->semesters($student, $until);

        if ($semesters === []) {
            return null;
        }

        $finalYear = end($semesters)['school_year'];

        foreach (array_reverse($semesters) as $semester) {
            if ($semester['complete']) {
                return $semester['school_year'] === $finalYear ? $semester['scores'] : null;
            }
        }

        return null;
    }
}
