<?php

namespace App\Services\MachineLearning;

use App\Models\LetModel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retrains the Estimated Outcome model when an administrator asks for it,
 * and decides whether the new version may replace the one in use.
 *
 * Nothing here runs by itself: retraining needs an administrator's click and
 * at least ten confirmed LLE results that the last training did not have.
 * It runs on the queue (RetrainLetModelJob): the evaluation fits about
 * twenty thousand models.
 *
 * The steps, each as version 1 was made in WEKA:
 *  1. the rows: every row of the base dataset plus each graduate's completed
 *     pre-tests and mock boards (LetTrainingSetBuilder);
 *  2. the ridge chosen by CVParameterSelection's rule and a Logistic model
 *     fitted on every row (RidgeSelector, LogisticRegressionTrainer);
 *  3. stratified 10-fold cross-validation repeated with seeds 1 to 10, the
 *     ridge chosen again inside every training fold, ZeroR on the same folds
 *     (LetModelEvaluator);
 *  4. taken up only if it meets both targets (ROC area >= 0.70, FAILED recall
 *     >= 0.60 at the 25% cutoff), beats ZeroR on Kappa (above 0), and its ROC
 *     area is at least the live version's. Otherwise it is kept as an
 *     inactive version, with the test it failed.
 *
 * Every version is kept in let_models with its figures and a file under
 * storage/app/private/let-models/.
 */
class LetModelTrainer
{
    /** Confirmed LLE results the last training had not seen, needed before training again. */
    public const MIN_NEW_RESULTS = 10;

    public const OUTCOME_PROMOTED = 'promoted';
    public const OUTCOME_KEPT_CURRENT = 'kept_current';
    public const OUTCOME_NO_BASE_DATASET = 'no_base_dataset';
    public const OUTCOME_NOT_ENOUGH_NEW_RESULTS = 'not_enough_new_results';
    public const OUTCOME_ALREADY_RUNNING = 'already_running';
    public const OUTCOME_QUEUED = 'queued';
    public const OUTCOME_ACTIVATED = 'activated';

    /** Set while a retraining is queued or running, so the page can say so. */
    public const RUNNING_KEY = 'let-model-retraining';

    /** Each retrained version is written here, on the local disk. */
    private const MODEL_DIRECTORY = 'let-models';

    public function __construct(
        private readonly LetTrainingSetBuilder $trainingSet,
        private readonly LetBaseDataset $base,
        private readonly RidgeSelector $selector,
        private readonly LetModelEvaluator $evaluator,
    ) {
    }

    /**
     * Why retraining cannot start now, or null when it can.
     *
     * @return array{outcome: string, message: string}|null
     */
    public function blockedReason(?array $set = null): ?array
    {
        $set ??= $this->trainingSet->build();

        if ($set['base'] === []) {
            return [
                'outcome' => self::OUTCOME_NO_BASE_DATASET,
                'message' => sprintf(
                    'The base dataset is missing. Retraining adds new results to the original review-exam records, so put it at %s first.',
                    LetBaseDataset::PATH,
                ),
            ];
        }

        $new = count($this->newSinceLastTraining($set['results']));

        if ($new < self::MIN_NEW_RESULTS) {
            return [
                'outcome' => self::OUTCOME_NOT_ENOUGH_NEW_RESULTS,
                'message' => sprintf(
                    'Only %d new confirmed LLE result(s) since the last training. At least %d are needed before retraining.',
                    $new,
                    self::MIN_NEW_RESULTS,
                ),
            ];
        }

        return null;
    }

    public function isRunning(): bool
    {
        return Cache::has(self::RUNNING_KEY);
    }

    public function markRunning(): void
    {
        // A worker that dies mid-run must not leave the button off for good.
        Cache::put(self::RUNNING_KEY, now()->toIso8601String(), now()->addHours(2));
    }

    public function markFinished(): void
    {
        Cache::forget(self::RUNNING_KEY);
    }

