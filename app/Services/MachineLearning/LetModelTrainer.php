<?php

namespace App\Services\MachineLearning;

use App\Models\LetModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Retrains the LET readiness tree from recorded licensure results and decides
 * whether the result is fit to replace the model in use.
 *
 * Retraining is cheap; trusting the result is the hard part. A tree grown from
 * a few dozen graduates can easily be worse than the one it would replace, so
 * a new tree goes live only if it does at least as well as the live model on
 * results the live model has never seen. For those results the new tree's
 * answers come from copies of it trained without them (cross-validation), so
 * neither side is marked on its own homework.
 *
 * Every tree that gets as far as that comparison is kept in let_models, taken
 * up or not, so the page can show what was tried and why.
 */
class LetModelTrainer
{
    /** Fewer records than this cannot support a tree worth comparing. */
    public const MIN_RECORDS = 20;

    /** Each outcome needs this many, or the tree only ever learns the other one. */
    public const MIN_PER_OUTCOME = 5;

    /** Results the live model has not seen, needed before judging a replacement on them. */
    public const MIN_NEW_RESULTS = 10;

    private const FOLDS = 10;

    public const OUTCOME_ACTIVATED = 'activated';
    public const OUTCOME_KEPT_CURRENT = 'kept_current';
    public const OUTCOME_NOT_ENOUGH_DATA = 'not_enough_data';
    public const OUTCOME_NOT_ENOUGH_NEW_RESULTS = 'not_enough_new_results';
    public const OUTCOME_NOTHING_NEW = 'nothing_new';

    public function __construct(
        private readonly LetTrainingSetBuilder $trainingSet,
        private readonly DecisionTreeTrainer $trainer,
        private readonly LetPredictionService $predictor,
    ) {
    }

