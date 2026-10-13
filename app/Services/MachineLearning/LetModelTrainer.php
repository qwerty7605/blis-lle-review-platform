<?php

namespace App\Services\MachineLearning;

use App\Models\LetModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retrains the board exam model when an administrator asks for it, and
 * decides whether the result is fit to replace the model in use.
 *
 * Nothing here runs by itself. Retraining needs an administrator's click and
 * at least ten confirmed board results the last training did not have.
 *
 * The steps, each as the model was first made in Weka:
 *  1. the data: every row of the base dataset plus every graduate with a
 *     confirmed result (LetTrainingSetBuilder), missing scores left missing;
 *  2. a J48 tree, -C 0.25 -M 2 (DecisionTreeTrainer);
 *  3. 10-fold cross-validation, random seed 1 (LetModelEvaluator);
 *  4. the baseline: the share of rows that PASSED, which is what always
 *     answering PASSED would score;
 *  5. the new tree is taken up only if its accuracy is at least the baseline
 *     and its Kappa at least the current model's. Otherwise the current model
 *     stays, and the reason is given.
 *
 * Every tree trained is kept as a numbered version in let_models, taken up or
 * not, with its file, so the record can be shown and any version that was
 * once in use gone back to.
 */
class LetModelTrainer
{
    /** Confirmed results the last training had not seen, needed before training again. */
    public const MIN_NEW_RESULTS = 10;

    public const OUTCOME_PROMOTED = 'promoted';
    public const OUTCOME_KEPT_CURRENT = 'kept_current';
    public const OUTCOME_NO_BASE_DATASET = 'no_base_dataset';
    public const OUTCOME_NOT_ENOUGH_NEW_RESULTS = 'not_enough_new_results';
    public const OUTCOME_ACTIVATED = 'activated';

    /** Where the original model's Weka file is kept. */
    public const ORIGINAL_MODEL_FILE = 'ml/models/New_Model_LLE.model';

    /** Each retrained tree is written here, on the local disk. */
    private const MODEL_DIRECTORY = 'let-models';

    public function __construct(
        private readonly LetTrainingSetBuilder $trainingSet,
        private readonly LetBaseDataset $base,
        private readonly DecisionTreeTrainer $trainer,
        private readonly LetModelEvaluator $evaluator,
    ) {
    }

