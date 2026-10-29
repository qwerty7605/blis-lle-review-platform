<?php

namespace App\Services\MachineLearning;

/**
 * How WEKA orders and deals a dataset for cross-validation
 * (weka.core.Instances: randomize(), stratify(), trainCV(), testCV()), so a
 * seed here means the same folds it means in WEKA. Rows are arrays with a
 * 'label'.
 */
final class WekaFolds
{
    /**
     * Instances.randomize(): a Fisher-Yates shuffle drawing from $random.
     *
     * @template T
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function randomize(array $rows, JavaRandom $random): array
    {
        $rows = array_values($rows);

        for ($index = count($rows) - 1; $index > 0; $index--) {
            $other = $random->nextInt($index + 1);
            [$rows[$index], $rows[$other]] = [$rows[$other], $rows[$index]];
        }

        return $rows;
    }

    /**
     * Instances.stratify(): rows of one outcome brought together, in the
     * order each outcome is first met, then every $folds-th row taken in
     * turn, so each fold gets its share of both outcomes.
     *
     * @param  list<array{label: string}>  $rows
     * @return list<array{label: string}>
     */
    public static function stratify(array $rows, int $folds): array
    {
        $rows = array_values($rows);
        $count = count($rows);
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

        $dealt = [];
        for ($start = 0; count($dealt) < $count; $start++) {
            for ($next = $start; $next < $count; $next += $folds) {
                $dealt[] = $rows[$next];
            }
        }

        return $dealt;
    }

    /**
     * Instances.trainCV() without a random: everything outside fold $fold,
     * in order.
     *
     * @template T
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function train(array $rows, int $folds, int $fold): array
    {
        [$first, $length] = self::span(count($rows), $folds, $fold);

        return [...array_slice($rows, 0, $first), ...array_slice($rows, $first + $length)];
    }

    /**
     * Instances.testCV(): fold $fold.
     *
     * @template T
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function test(array $rows, int $folds, int $fold): array
    {
        [$first, $length] = self::span(count($rows), $folds, $fold);

        return array_slice($rows, $first, $length);
    }

    /** @return array{0: int, 1: int} first row and length of a fold */
    private static function span(int $count, int $folds, int $fold): array
    {
        $size = intdiv($count, $folds);
        $extra = $count % $folds;

        return [$fold * $size + min($fold, $extra), $size + ($fold < $extra ? 1 : 0)];
    }
}
