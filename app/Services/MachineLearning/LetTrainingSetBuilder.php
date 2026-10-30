<?php

namespace App\Services\MachineLearning;

use App\Models\LleResult;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gathers what the Estimated Outcome model learns from: every row of the
 * base dataset, plus, for each graduate with a recorded LLE result, one row
 * per pre-test or mock board series they completed before their LLE date,
 * each with its series' exam type. That matches the base dataset, where one
 * examinee contributes several review exams.
 *
 * Never used: battery or regular mock exams, sittings submitted after the
 * LLE date (an exam's latest sitting on or before that day is the one that
 * counts), anything the model itself predicted (a row needs an lle_results
 * record, typed in from the real results), and demo accounts, whose results
 * are invented.
 */
class LetTrainingSetBuilder
{
    public const SOURCE_GRADUATE = 'graduate';

    public function __construct(
        private readonly ReviewSeriesFeatureBuilder $series,
        private readonly LetBaseDataset $base,
    ) {
    }

    /**
     * @return array{
     *   base: list<array{key: string, features: array<string, mixed>, label: string, source: string}>,
     *   graduates: list<array{key: string, features: array<string, mixed>, label: string, source: string}>,
     *   results: list<array{key: string, label: string, rows: int}>,
     *   skipped: int
     * } `results` is one entry per graduate who gave rows; `skipped` counts graduates with a result but no series completed before it
     */
    public function build(): array
    {
        $rows = [];
        $results = [];
        $skipped = 0;

        // The first sitting is the one the review exams were preparation
        // for. A retake is a real result too, but its rows would be the same
        // student's same series counted twice.
        $sittings = LleResult::query()
            ->with('student')
            ->orderBy('exam_year')
            ->orderBy('id')
            ->get()
            ->unique('user_id');

        foreach ($sittings as $sitting) {
            $student = $sitting->student;

            if (!$student || $student->role !== User::ROLE_STUDENT || $student->is_demo) {
                continue;
            }

            // The LLE date; with none recorded, the end of the exam year.
            $until = $sitting->taken_at
                ? Carbon::parse($sitting->taken_at)->endOfDay()
                : Carbon::create((int) $sitting->exam_year, 12, 31)->endOfDay();

            $label = $sitting->isPass() ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL;
            $completed = array_values(array_filter(
                $this->series->seriesFor($student, $until),
                static fn (array $entry): bool => $entry['complete'],
            ));

            if ($completed === []) {
                $skipped++;

                continue;
            }

            foreach ($completed as $entry) {
                $rows[] = [
                    'key' => 'student:' . $student->id . ':series:' . $entry['series_id'],
                    'features' => ReviewSeriesFeatureBuilder::features($entry),
                    'label' => $label,
                    'source' => self::SOURCE_GRADUATE,
                ];
            }

            $results[] = ['key' => 'student:' . $student->id, 'label' => $label, 'rows' => count($completed)];
        }

        return [
            'base' => $this->base->exists() ? $this->base->rows() : [],
            'graduates' => $rows,
            'results' => $results,
            'skipped' => $skipped,
        ];
    }
}
