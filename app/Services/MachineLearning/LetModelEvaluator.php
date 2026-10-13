<?php

namespace App\Services\MachineLearning;

/**
 * Judges a way of training by 10-fold cross-validation with random seed 1,
 * dealt into folds exactly as Weka deals them, so the figures are the ones
 * Weka's own "Cross-validation, folds 10, seed 1" reports for the same file.
 *
 * Every record is predicted once, by a tree grown from the nine tenths of the
 * data it is not in. No tree is marked on a record it was trained on.
 */
class LetModelEvaluator
{
    public const FOLDS = 10;

    public const SEED = 1;

    public function __construct(
        private readonly DecisionTreeTrainer $trainer,
        private readonly LetPredictionService $predictor,
    ) {
    }

    /**
     * @param  array<int, array{features: array<string, ?float>, label: string}>  $rows
     * @param  array<int, string>  $attributes
     * @return array{rows: int, failed: int, accuracy: float, kappa: float, failed_recall: ?float, baseline: float, matrix: array<string, array<string, int>>}
     */
    public function evaluate(array $rows, array $attributes): array
    {
        $rows = array_values($rows);
        $pass = LetPredictionService::RESULT_PASS;
        $fail = LetPredictionService::RESULT_FAIL;
        $matrix = [$fail => [$fail => 0, $pass => 0], $pass => [$fail => 0, $pass => 0]];

        foreach ($this->folds($rows) as [$training, $held]) {
            $tree = $this->trainer->train($training, $attributes);

            foreach ($held as $row) {
                $matrix[$row['label']][$this->predictor->predict($row['features'], $tree)['prediction']]++;
            }
        }

        $total = count($rows);
        $failed = array_sum($matrix[$fail]);
        $passed = array_sum($matrix[$pass]);
        $right = $matrix[$fail][$fail] + $matrix[$pass][$pass];

        // Kappa: agreement with the real results beyond what guessing in the
        // same proportions would reach by chance.
        $saidFail = $matrix[$fail][$fail] + $matrix[$pass][$fail];
        $saidPass = $matrix[$fail][$pass] + $matrix[$pass][$pass];
        $chance = $total > 0 ? ($failed * $saidFail + $passed * $saidPass) / ($total * $total) : 0.0;
        $observed = $total > 0 ? $right / $total : 0.0;

        return [
            'rows' => $total,
            'failed' => $failed,
            'accuracy' => $total > 0 ? round($observed * 100, 2) : 0.0,
            'kappa' => $chance < 1 ? round(($observed - $chance) / (1 - $chance), 4) : 1.0,
            'failed_recall' => $failed > 0 ? round($matrix[$fail][$fail] / $failed * 100, 2) : null,
            // What always answering PASSED would score.
            'baseline' => $total > 0 ? round($passed / $total * 100, 2) : 0.0,
            'matrix' => $matrix,
        ];
    }

    /**
     * The training and held-out rows of each fold, in Weka's order of events:
     * shuffle with java.util.Random(seed), group by outcome, deal the groups
     * round the folds, then cut the list into ten runs.
     *
     * @param  array<int, array{label: string}>  $rows
     * @return array<int, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}>
     */
    private function folds(array $rows): array
    {
        $count = count($rows);
        $folds = min(self::FOLDS, $count);

        if ($folds < 2) {
            return [];
        }

        // Instances.randomize()
        $random = new JavaRandom(self::SEED);
        for ($index = $count - 1; $index > 0; $index--) {
            $other = $random->nextInt($index + 1);
            [$rows[$index], $rows[$other]] = [$rows[$other], $rows[$index]];
        }

        // Instances.stratify(): rows of one outcome brought together, in the
        // order each outcome is first met...
        $index = 1;
        while ($index < $count) {
            $label = $rows[$index - 1]['label'];

            for ($next = $index; $next < $count; $next++) {
                if ($rows[$next]['label'] === $label) {
                    [$rows[$index], $rows[$next]] = [$rows[$next], $rows[$index]];
                    $index++;
                }
            }

            $index++;
        }

        // ...then every tenth row taken in turn, so each fold gets its share
        // of both outcomes.
        $dealt = [];
        for ($start = 0; count($dealt) < $count; $start++) {
            for ($next = $start; $next < $count; $next += $folds) {
                $dealt[] = $rows[$next];
            }
        }

        // Instances.testCV() / trainCV()
        $size = intdiv($count, $folds);
        $extra = $count % $folds;
        $result = [];

        for ($fold = 0; $fold < $folds; $fold++) {
            $first = $fold * $size + min($fold, $extra);
            $length = $size + ($fold < $extra ? 1 : 0);

            $result[] = [
                [...array_slice($dealt, 0, $first), ...array_slice($dealt, $first + $length)],
                array_slice($dealt, $first, $length),
            ];
        }

        return $result;
    }
}