    /**
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    public function run(?User $by = null, string $trigger = LetModel::TRIGGER_AUTO): array
    {
        $set = $this->trainingSet->build();
        $records = $set['records'];
        $counts = $this->countOutcomes($records);

        if (count($records) < self::MIN_RECORDS || min($counts) < self::MIN_PER_OUTCOME) {
            return $this->outcome(self::OUTCOME_NOT_ENOUGH_DATA, sprintf(
                'Not enough results to learn from yet: %d usable so far (%d passed, %d failed). At least %d are needed, with %d of each.',
                count($records),
                $counts[LetPredictionService::RESULT_PASS],
                $counts[LetPredictionService::RESULT_FAIL],
                self::MIN_RECORDS,
                self::MIN_PER_OUTCOME,
            ));
        }

        $fingerprints = array_map($this->fingerprint(...), $records);

        // Whatever was trained last has already been judged on this data,
        // taken up or not. Running again would only repeat the verdict -- and
        // would quietly undo an administrator's decision to revert a model.
        $latest = LetModel::query()->latest('id')->first();
        if ($latest && array_diff($fingerprints, $latest->training_keys ?? []) === []) {
            return $this->outcome(self::OUTCOME_NOTHING_NEW, 'No new licensure results since the model was last checked.');
        }

        $live = LetModel::query()->active()->latest('id')->first();
        $unseen = $this->unseenByLiveModel($records, $live);

        if (count($unseen) < self::MIN_NEW_RESULTS) {
            return $this->outcome(self::OUTCOME_NOT_ENOUGH_NEW_RESULTS, sprintf(
                'Only %d new result(s) since the current model was set. At least %d are needed to judge a retrained one fairly.',
                count($unseen),
                self::MIN_NEW_RESULTS,
            ));
        }

        $attributes = LetPredictionService::requiredFeatures();
        $outOfFold = $this->outOfFoldPredictions($records, $attributes);
        $liveTree = $live?->tree ?? LetPredictionService::builtInTree();

        $candidateRight = 0;
        $liveRight = 0;
        foreach ($unseen as $record) {
            $candidateRight += $outOfFold[$record['key']] === $record['label'] ? 1 : 0;
            $liveRight += $this->predictor->predict($record['features'], $liveTree)['prediction'] === $record['label'] ? 1 : 0;
        }

        $overallRight = 0;
        foreach ($records as $record) {
            $overallRight += $outOfFold[$record['key']] === $record['label'] ? 1 : 0;
        }

        $candidateAccuracy = round($candidateRight / count($unseen) * 100, 1);
        $liveAccuracy = round($liveRight / count($unseen) * 100, 1);
        $accepted = $candidateRight >= $liveRight;

        $graduates = count(array_filter($records, static fn (array $record): bool => $record['source'] === LetTrainingSetBuilder::SOURCE_GRADUATE));

        $model = DB::transaction(function () use ($accepted, $records, $attributes, $graduates, $fingerprints, $counts, $set, $unseen, $candidateAccuracy, $liveAccuracy, $overallRight, $live, $trigger, $by): LetModel {
            if ($accepted) {
                LetModel::query()->active()->update(['status' => LetModel::STATUS_RETIRED]);
            }

            return LetModel::query()->create([
                'status' => $accepted ? LetModel::STATUS_ACTIVE : LetModel::STATUS_REJECTED,
                'tree' => $this->trainer->train($records, $attributes),
                'training_count' => count($records),
                'graduate_count' => $graduates,
                'imported_count' => count($records) - $graduates,
                'training_keys' => $fingerprints,
                'metrics' => [
                    'compared_on' => count($unseen),
                    'candidate_accuracy' => $candidateAccuracy,
                    'live_accuracy' => $liveAccuracy,
                    'compared_against' => $live ? 'model ' . $live->id : 'built-in model',
                    'cross_validated_accuracy' => round($overallRight / count($records) * 100, 1),
                    'passed' => $counts[LetPredictionService::RESULT_PASS],
                    'failed' => $counts[LetPredictionService::RESULT_FAIL],
                    'skipped_for_thin_history' => $set['skipped'],
                ],
                'trigger' => $trigger,
                'trained_by' => $by?->id,
                'activated_at' => $accepted ? now() : null,
            ]);
        });

        return $accepted
            ? $this->outcome(self::OUTCOME_ACTIVATED, sprintf(
                'The model was retrained on %d results and is now in use. On the %d results the previous model had not seen, it got %s%% right against %s%%.',
                count($records),
                count($unseen),
                $candidateAccuracy,
                $liveAccuracy,
            ), $model)
            : $this->outcome(self::OUTCOME_KEPT_CURRENT, sprintf(
                'A retrained model got %s%% of the %d new results right, below the current model\'s %s%%, so the current model stays in use.',
                $candidateAccuracy,
                count($unseen),
                $liveAccuracy,
            ), $model);
    }

    /**
     * Take the live model out of use and go back to the one before it, or to
     * the built-in tree when there is none.
     *
     * @return array{outcome: string, message: string, model: ?LetModel}|null null when the built-in tree is already live
     */
    public function revert(): ?array
    {
        $live = LetModel::query()->active()->latest('id')->first();
        if (!$live) {
            return null;
        }

        $previous = DB::transaction(function () use ($live): ?LetModel {
            $live->update(['status' => LetModel::STATUS_REVERTED]);

            $previous = LetModel::query()
                ->where('status', LetModel::STATUS_RETIRED)
                ->latest('id')
                ->first();

            $previous?->update(['status' => LetModel::STATUS_ACTIVE, 'activated_at' => now()]);

            return $previous;
        });

        return $this->outcome('reverted', $previous
            ? sprintf('Went back to the model trained on %d results.', $previous->training_count)
            : 'Went back to the built-in model.', $previous);
    }

