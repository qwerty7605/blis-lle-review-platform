<?php

namespace App\Services\MachineLearning;

/**
 * WEKA 3.8.6's CVParameterSelection around Logistic, in PHP: picks the ridge
 * by 10-fold cross-validation among 0.5, 1.0, ... 10.0 (`-P "R 0.5 10.0
 * 20.0"`), exactly as WEKA does it:
 *
 *   - the rows are shuffled with java.util.Random(1) and stratified into 10
 *     folds (each candidate meets the same folds);
 *   - each fold's training rows are reshuffled with a new Random(1)
 *     (trainCV(folds, j, new Random(1))) and a model is fitted on them;
 *   - a held-out row counts as an error when the class with the larger
 *     probability is not its own (FAILED on an exact tie);
 *   - the candidate with the lowest error rate wins; on a tie the earlier
 *     (smaller) ridge is kept, since WEKA only replaces the best on a
 *     strictly lower error.
 *
 * The candidates are stepped the way WEKA's loop steps them, by repeated
 * addition of (upper - lower) / (steps - 1), so floating point gives the same
 * twenty values.
 */
class RidgeSelector
{
    public const LOWER = 0.5;

    public const UPPER = 10.0;

    public const STEPS = 20;

    public const FOLDS = 10;

    public const SEED = 1;

    public function __construct(
        private readonly LogisticRegressionTrainer $trainer,
    ) {
    }

    /**
     * The candidate ridges, in WEKA's order.
     *
     * @return list<float>
     */
    public static function candidates(): array
    {
        $values = [];
        $increment = (self::UPPER - self::LOWER) / (self::STEPS - 1);

        for ($ridge = self::LOWER; $ridge <= self::UPPER; $ridge += $increment) {
            $values[] = $ridge;
        }

        return $values;
    }

    /**
     * $examTypes: how the whole training set codes the exam type
     * (LogisticModel::examTypesFor()); every fold is coded the same way.
     *
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<string>|null  $examTypes
     * @return array{ridge: float, candidates: list<array{ridge: float, error_rate: float}>}
     */
    public function select(array $rows, ?array $examTypes = null): array
    {
        $examTypes ??= LogisticModel::examTypesFor($rows);
        $rows = WekaFolds::stratify(WekaFolds::randomize($rows, new JavaRandom(self::SEED)), self::FOLDS);
        $count = count($rows);
        $folds = [];

        for ($fold = 0; $fold < self::FOLDS; $fold++) {
            $folds[] = [
                WekaFolds::randomize(WekaFolds::train($rows, self::FOLDS, $fold), new JavaRandom(1)),
                WekaFolds::test($rows, self::FOLDS, $fold),
            ];
        }

        $best = null;
        $bestError = null;
        $results = [];
        $starts = [];

        foreach (self::candidates() as $ridge) {
            $errors = 0;

            foreach ($folds as $fold => [$train, $test]) {
                // The previous candidate's answer on this fold is a good
                // place to start looking; the minimum found is the same.
                $fit = $this->trainer->fitFrom($train, $ridge, $starts[$fold] ?? null, $examTypes);
                $starts[$fold] = $fit['beta'];

                foreach ($test as $row) {
                    $failing = $fit['model']->probabilityOfFailing($row['features']);
                    $passing = $fit['model']->probabilityOfPassing($row['features']);
                    $predicted = $failing >= $passing ? LetPredictionService::RESULT_FAIL : LetPredictionService::RESULT_PASS;
                    $errors += $predicted === $row['label'] ? 0 : 1;
                }
            }

            $error = $errors / $count;
            $results[] = ['ridge' => $ridge, 'error_rate' => $error];

            if ($bestError === null || $error < $bestError) {
                $best = $ridge;
                $bestError = $error;
            }
        }

        return ['ridge' => (float) $best, 'candidates' => $results];
    }

    /**
     * CVParameterSelection.buildClassifier(): the chosen ridge, then a model
     * fitted on every row, in their original order.
     *
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<string>|null  $examTypes
     * @return array{model: LogisticModel, selection: array{ridge: float, candidates: list<array{ridge: float, error_rate: float}>}}
     */
    public function fit(array $rows, ?array $examTypes = null): array
    {
        $examTypes ??= LogisticModel::examTypesFor($rows);
        $selection = $this->select($rows, $examTypes);

        return ['model' => $this->trainer->fit(array_values($rows), $selection['ridge'], $examTypes), 'selection' => $selection];
    }
}
