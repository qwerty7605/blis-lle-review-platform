<?php

namespace App\Services\MachineLearning;

use RuntimeException;

/**
 * WEKA 3.8.6's Logistic.buildClassifier() for the Estimated Outcome rows, in
 * PHP (the server has no Java):
 *
 *   1. ReplaceMissingValues: a missing score becomes the mean of the scores
 *      present; a missing exam type the most common type (the first declared
 *      on a tie: pre-test);
 *   2. RemoveUseless: an input with fewer than two distinct values is
 *      dropped (and the exam type if it varies too much, >99% distinct);
 *   3. NominalToBinary: with the two review types the exam type is one input,
 *      1 for a mock board and 0 for a pre-test; with three (battery rows in
 *      the set) one 0/1 input per type, in the declared order;
 *   4. each input standardized with its mean and sample standard deviation
 *      (n - 1), computed after step 1;
 *   5. the coefficients that minimise the negative log-likelihood plus
 *      ridge x (sum of squared standardized coefficients), the intercept not
 *      penalized, for class FAILED against PASSED;
 *   6. the coefficients turned back into the inputs' own units.
 *
 * WEKA finds step 5 with a quasi-Newton search that stops at its own
 * tolerance; here Newton's method runs until the steps vanish. The function
 * is strictly convex for a positive ridge, so both land on the same
 * minimum; ml/tools checks they agree to well within 1e-4.
 */
class LogisticRegressionTrainer
{
    private const MAX_ITERATIONS = 100;

    private const TOLERANCE = 1e-12;

    /**
     * $examTypes are the types the whole training set is coded with
     * (LogisticModel::examTypesFor() of every row): a cross-validation fold
     * is coded as its dataset is, as WEKA's folds share their file's header.
     * Left out, they are read from $rows.
     *
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<string>|null  $examTypes
     */
    public function fit(array $rows, float $ridge, ?array $examTypes = null): LogisticModel
    {
        return $this->fitFrom($rows, $ridge, null, $examTypes)['model'];
    }

    /**
     * fit(), starting the search at $start (the standardized coefficients of
     * a neighbouring fit) when given. The minimum is the same; only the
     * number of steps to it changes.
     *
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<float>|null  $start
     * @param  list<string>|null  $examTypes
     * @return array{model: LogisticModel, beta: list<float>}
     */
    public function fitFrom(array $rows, float $ridge, ?array $start = null, ?array $examTypes = null): array
    {
        $count = count($rows);
        $examTypes ??= LogisticModel::examTypesFor($rows);
        $oneInputPerType = count($examTypes) > 2;

        if ($count < 2) {
            throw new RuntimeException('A logistic model needs at least two training rows.');
        }

        $y = [];
        foreach ($rows as $row) {
            $y[] = $row['label'] === LetPredictionService::RESULT_FAIL ? 1.0 : 0.0;
        }

        // 1. Fill what is missing.
        $columns = [];
        $fill = [];
        foreach (LogisticModel::INPUTS as $key) {
            if ($key === 'exam_type' && $oneInputPerType) {
                // Filled, judged by RemoveUseless and split into one input per
                // type, all three steps on the type itself.
                [$typeColumns, $typeFill] = $this->examTypeColumns($rows, $examTypes);
                $columns += $typeColumns;
                $fill += $typeFill;

                continue;
            }

            $values = [];
            foreach ($rows as $row) {
                $values[] = $key === 'exam_type'
                    ? LogisticModel::encodeExamType($row['features']['exam_type'] ?? null)
                    : (is_numeric($row['features'][$key] ?? null) ? (float) $row['features'][$key] : null);
            }

            $fill[$key] = $key === 'exam_type' ? $this->mode($values) : $this->mean($values);
            $columns[$key] = array_map(static fn (?float $value): float => $value ?? $fill[$key], $values);
        }

        // 2. Drop what cannot inform the model.
        foreach ($columns as $key => $column) {
            if (str_starts_with($key, LogisticModel::TYPE_INPUT_PREFIX)) {
                continue; // judged on the type as a whole, in examTypeColumns()
            }

            $distinct = count(array_unique(array_map(static fn (float $value): string => (string) $value, $column)));
            $tooVaried = $key === 'exam_type' && ($distinct / $count) * 100.0 > 99.0;

            if ($distinct < 2 || $tooVaried) {
                unset($columns[$key]);
            }
        }

        // 4. Standardize.
        $keys = array_keys($columns);
        $means = [];
        $sds = [];
        $x = array_fill(0, $count, [1.0]);

        foreach ($keys as $key) {
            $sum = 0.0;
            $squares = 0.0;
            foreach ($columns[$key] as $value) {
                $sum += $value;
                $squares += $value * $value;
            }

            $mean = $sum / $count;
            $sd = sqrt(abs($squares - $count * $mean * $mean) / ($count - 1));
            $means[$key] = $mean;
            $sds[$key] = $sd;

            foreach ($columns[$key] as $index => $value) {
                $x[$index][] = $sd != 0.0 ? ($value - $mean) / $sd : $value;
            }
        }

        // 5. Minimise.
        $failed = array_sum($y);
        $beta = $start !== null && count($start) === count($keys) + 1
            ? $start
            : [log($failed + 1.0) - log(($count - $failed) + 1.0), ...array_fill(0, count($keys), 0.0)];
        $beta = $this->minimise($x, $y, $ridge, $beta);

        // 6. Back to the inputs' own units.
        $intercept = $beta[0];
        $inputs = [];
        foreach ($keys as $position => $key) {
            $coefficient = $beta[$position + 1];

            if ($sds[$key] != 0.0) {
                $coefficient /= $sds[$key];
                $intercept -= $coefficient * $means[$key];
            }

            $inputs[$key] = ['coefficient' => $coefficient, 'fill' => $fill[$key], 'mean' => $means[$key], 'sd' => $sds[$key]];
        }

        return ['model' => new LogisticModel($intercept, $inputs, $ridge), 'beta' => $beta];
    }

