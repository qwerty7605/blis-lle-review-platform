<?php

namespace App\Services\MachineLearning;

use App\Models\LetModel;
use Throwable;

/**
 * The Estimated Outcome: a student's chance of passing the LLE, estimated
 * from one completed pre-test or mock board (its six subject scores and its
 * type) by the version of the model in use (`let_models`, status active).
 *
 * The model is ridge-penalized logistic regression trained in WEKA 3.8.6
 * (version 1, ml/models/logistic-v1.json) or retrained here
 * (LetModelTrainer). P(PASSED) is computed in PHP from its stored
 * coefficients, the way WEKA's Logistic computes it (LogisticModel). A
 * student is "At risk" when the chance of failing is 25% or more.
 *
 * It is an estimate from past review-exam results, for review planning; it
 * does not say whether anyone will pass the actual LLE.
 */
class LetPredictionService
{
    public const RESULT_PASS = 'PASS';

    public const RESULT_FAIL = 'FAIL';

    /** @var array{id: int, version: int, trained_on: int, trained_at: ?string, source: string, cutoff: float, model: LogisticModel}|false|null */
    private array|false|null $live = null;

    /**
     * The version predictions are made with, or null when none is active
     * (or the table cannot be read yet).
     *
     * @return array{id: int, version: int, trained_on: int, trained_at: ?string, source: string, cutoff: float, model: LogisticModel}|null
     */
    public function liveModel(): ?array
    {
        if ($this->live === null) {
            try {
                $row = LetModel::query()->active()->latest('id')->first();
            } catch (Throwable) {
                $row = null;
            }

            $this->live = $row === null ? false : [
                'id' => (int) $row->id,
                'version' => (int) $row->version,
                'trained_on' => (int) $row->training_count,
                'trained_at' => $row->created_at?->toIso8601String(),
                'source' => (string) $row->source,
                'cutoff' => (float) $row->at_risk_cutoff,
                'model' => $row->model(),
            ];
        }

        return $this->live ?: null;
    }

    /**
     * @param  array<string, mixed>  $features  exam_type ('pre_test' | 'mock_board') and the six subject scores, keyed by subject key; a missing score is null
     * @return array{probability_passing: float, probability_failing: float, percent_passing: int, at_risk: bool, version: int}|null  null when no model is in use
     */
    public function predict(array $features, ?LogisticModel $model = null, ?float $cutoff = null): ?array
    {
        $live = $model === null ? $this->liveModel() : null;
        $model ??= $live['model'] ?? null;

        if ($model === null) {
            return null;
        }

        $cutoff ??= $live['cutoff'] ?? LogisticModel::AT_RISK_CUTOFF;
        $passing = $model->probabilityOfPassing($features);
        $failing = $model->probabilityOfFailing($features);

        return [
            'probability_passing' => $passing,
            'probability_failing' => $failing,
            'percent_passing' => (int) round($passing * 100),
            // Decided on the unrounded chance, at exactly the cutoff the
            // model was judged at.
            'at_risk' => $failing >= $cutoff,
            'version' => (int) ($live['version'] ?? 0),
        ];
    }
}