    /**
     * Train, evaluate and judge a new version. Run by RetrainLetModelJob.
     *
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    public function run(?User $by = null): array
    {
        $set = $this->trainingSet->build();
        $blocked = $this->blockedReason($set);

        if ($blocked !== null) {
            return $this->outcome($blocked['outcome'], $blocked['message']);
        }

        $rows = [...$set['base'], ...$set['graduates']];
        $fit = $this->selector->fit($rows);
        $evaluation = $this->evaluator->evaluate($rows);
        $figures = $evaluation['model'];
        $live = LetModel::query()->active()->latest('id')->first();

        $checks = [
            'roc_area_target' => [
                'passed' => $figures['roc_area'] >= LetModel::TARGET_ROC_AREA,
                'text' => sprintf('ROC area %.3f against the target of %.2f', $figures['roc_area'], LetModel::TARGET_ROC_AREA),
            ],
            'failed_recall_target' => [
                'passed' => $figures['failed_recall'] >= LetModel::TARGET_FAILED_RECALL,
                'text' => sprintf('FAILED recall %.3f at the 25%% cutoff against the target of %.2f', $figures['failed_recall'], LetModel::TARGET_FAILED_RECALL),
            ],
            'beats_zero_r' => [
                'passed' => $figures['kappa'] > $evaluation['zero_r']['kappa'],
                'text' => sprintf('Kappa %.3f against ZeroR\'s %.3f', $figures['kappa'], $evaluation['zero_r']['kappa']),
            ],
            'roc_area_vs_live' => [
                'passed' => $live === null || $figures['roc_area'] >= $live->roc_area,
                'text' => $live === null
                    ? 'No version is in use to compare with'
                    : sprintf('ROC area %.3f against version %d\'s %.3f (measured on its own training rows)', $figures['roc_area'], $live->version, $live->roc_area),
            ],
        ];

        $promoted = !in_array(false, array_column($checks, 'passed'), true);
        $failedChecks = array_values(array_map(
            static fn (array $check): string => $check['text'],
            array_filter($checks, static fn (array $check): bool => !$check['passed']),
        ));

        $version = (int) LetModel::query()->max('version') + 1;
        $path = self::MODEL_DIRECTORY . "/model-v{$version}.json";

        Storage::disk('local')->put($path, (string) json_encode([
            'version' => $version,
            'trained_at' => now()->toIso8601String(),
            'algorithm' => 'Logistic (ridge) in CVParameterSelection, as WEKA 3.8.6',
            'parameters' => $fit['model']->toArray(),
            'selection' => $fit['selection'],
            'evaluation' => $evaluation,
            'checks' => $checks,
        ], JSON_PRETTY_PRINT));

        $model = DB::transaction(function () use ($promoted, $version, $fit, $evaluation, $figures, $set, $checks, $path, $by): LetModel {
            if ($promoted) {
                LetModel::query()->active()->update(['status' => LetModel::STATUS_RETIRED]);
            }

            return LetModel::query()->create([
                'version' => $version,
                'status' => $promoted ? LetModel::STATUS_ACTIVE : LetModel::STATUS_REJECTED,
                'source' => LetModel::SOURCE_RETRAINED,
                'algorithm' => 'Logistic -R (chosen) in CVParameterSelection -P "R 0.5 10.0 20.0" -X 10 -S 1',
                'parameters' => $fit['model']->toArray(),
                'ridge' => $fit['model']->ridge,
                'at_risk_cutoff' => LogisticModel::AT_RISK_CUTOFF,
                'training_count' => $evaluation['rows'],
                'failed_count' => $evaluation['failed_rows'],
                'imported_count' => count($set['base']),
                'graduate_count' => count($set['results']),
                'roc_area' => $figures['roc_area'],
                'failed_recall' => $figures['failed_recall'],
                'false_alarm_rate' => $figures['false_alarm_rate'],
                'failed_precision' => $figures['failed_precision'],
                'failed_f_measure' => $figures['failed_f_measure'],
                'accuracy' => $figures['accuracy'],
                'kappa' => $figures['kappa'],
                'zero_r_accuracy' => $evaluation['zero_r']['accuracy'],
                'zero_r_kappa' => $evaluation['zero_r']['kappa'],
                'meets_targets' => LetModel::meetsTargets($figures['roc_area'], $figures['failed_recall']),
                'evaluation' => $evaluation,
                'selection' => $fit['selection'],
                'checks' => $checks,
                'training_keys' => array_map($this->fingerprint(...), $set['results']),
                'file_path' => $path,
                'trigger' => LetModel::TRIGGER_MANUAL,
                'trained_by' => $by?->id,
                'activated_at' => $promoted ? now() : null,
            ]);
        });

        $summary = sprintf(
            'ROC area %.3f, FAILED recall %.3f, false alarms %.3f, Kappa %.3f',
            $figures['roc_area'],
            $figures['failed_recall'],
            $figures['false_alarm_rate'],
            $figures['kappa'],
        );

        return $promoted
            ? $this->outcome(self::OUTCOME_PROMOTED, sprintf(
                'Version %d was trained on %d rows and is now in use (%s).',
                $version,
                $evaluation['rows'],
                $summary,
            ), $model)
            : $this->outcome(self::OUTCOME_KEPT_CURRENT, sprintf(
                'Version %d was trained on %d rows (%s) but was not taken up: %s.',
                $version,
                $evaluation['rows'],
                $summary,
                implode('; ', $failedChecks),
            ), $model);
    }

    /**
     * Put a saved version into use: any that meets both targets.
     *
     * @return array{outcome: string, message: string, model: ?LetModel}|null null when that version cannot be used or already is
     */
    public function activate(LetModel $model): ?array
    {
        if (!$model->canBeActivated()) {
            return null;
        }

        DB::transaction(function () use ($model): void {
            LetModel::query()->active()->update(['status' => LetModel::STATUS_RETIRED]);
            $model->update(['status' => LetModel::STATUS_ACTIVE, 'activated_at' => now()]);
        });

        return $this->outcome(self::OUTCOME_ACTIVATED, sprintf('Version %d is now the model in use.', $model->version), $model->refresh());
    }

