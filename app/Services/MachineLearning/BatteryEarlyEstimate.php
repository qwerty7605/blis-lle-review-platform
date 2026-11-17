<?php

namespace App\Services\MachineLearning;

use App\Models\User;
use App\Support\LleTaxonomy;

/**
 * The early estimate from battery exams, on the staff Student Profile: the
 * chance of passing for a student in their 3rd year on who has not finished
 * a pre-test or mock board series yet.
 *
 * It turns itself on and off with the model in use. It says nothing (status
 * `off`) unless the version in use learnt from battery exams and its battery
 * rows passed their own checks (`let_models.battery_ready`, decided when it
 * was trained: LetModelTrainer); a version put into use later decides it
 * again. Once the student finishes a series, the Estimated Outcome takes
 * over (`superseded`). Otherwise the latest semester of battery exams from
 * the 3rd year on with all six subjects is read with the exam type
 * `battery` (BatterySemesterFeatureBuilder, the same rows the model learnt
 * from); a later semester still missing a subject is shown with what it
 * needs (`locked`), and a student with no such battery exams gets `none`.
 *
 * Staff only for now: it is to prove itself on the profile before students
 * see it.
 */
class BatteryEarlyEstimate
{
    public const STATUS_OFF = 'off';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_NONE = 'none';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_READY = 'ready';

    public const NOTE = 'An early estimate from battery exams, for review planning only. It is less certain than the Estimated Outcome from a pre-test or mock board, which takes over once this student finishes one.';

    public function __construct(
        private readonly BatterySemesterFeatureBuilder $battery,
        private readonly ReviewSeriesFeatureBuilder $series,
        private readonly LetPredictionService $predictor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $student): array
    {
        $live = $this->predictor->liveModel();

        if ($live === null || !$live['battery_ready'] || !in_array(LogisticModel::BATTERY_TYPE, $live['model']->examTypes(), true)) {
            return ['status' => self::STATUS_OFF];
        }

        $finished = array_filter($this->series->seriesFor($student), static fn (array $entry): bool => $entry['complete']);

        if ($finished !== []) {
            return ['status' => self::STATUS_SUPERSEDED];
        }

        // Oldest first, from the 3rd year on.
        $semesters = $this->battery->semestersFor($student);
        $complete = array_values(array_filter($semesters, static fn (array $entry): bool => $entry['complete']));
        $latest = $complete === [] ? null : $complete[count($complete) - 1];
        $newest = $semesters === [] ? null : $semesters[count($semesters) - 1];

        if ($latest === null && $newest === null) {
            return ['status' => self::STATUS_NONE];
        }

        // A semester begun since the one read, still short of a subject.
        $pending = $newest !== null && !$newest['complete'] ? [
            'label' => $newest['label'],
            'year_level' => $newest['year_level'],
            'missing' => $newest['missing'],
        ] : null;

        if ($latest === null) {
            return ['status' => self::STATUS_LOCKED, 'pending' => $pending];
        }

        $prediction = $this->predictor->predict(BatterySemesterFeatureBuilder::features($latest));

        $scores = [];
        foreach (LleTaxonomy::keys() as $key) {
            $scores[] = ['key' => $key, 'subject' => LleTaxonomy::subjectName($key), 'score' => $latest['scores'][$key]];
        }

        return [
            'status' => self::STATUS_READY,
            'probability_passing' => round($prediction['probability_passing'], 4),
            'percent_passing' => $prediction['percent_passing'],
            'at_risk' => $prediction['at_risk'],
            'label' => $prediction['at_risk'] ? BoardExamOutlook::LABEL_AT_RISK : BoardExamOutlook::LABEL_NOT_AT_RISK,
            'note' => self::NOTE,
            'semester' => [
                'school_year' => $latest['school_year'],
                'semester' => $latest['semester'],
                'label' => $latest['label'],
                'year_level' => $latest['year_level'],
                'last_submitted_at' => $latest['last_submitted_at'],
            ],
            'scores' => $scores,
            'pending' => $pending,
            'model_version' => $prediction['version'],
        ];
    }
}