    /**
     * Newton's method on f(b) = sum[log(1 + e^z) - y z] + ridge * sum(b_r^2),
     * z = x . b, r over everything but the intercept; a step that does not
     * lower f is halved.
     *
     * @param  list<list<float>>  $x
     * @param  list<float>  $y
     * @param  list<float>  $beta
     * @return list<float>
     */
    private function minimise(array $x, array $y, float $ridge, array $beta): array
    {
        $dimension = count($beta);
        $current = $this->objective($x, $y, $ridge, $beta);

        for ($iteration = 0; $iteration < self::MAX_ITERATIONS; $iteration++) {
            $gradient = array_fill(0, $dimension, 0.0);
            $hessian = array_fill(0, $dimension, array_fill(0, $dimension, 0.0));

            foreach ($x as $index => $row) {
                $z = 0.0;
                for ($j = 0; $j < $dimension; $j++) {
                    $z += $row[$j] * $beta[$j];
                }

                $p = 1.0 / (1.0 + exp(-$z));
                $residual = $p - $y[$index];
                $weight = $p * (1.0 - $p);

                for ($j = 0; $j < $dimension; $j++) {
                    $gradient[$j] += $residual * $row[$j];
                    $scaled = $weight * $row[$j];
                    for ($k = 0; $k <= $j; $k++) {
                        $hessian[$j][$k] += $scaled * $row[$k];
                    }
                }
            }

            for ($j = 0; $j < $dimension; $j++) {
                for ($k = 0; $k < $j; $k++) {
                    $hessian[$k][$j] = $hessian[$j][$k];
                }
                if ($j > 0) {
                    $gradient[$j] += 2.0 * $ridge * $beta[$j];
                    $hessian[$j][$j] += 2.0 * $ridge;
                }
            }

            $step = $this->solve($hessian, $gradient);
            $scale = 1.0;

            do {
                $candidate = [];
                for ($j = 0; $j < $dimension; $j++) {
                    $candidate[$j] = $beta[$j] - $scale * $step[$j];
                }
                $value = $this->objective($x, $y, $ridge, $candidate);
                $scale /= 2.0;
            } while ($value > $current && $scale > 1e-10);

            $largest = 0.0;
            for ($j = 0; $j < $dimension; $j++) {
                $largest = max($largest, abs($candidate[$j] - $beta[$j]));
            }

            $beta = $candidate;
            $current = min($value, $current);

            if ($largest < self::TOLERANCE) {
                break;
            }
        }

        return $beta;
    }