    /**
     * What the Licensure page shows: the version in use, whether a new one
     * can be trained and why not, and every version with its figures side by
     * side. Each version's figures come from its own training rows.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $set = $this->trainingSet->build();
        $blocked = $this->blockedReason($set);
        $live = LetModel::query()->active()->latest('id')->first();

        $versions = LetModel::query()
            ->orderByDesc('version')
            ->get()
            ->map(fn (LetModel $model): array => [
                'id' => (int) $model->id,
                'version' => (int) $model->version,
                'source' => $model->source,
                'label' => $model->source === LetModel::SOURCE_WEKA ? 'Trained in WEKA' : 'Retrained here',
                'trained_at' => $model->created_at?->toIso8601String(),
                'rows' => (int) $model->training_count,
                'failed' => (int) $model->failed_count,
                'graduates' => (int) $model->graduate_count,
                'ridge' => $model->ridge,
                'roc_area' => $model->roc_area,
                'failed_recall' => $model->failed_recall,
                'false_alarm_rate' => $model->false_alarm_rate,
                'failed_precision' => $model->failed_precision,
                'failed_f_measure' => $model->failed_f_measure,
                'accuracy' => $model->accuracy,
                'kappa' => $model->kappa,
                'zero_r_accuracy' => $model->zero_r_accuracy,
                'zero_r_kappa' => $model->zero_r_kappa,
                'confusion' => $model->evaluation['model']['confusion'] ?? null,
                'meets_targets' => $model->meets_targets,
                'failed_checks' => array_values(array_map(
                    static fn (array $check): string => $check['text'],
                    array_filter($model->checks ?? [], static fn (array $check): bool => !$check['passed']),
                )),
                'file_path' => $model->file_path,
                'active' => $model->status === LetModel::STATUS_ACTIVE,
                'status' => $model->status,
                'can_activate' => $model->canBeActivated(),
            ])
            ->all();

        return [
            'live' => $live === null ? null : [
                'id' => (int) $live->id,
                'version' => (int) $live->version,
                'trained_on' => (int) $live->training_count,
                'trained_at' => $live->created_at?->toIso8601String(),
                'source' => $live->source,
                'ridge' => $live->ridge,
            ],
            'targets' => [
                'roc_area' => LetModel::TARGET_ROC_AREA,
                'failed_recall' => LetModel::TARGET_FAILED_RECALL,
                'at_risk_cutoff' => LogisticModel::AT_RISK_CUTOFF,
            ],
            'base_dataset' => [
                'present' => $this->base->exists(),
                'path' => LetBaseDataset::PATH,
                'rows' => count($set['base']),
            ],
            'data' => [
                'base_rows' => count($set['base']),
                'confirmed_graduates' => count($set['results']),
                'graduate_rows' => count($set['graduates']),
                'skipped_graduates' => $set['skipped'],
                'new_since_last_training' => count($this->newSinceLastTraining($set['results'])),
                'minimum_new_results' => self::MIN_NEW_RESULTS,
            ],
            'training' => $this->isRunning(),
            'can_retrain' => $blocked === null && !$this->isRunning(),
            'retrain_blocked_reason' => $this->isRunning() ? 'A retraining is already running.' : ($blocked['message'] ?? null),
            'versions' => $versions,
        ];
    }

    /**
     * Graduates the most recent training did not have. A corrected result
     * counts as new, since it is new information.
     *
     * @param  list<array{key: string, label: string}>  $results
     * @return list<array{key: string, label: string}>
     */
    private function newSinceLastTraining(array $results): array
    {
        $last = LetModel::query()->latest('id')->first();
        $seen = array_flip($last?->training_keys ?? []);

        return array_values(array_filter(
            $results,
            fn (array $result): bool => !isset($seen[$this->fingerprint($result)]),
        ));
    }

    /**
     * A graduate's identity together with their result.
     *
     * @param  array{key: string, label: string}  $result
     */
    private function fingerprint(array $result): string
    {
        return $result['key'] . '=' . $result['label'];
    }

    /**
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    private function outcome(string $outcome, string $message, ?LetModel $model = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'model' => $model];
    }
}