    /**
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    public function run(?User $by = null): array
    {
        $set = $this->trainingSet->build();
        $blocked = $this->blockedReason($set);

        if ($blocked !== null) {
            return $this->outcome($blocked['outcome'], $blocked['message']);
        }

        $attributes = LetPredictionService::requiredFeatures();
        $records = [...$set['base'], ...$set['graduates']];

        $tree = $this->trainer->train($records, $attributes);
        $scores = $this->evaluator->evaluate($records, $attributes);
        $current = $this->currentModel($set['base'], $attributes);

        $reasons = [];
        if ($scores['accuracy'] < $scores['baseline']) {
            $reasons[] = sprintf(
                'its accuracy (%s%%) is below the baseline (%s%%), which is what always answering PASSED would score',
                $scores['accuracy'],
                $scores['baseline'],
            );
        }
        if ($scores['kappa'] < $current['kappa']) {
            $reasons[] = sprintf(
                'its Kappa (%s) is below that of the model in use, version %d (%s)',
                $this->kappa($scores['kappa']),
                $current['version'],
                $this->kappa($current['kappa']),
            );
        }

        $promoted = $reasons === [];
        $version = max((int) LetModel::query()->max('version'), LetPredictionService::BUILT_IN_VERSION) + 1;
        $path = self::MODEL_DIRECTORY . "/model-v{$version}.json";

        Storage::disk('local')->put($path, (string) json_encode([
            'version' => $version,
            'trained_at' => now()->toIso8601String(),
            'algorithm' => 'J48 -C 0.25 -M 2',
            'attributes' => [...$attributes, 'board_result'],
            'rows' => $scores['rows'],
            'tree' => $tree,
        ], JSON_PRETTY_PRINT));

        $model = DB::transaction(function () use ($promoted, $version, $tree, $scores, $set, $path, $current, $reasons, $by): LetModel {
            if ($promoted) {
                LetModel::query()->active()->update(['status' => LetModel::STATUS_RETIRED]);
            }

            return LetModel::query()->create([
                'version' => $version,
                'status' => $promoted ? LetModel::STATUS_ACTIVE : LetModel::STATUS_REJECTED,
                'tree' => $tree,
                'training_count' => $scores['rows'],
                'failed_count' => $scores['failed'],
                'graduate_count' => count($set['graduates']),
                'imported_count' => count($set['base']),
                'accuracy' => $scores['accuracy'],
                'kappa' => $scores['kappa'],
                'failed_recall' => $scores['failed_recall'],
                'baseline' => $scores['baseline'],
                'file_path' => $path,
                'training_keys' => array_map($this->fingerprint(...), $set['graduates']),
                'metrics' => [
                    'compared_with_version' => $current['version'],
                    'compared_with_kappa' => $current['kappa'],
                    'not_taken_up_because' => $reasons,
                    'confusion_matrix' => $scores['matrix'],
                    'skipped_graduates' => $set['skipped'],
                ],
                'trigger' => LetModel::TRIGGER_MANUAL,
                'trained_by' => $by?->id,
                'activated_at' => $promoted ? now() : null,
            ]);
        });

        $figures = sprintf(
            'accuracy %s%%, Kappa %s, baseline %s%%',
            $scores['accuracy'],
            $this->kappa($scores['kappa']),
            $scores['baseline'],
        );

        return $promoted
            ? $this->outcome(self::OUTCOME_PROMOTED, sprintf(
                'Version %d was trained on %d rows and is now in use (%s).',
                $version,
                $scores['rows'],
                $figures,
            ), $model)
            : $this->outcome(self::OUTCOME_KEPT_CURRENT, sprintf(
                'Version %d was trained on %d rows (%s) but was not taken up: %s. Version %d stays in use.',
                $version,
                $scores['rows'],
                $figures,
                implode(', and ', $reasons),
                $current['version'],
            ), $model);
    }

    /**
     * Put an earlier version back into use: $model, or the original model
     * when null.
     *
     * @return array{outcome: string, message: string, model: ?LetModel}|null null when that version cannot be used or already is
     */
    public function activate(?LetModel $model): ?array
    {
        $live = LetModel::query()->active()->latest('id')->first();

        if ($model === null ? $live === null : !$model->canBeActivated()) {
            return null;
        }

        DB::transaction(function () use ($model): void {
            LetModel::query()->active()->update(['status' => LetModel::STATUS_RETIRED]);
            $model?->update(['status' => LetModel::STATUS_ACTIVE, 'activated_at' => now()]);
        });

        return $this->outcome(self::OUTCOME_ACTIVATED, sprintf(
            'Version %d is now the model in use.',
            $model?->version ?? LetPredictionService::BUILT_IN_VERSION,
        ), $model);
    }