    /**
     * @param  list<list<float>>  $x
     * @param  list<float>  $y
     * @param  list<float>  $beta
     */
    private function objective(array $x, array $y, float $ridge, array $beta): float
    {
        $value = 0.0;
        $dimension = count($beta);

        foreach ($x as $index => $row) {
            $z = 0.0;
            for ($j = 0; $j < $dimension; $j++) {
                $z += $row[$j] * $beta[$j];
            }

            // log(1 + e^z) without overflow.
            $value += ($z > 0 ? $z + log1p(exp(-$z)) : log1p(exp($z))) - $y[$index] * $z;
        }

        for ($j = 1; $j < $dimension; $j++) {
            $value += $ridge * $beta[$j] * $beta[$j];
        }

        return $value;
    }

    /**
     * Gaussian elimination with partial pivoting: a for H a = g.
     *
     * @param  list<list<float>>  $matrix
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function solve(array $matrix, array $vector): array
    {
        $size = count($vector);

        for ($column = 0; $column < $size; $column++) {
            $pivot = $column;
            for ($row = $column + 1; $row < $size; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }

            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];
            [$vector[$column], $vector[$pivot]] = [$vector[$pivot], $vector[$column]];

            $lead = $matrix[$column][$column];
            if ($lead == 0.0) {
                continue;
            }

            for ($row = $column + 1; $row < $size; $row++) {
                $factor = $matrix[$row][$column] / $lead;
                if ($factor == 0.0) {
                    continue;
                }
                for ($k = $column; $k < $size; $k++) {
                    $matrix[$row][$k] -= $factor * $matrix[$column][$k];
                }
                $vector[$row] -= $factor * $vector[$column];
            }
        }

        $solution = array_fill(0, $size, 0.0);
        for ($row = $size - 1; $row >= 0; $row--) {
            $sum = $vector[$row];
            for ($k = $row + 1; $k < $size; $k++) {
                $sum -= $matrix[$row][$k] * $solution[$k];
            }
            $solution[$row] = $matrix[$row][$row] != 0.0 ? $sum / $matrix[$row][$row] : 0.0;
        }

        return $solution;
    }

    /**
     * The exam type of a set with battery rows, as WEKA codes a nominal with
     * three values: a missing or unknown type becomes the most common type
     * (the first declared on a tie, as Utils.maxIndex picks it);
     * RemoveUseless drops the type if fewer than two types are present or
     * nearly every row is a type of its own; NominalToBinary then gives every
     * declared type its own 0/1 input, a type no row has included (its input
     * is all 0, so it is not standardized and keeps a zero coefficient).
     *
     * @param  list<array{features: array<string, mixed>}>  $rows
     * @param  list<string>  $examTypes
     * @return array{0: array<string, list<float>>, 1: array<string, float>}
     */
    private function examTypeColumns(array $rows, array $examTypes): array
    {
        $values = [];
        $counts = array_fill_keys($examTypes, 0);

        foreach ($rows as $row) {
            $type = LogisticModel::normalizeExamType($row['features']['exam_type'] ?? null);
            $type = in_array($type, $examTypes, true) ? $type : null;
            $values[] = $type;

            if ($type !== null) {
                $counts[$type]++;
            }
        }

        $mode = $examTypes[0];
        foreach ($examTypes as $type) {
            if ($counts[$type] > $counts[$mode]) {
                $mode = $type;
            }
        }

        $values = array_map(static fn (?string $type): string => $type ?? $mode, $values);
        $present = count(array_unique($values));

        if ($present < 2 || ($present / count($values)) * 100.0 > 99.0) {
            return [[], []];
        }

        $columns = [];
        $fill = [];
        foreach ($examTypes as $type) {
            $key = LogisticModel::typeInputKey($type);
            $columns[$key] = array_map(static fn (string $value): float => $value === $type ? 1.0 : 0.0, $values);
            $fill[$key] = $mode === $type ? 1.0 : 0.0;
        }

        return [$columns, $fill];
    }

    /** @param  list<?float>  $values */
    private function mean(array $values): float
    {
        $present = array_filter($values, static fn (?float $value): bool => $value !== null);

        return $present === [] ? 0.0 : array_sum($present) / count($present);
    }

    /**
     * The more common value; 0 (pre-test) on a tie, as Utils.maxIndex picks
     * the first.
     *
     * @param  list<?float>  $values
     */
    private function mode(array $values): float
    {
        $ones = count(array_filter($values, static fn (?float $value): bool => $value === 1.0));
        $zeros = count(array_filter($values, static fn (?float $value): bool => $value === 0.0));

        return $ones > $zeros ? 1.0 : 0.0;
    }
}
