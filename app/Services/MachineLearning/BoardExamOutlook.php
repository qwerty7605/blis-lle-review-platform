<?php

namespace App\Services\MachineLearning;

use App\Models\User;
use App\Support\LleTaxonomy;

/**
 * The Estimated Outcome card, for a student and for staff looking at that
 * student. The same wording for every student, whatever their year level.
 *
 * Locked until the student has completed a pre-test or mock board series
 * (all six subject exams submitted); any series under way is shown with what
 * it still needs. Then the most recently completed series (the one whose
 * last subject was submitted most recently) is read: the estimated chance of
 * passing, "At risk" when the chance of failing is 25% or more, which series
 * and when, and the six subject scores it is based on. Battery and regular
 * mock exams never feed it.
 */
class BoardExamOutlook
{
    public const STATUS_LOCKED = 'locked';

    public const STATUS_READY = 'ready';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const LABEL_AT_RISK = 'At risk';

    public const LABEL_NOT_AT_RISK = 'Not at risk';

    public const NOTE = 'This is an estimate based on past review-exam results, for review planning only. It does not say whether you will pass the actual LLE.';

    public function __construct(
        private readonly ReviewSeriesFeatureBuilder $series,
        private readonly LetPredictionService $predictor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $student): array
    {
        $all = $this->series->seriesFor($student);
        $completed = array_values(array_filter($all, static fn (array $entry): bool => $entry['complete']));
        $latest = $completed[0] ?? null;

        // Series still under way, newest first: all of them while locked, and
        // the ones begun since the series read once it is not.
        $inProgress = array_values(array_map(
            fn (array $entry): array => $this->progress($entry),
            array_filter($all, static fn (array $entry): bool => !$entry['complete']
                && ($latest === null || $entry['last_submitted_at'] > $latest['completed_at'])),
        ));

        $payload = ['title' => 'Estimated Outcome', 'in_progress' => $inProgress];

        if ($latest === null) {
            return $payload + [
                'status' => self::STATUS_LOCKED,
                'message' => ReviewSeriesFeatureBuilder::LOCKED_MESSAGE,
            ];
        }

        $prediction = $this->predictor->predict(ReviewSeriesFeatureBuilder::features($latest));

        if ($prediction === null) {
            return $payload + [
                'status' => self::STATUS_UNAVAILABLE,
                'message' => 'No Estimated Outcome model is in use right now.',
            ];
        }

        $scores = [];
        foreach (LleTaxonomy::keys() as $key) {
            $scores[] = ['key' => $key, 'subject' => LleTaxonomy::subjectName($key), 'score' => $latest['scores'][$key]];
        }

        return $payload + [
            'status' => self::STATUS_READY,
            'probability_passing' => round($prediction['probability_passing'], 4),
            'percent_passing' => $prediction['percent_passing'],
            'at_risk' => $prediction['at_risk'],
            'label' => $prediction['at_risk'] ? self::LABEL_AT_RISK : self::LABEL_NOT_AT_RISK,
            'note' => self::NOTE,
            'series' => [
                'id' => $latest['series_id'],
                'name' => $latest['name'],
                'type' => $latest['type'],
                'type_label' => $latest['type_label'],
                'completed_at' => $latest['completed_at'],
            ],
            'scores' => $scores,
            'model_version' => $prediction['version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{name: string, type_label: string, done: int, required: int, missing: list<string>}
     */
    private function progress(array $entry): array
    {
        return [
            'name' => $entry['name'],
            'type_label' => $entry['type_label'],
            'done' => $entry['done'],
            'required' => $entry['required'],
            'missing' => $entry['missing'],
        ];
    }
}