    /**
     * What the Licensure page shows: which model is live, how much there is
     * to learn from, and what recent retraining decided.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $set = $this->trainingSet->build();
        $records = $set['records'];
        $counts = $this->countOutcomes($records);
        $live = LetModel::query()->active()->latest('id')->first();
        // Read afresh: $this->predictor remembers the model it first saw, and
        // a retraining earlier in this request may have replaced it.
        $liveInfo = (new LetPredictionService())->liveModel();
        unset($liveInfo['tree']);

        return [
            'live' => $liveInfo,
            'data' => [
                'usable' => count($records),
                'passed' => $counts[LetPredictionService::RESULT_PASS],
                'failed' => $counts[LetPredictionService::RESULT_FAIL],
                'skipped_for_thin_history' => $set['skipped'],
                'new_since_live_model' => count($this->unseenByLiveModel($records, $live)),
                'minimum_records' => self::MIN_RECORDS,
                'minimum_per_outcome' => self::MIN_PER_OUTCOME,
                'minimum_new_results' => self::MIN_NEW_RESULTS,
            ],
            'history' => LetModel::query()
                ->latest('id')
                ->limit(5)
                ->get(['id', 'status', 'training_count', 'graduate_count', 'imported_count', 'metrics', 'trigger', 'activated_at', 'created_at'])
                ->all(),
            'can_revert' => $live !== null,
        ];
    }

    /**
     * Records the live model was not trained on. The built-in tree was grown
     * from the original cohort, which is what an imported file holds, so for
     * it only this system's own graduates count as unseen.
     *
     * @param  array<int, array{key: string, features: array<string, float>, label: string, source: string}>  $records
     * @return array<int, array{key: string, features: array<string, float>, label: string, source: string}>
     */
    private function unseenByLiveModel(array $records, ?LetModel $live): array
    {
        if (!$live) {
            return array_values(array_filter(
                $records,
                static fn (array $record): bool => $record['source'] === LetTrainingSetBuilder::SOURCE_GRADUATE,
            ));
        }

        $seen = array_flip($live->training_keys ?? []);

        return array_values(array_filter(
            $records,
            fn (array $record): bool => !isset($seen[$this->fingerprint($record)]),
        ));
    }

    /**
     * For every record, the answer of a tree that was trained without it.
     *
     * The records are dealt into folds so that each fold has its share of
     * passes and fails, in an order fixed by the records themselves: the same
     * data always gives the same verdict.
     *
     * @param  array<int, array{key: string, features: array<string, float>, label: string, source: string}>  $records
     * @param  array<int, string>  $attributes
     * @return array<string, string> record key => predicted outcome
     */
    private function outOfFoldPredictions(array $records, array $attributes): array
    {
        $folds = max(2, min(self::FOLDS, min($this->countOutcomes($records))));

        $byOutcome = [];
        foreach ($records as $record) {
            $byOutcome[$record['label']][] = $record;
        }

        $assigned = [];
        foreach ($byOutcome as $group) {
            usort($group, static fn (array $left, array $right): int => [crc32($left['key']), $left['key']] <=> [crc32($right['key']), $right['key']]);

            foreach ($group as $index => $record) {
                $assigned[$index % $folds][] = $record;
            }
        }

        $predictions = [];

        foreach ($assigned as $fold => $held) {
            $training = [];
            foreach ($assigned as $other => $rows) {
                if ($other !== $fold) {
                    array_push($training, ...$rows);
                }
            }

            $tree = $this->trainer->train($training, $attributes);

            foreach ($held as $record) {
                $predictions[$record['key']] = $this->predictor->predict($record['features'], $tree)['prediction'];
            }
        }

        return $predictions;
    }

    /**
     * A record's identity together with its outcome, so a corrected result
     * counts as new information.
     *
     * @param  array{key: string, label: string}  $record
     */
    private function fingerprint(array $record): string
    {
        return $record['key'] . '=' . $record['label'];
    }

    /**
     * @param  array<int, array{label: string}>  $records
     * @return array<string, int>
     */
    private function countOutcomes(array $records): array
    {
        $counts = [LetPredictionService::RESULT_PASS => 0, LetPredictionService::RESULT_FAIL => 0];

        foreach ($records as $record) {
            $counts[$record['label']]++;
        }

        return $counts;
    }

    /**
     * @return array{outcome: string, message: string, model: ?LetModel}
     */
    private function outcome(string $outcome, string $message, ?LetModel $model = null): array
    {
        return ['outcome' => $outcome, 'message' => $message, 'model' => $model];
    }
}