    /**
     * What the Licensure page shows: the model in use, whether it can be
     * retrained and why not, and every version with its figures.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $set = $this->trainingSet->build();
        $blocked = $this->blockedReason($set);
        // Read afresh: a predictor remembers the model it first saw, and a
        // retraining earlier in this request may have replaced it.
        $live = (new LetPredictionService())->liveModel();
        unset($live['tree']);

        $attributes = LetPredictionService::requiredFeatures();
        $original = $set['base'] !== [] ? $this->evaluator->evaluate($set['base'], $attributes) : null;

        $versions = [[
            'id' => null,
            'version' => LetPredictionService::BUILT_IN_VERSION,
            'label' => 'Original model (Weka J48)',
            'trained_at' => null,
            'rows' => $original['rows'] ?? LetPredictionService::BUILT_IN_TRAINING_COUNT,
            'failed' => $original['failed'] ?? null,
            'accuracy' => $original['accuracy'] ?? null,
            'kappa' => $original['kappa'] ?? null,
            'failed_recall' => $original['failed_recall'] ?? null,
            'baseline' => $original['baseline'] ?? null,
            'file_path' => self::ORIGINAL_MODEL_FILE,
            'active' => $live['source'] === 'built_in',
            'status' => $live['source'] === 'built_in' ? LetModel::STATUS_ACTIVE : LetModel::STATUS_RETIRED,
            'can_activate' => $live['source'] !== 'built_in',
            'note' => null,
        ]];

        foreach (LetModel::query()->orderBy('version')->orderBy('id')->get() as $model) {
            $versions[] = [
                'id' => (int) $model->id,
                'version' => (int) $model->version,
                'label' => 'Retrained',
                'trained_at' => $model->created_at?->toIso8601String(),
                'rows' => (int) $model->training_count,
                'failed' => $model->failed_count,
                'accuracy' => $model->accuracy,
                'kappa' => $model->kappa,
                'failed_recall' => $model->failed_recall,
                'baseline' => $model->baseline,
                'file_path' => $model->file_path,
                'active' => $model->status === LetModel::STATUS_ACTIVE,
                'status' => $model->status,
                'can_activate' => $model->canBeActivated(),
                'note' => $model->accuracy === null
                    ? 'Trained under the earlier rules; cannot be used with the present model.'
                    : (implode('; ', $model->metrics['not_taken_up_because'] ?? []) ?: null),
            ];
        }

        return [
            'live' => $live,
            'base_dataset' => [
                'present' => $this->base->exists(),
                'path' => LetBaseDataset::PATH,
                'rows' => count($set['base']),
            ],
            'data' => [
                'base_rows' => count($set['base']),
                'confirmed_graduates' => count($set['graduates']),
                'skipped_graduates' => $set['skipped'],
                'new_since_last_training' => count($this->newSinceLastTraining($set['graduates'])),
                'minimum_new_results' => self::MIN_NEW_RESULTS,
            ],
            'can_retrain' => $blocked === null,
            'retrain_blocked_reason' => $blocked['message'] ?? null,
            'versions' => array_reverse($versions),
        ];
    }

    /**
     * @param  array{base: array<int, mixed>, graduates: array<int, array<string, mixed>>, skipped: int}  $set
     * @return array{outcome: string, message: string}|null
     */
    private function blockedReason(array $set): ?array
    {
        if ($set['base'] === []) {
            return [
                'outcome' => self::OUTCOME_NO_BASE_DATASET,
                'message' => sprintf(
                    'The base dataset is missing. Retraining adds new results to the original records, so put lle_clean.arff at %s first.',
                    LetBaseDataset::PATH,
                ),
            ];
        }

        $new = count($this->newSinceLastTraining($set['graduates']));

        if ($new < self::MIN_NEW_RESULTS) {
            return [
                'outcome' => self::OUTCOME_NOT_ENOUGH_NEW_RESULTS,
                'message' => sprintf(
                    'Only %d new confirmed board result(s) since the last training. At least %d are needed before retraining.',
                    $new,
                    self::MIN_NEW_RESULTS,
                ),
            ];
        }

        return null;
    }

    /**
     * Graduates the most recent training did not have. A corrected result
     * counts as new, since it is new information.
     *
     * @param  array<int, array{key: string, label: string}>  $graduates
     * @return array<int, array{key: string, label: string}>
     */
    private function newSinceLastTraining(array $graduates): array
    {
        $last = LetModel::query()->currentRules()->latest('id')->first();
        $seen = array_flip($last?->training_keys ?? []);

        return array_values(array_filter(
            $graduates,
            fn (array $record): bool => !isset($seen[$this->fingerprint($record)]),
        ));
    }

    /**
     * The model in use and the Kappa a new one has to match. For a retrained
     * version that is the Kappa recorded when it was trained. For the
     * original it is worked out the same way, on the base dataset it was
     * trained on.
     *
     * @param  array<int, array<string, mixed>>  $base
     * @param  array<int, string>  $attributes
     * @return array{version: int, kappa: float}
     */
    private function currentModel(array $base, array $attributes): array
    {
        $live = LetModel::query()->active()->latest('id')->first();

        return $live
            ? ['version' => (int) $live->version, 'kappa' => (float) $live->kappa]
            : [
                'version' => LetPredictionService::BUILT_IN_VERSION,
                'kappa' => $this->evaluator->evaluate($base, $attributes)['kappa'],
            ];
    }

    /**
     * A record's identity together with its outcome.
     *
     * @param  array{key: string, label: string}  $record
     */
    private function fingerprint(array $record): string
    {
        return $record['key'] . '=' . $record['label'];
    }

    private function kappa(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    /**
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    private function outcome(string $outcome, string $message, ?LetModel $model = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'model' => $model];
    }
}
