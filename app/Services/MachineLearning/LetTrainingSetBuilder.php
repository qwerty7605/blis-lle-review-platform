<?php

namespace App\Services\MachineLearning;

use App\Models\LetTrainingRecord;
use App\Models\LleResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gathers what the LET readiness tree can learn from: one record per person
 * whose real licensure result is known, pairing the 15 features with the
 * outcome.
 *
 * A student becomes a record once an lle_results row exists for them -- that
 * row is the proof they sat the exam, which is why someone who dropped out is
 * never counted as a failure. Records brought in from a file
 * (`let:import-training-data`) are added as they are.
 */
class LetTrainingSetBuilder
{
    public const SOURCE_GRADUATE = 'graduate';
    public const SOURCE_IMPORTED = 'imported';

    public function __construct(private readonly LetReadinessFeatureBuilder $features)
    {
    }

    /**
     * @return array{
     *   records: array<int, array{key: string, features: array<string, float>, label: string, source: string}>,
     *   skipped: int
     * } `skipped` counts students with a result but too little exam history to describe them
     */
    public function build(): array
    {
        $records = [];
        $skipped = 0;

        // The first sitting is the one the mock history was preparation for.
        // A later retake is a real result too, but its features would be the
        // same student's same history, counted twice.
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

            $built = $this->features->forStudent($student, $until);

            if (!$built['ready']) {
                $skipped++;

                continue;
            }

            $records[] = [
                'key' => 'student:' . $student->id,
                'features' => $built['features'],
                'label' => $sitting->isPass() ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL,
                'source' => self::SOURCE_GRADUATE,
            ];
        }

        $required = LetPredictionService::requiredFeatures();

        foreach (LetTrainingRecord::query()->orderBy('id')->get() as $imported) {
            $features = [];

            foreach ($required as $feature) {
                $value = $imported->features[$feature] ?? null;
                if (!is_numeric($value)) {
                    continue 2;
                }

                $features[$feature] = (float) $value;
            }

            $records[] = [
                'key' => 'imported:' . $imported->id,
                'features' => $features,
                'label' => strtolower((string) $imported->result) === LleResult::RESULT_PASS
                    ? LetPredictionService::RESULT_PASS
                    : LetPredictionService::RESULT_FAIL,
                'source' => self::SOURCE_IMPORTED,
            ];
        }

        return ['records' => $records, 'skipped' => $skipped];
    }
}
