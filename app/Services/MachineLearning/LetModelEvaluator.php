<?php

namespace App\Services\MachineLearning;

/**
 * Judges the Estimated Outcome model the way the paper does: stratified
 * 10-fold cross-validation repeated with seeds 1 to 10, dealt into folds
 * exactly as WEKA's Evaluation.crossValidateModel() deals them (shuffle with
 * java.util.Random(seed), stratify, and each fold's training rows reshuffled
 * with that same Random). Inside every training fold the ridge is chosen
 * again (RidgeSelector), since the whole CVParameterSelection is what is
 * being judged; no row is ever predicted by a model that saw it.
 *
 * Each repeat's predictions are pooled, as WEKA's Explorer reports one run,
 * and the figures are the mean over the ten repeats:
 *
 *   - ROC area, from WEKA's own threshold curve (ThresholdCurve);
 *   - at the at-risk cutoff (P(FAILED) >= 0.25): FAILED recall, the false
 *     alarm rate (passers flagged at risk), FAILED precision and F-measure,
 *     accuracy, Kappa and the confusion matrix;
 *   - the same for ZeroR (always the training majority, PASSED) on the same
 *     folds, the baseline the model has to beat.
 *
 * The same figures are also worked out for each kind of row on its own
 * (`by_type`): the review rows (pre-tests and mock boards) and the battery
 * semesters. Each comes from the same pooled predictions, only counted over
 * that kind of row, so with no battery rows the review figures are the
 * figures. A version is judged on its review rows, and battery estimates on
 * its battery rows (LetModelTrainer).
 */
class LetModelEvaluator
{
    public const FOLDS = 10;

    public const REPEATS = 10;

    public function __construct(
        private readonly RidgeSelector $selector,
        private readonly LogisticRegressionTrainer $trainer,
    ) {
    }

    /** The kinds of row figures are also given for, apart (`by_type`). */
    public const ROW_KINDS = ['review', 'battery'];

    /**
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<string>|null  $examTypes  how the exam type is coded (LogisticModel::examTypesFor() of $rows if left out)
     * @return array{rows: int, failed_rows: int, cutoff_p_failed: float, folds: int, repeats: int, exam_types: list<string>, model: array<string, mixed>, zero_r: array<string, mixed>, per_repeat: list<array<string, mixed>>, by_type: array{review: ?array<string, mixed>, battery: ?array<string, mixed>}}
     */
    public function evaluate(array $rows, ?array $examTypes = null): array
    {
        $rows = array_values($rows);
        $examTypes ??= LogisticModel::examTypesFor($rows);
        $model = [];
        $zeroR = [];
        $perRepeat = [];
        $byKind = array_fill_keys(self::ROW_KINDS, ['model' => [], 'zero_r' => []]);

        for ($seed = 1; $seed <= self::REPEATS; $seed++) {
            [$modelPredictions, $zeroRPredictions] = $this->repeat($rows, $seed, $examTypes);
            $modelFigures = self::figures($modelPredictions);
            $perRepeat[] = ['seed' => $seed] + $modelFigures;
            $model[] = $modelFigures;
            $zeroR[] = self::figures($zeroRPredictions);

            foreach (self::ROW_KINDS as $kind) {
                $mine = static fn (array $prediction): bool => $prediction[2] === $kind;
                $kindModel = array_values(array_filter($modelPredictions, $mine));

                if ($kindModel !== []) {
                    $byKind[$kind]['model'][] = self::figures($kindModel);
                    $byKind[$kind]['zero_r'][] = self::figures(array_values(array_filter($zeroRPredictions, $mine)));
                }
            }
        }

        $failed = static fn (array $row): bool => $row['label'] === LetPredictionService::RESULT_FAIL;
        $byType = [];
        foreach (self::ROW_KINDS as $kind) {
            $kindRows = array_filter($rows, static fn (array $row): bool => self::kindOf($row) === $kind);
            $byType[$kind] = $byKind[$kind]['model'] === [] ? null : [
                'rows' => count($kindRows),
                'failed_rows' => count(array_filter($kindRows, $failed)),
                'model' => $this->mean($byKind[$kind]['model']),
                'zero_r' => $this->mean($byKind[$kind]['zero_r']),
            ];
        }

        return [
            'rows' => count($rows),
            'failed_rows' => count(array_filter($rows, $failed)),
            'cutoff_p_failed' => LogisticModel::AT_RISK_CUTOFF,
            'folds' => self::FOLDS,
            'repeats' => self::REPEATS,
            'exam_types' => $examTypes,
            'model' => $this->mean($model),
            'zero_r' => $this->mean($zeroR),
            'per_repeat' => $perRepeat,
            'by_type' => $byType,
        ];
    }

    /** A battery semester's row, or a review exam's (pre-test or mock board). */
    public static function kindOf(array $row): string
    {
        return LogisticModel::normalizeExamType($row['features']['exam_type'] ?? null) === LogisticModel::BATTERY_TYPE
            ? 'battery'
            : 'review';
    }

    /**
     * One repeat: each held-out row's P(FAILED) from the model and from
     * ZeroR, pooled across the ten folds, each with its kind of row.
     *
     * @param  list<array{features: array<string, mixed>, label: string}>  $rows
     * @param  list<string>  $examTypes
     * @return array{0: list<array{0: float, 1: bool, 2: string}>, 1: list<array{0: float, 1: bool, 2: string}>}
     */
    private function repeat(array $rows, int $seed, array $examTypes): array
    {
        $random = new JavaRandom($seed);
        $rows = WekaFolds::stratify(WekaFolds::randomize($rows, $random), self::FOLDS);
        $model = [];
        $zeroR = [];

        for ($fold = 0; $fold < self::FOLDS; $fold++) {
            $train = WekaFolds::randomize(WekaFolds::train($rows, self::FOLDS, $fold), $random);
            $test = WekaFolds::test($rows, self::FOLDS, $fold);

            $fitted = $this->trainer->fit($train, $this->selector->select($train, $examTypes)['ridge'], $examTypes);

            // ZeroR: the class counts of the training rows, each started at 1.
            $failed = count(array_filter($train, static fn (array $row): bool => $row['label'] === LetPredictionService::RESULT_FAIL));
            $priorFailed = (1.0 + $failed) / (2.0 + count($train));

            foreach ($test as $row) {
                $isFailed = $row['label'] === LetPredictionService::RESULT_FAIL;
                $kind = self::kindOf($row);
                $model[] = [$fitted->probabilityOfFailing($row['features']), $isFailed, $kind];
                $zeroR[] = [$priorFailed, $isFailed, $kind];
            }
        }

        return [$model, $zeroR];
    }

    /**
     * @param  list<array{0: float, 1: bool}>  $predictions  P(FAILED) and whether the row FAILED (anything after those two is ignored)
     * @return array{roc_area: float, failed_recall: float, false_alarm_rate: float, failed_precision: float, failed_f_measure: float, accuracy: float, kappa: float, confusion: array{failed_at_risk: float, failed_not_at_risk: float, passed_at_risk: float, passed_not_at_risk: float}}
     */
    public static function figures(array $predictions): array
    {
        $tp = $fn = $fp = $tn = 0.0;

        foreach ($predictions as [$pFailed, $isFailed]) {
            $atRisk = $pFailed >= LogisticModel::AT_RISK_CUTOFF;
            if ($isFailed && $atRisk) {
                $tp++;
            } elseif ($isFailed) {
                $fn++;
            } elseif ($atRisk) {
                $fp++;
            } else {
                $tn++;
            }
        }

        $n = $tp + $fn + $fp + $tn;
        $recall = $tp + $fn > 0 ? $tp / ($tp + $fn) : 0.0;
        $precision = $tp + $fp > 0 ? $tp / ($tp + $fp) : 0.0;
        $observed = ($tp + $tn) / $n;
        $chance = (($tp + $fn) * ($tp + $fp) + ($fp + $tn) * ($fn + $tn)) / ($n * $n);

        return [
            'roc_area' => self::rocArea($predictions),
            'failed_recall' => $recall,
            'false_alarm_rate' => $fp + $tn > 0 ? $fp / ($fp + $tn) : 0.0,
            'failed_precision' => $precision,
            'failed_f_measure' => $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0,
            'accuracy' => $observed,
            'kappa' => $chance < 1 ? ($observed - $chance) / (1 - $chance) : 0.0,
            'confusion' => [
                'failed_at_risk' => $tp,
                'failed_not_at_risk' => $fn,
                'passed_at_risk' => $fp,
                'passed_not_at_risk' => $tn,
            ],
        ];
    }

    /**
     * The area under WEKA's ROC curve for class FAILED
     * (ThresholdCurve.getCurve() then getROCArea()): one point per distinct
     * P(FAILED), lowest first, and the trapezoids between them.
     *
     * @param  list<array{0: float, 1: bool}>  $predictions
     */
    public static function rocArea(array $predictions): float
    {
        $totalPos = 0.0;
        $totalNeg = 0.0;
        foreach ($predictions as [, $isFailed]) {
            $isFailed ? $totalPos++ : $totalNeg++;
        }

        if ($predictions === [] || $totalPos == 0.0 || $totalNeg == 0.0) {
            return 0.5;
        }

        usort($predictions, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $tp = $totalPos;
        $fn = 0.0;
        $fp = $totalNeg;
        $tn = 0.0;
        $threshold = 0.0;
        $cumulativePos = 0.0;
        $cumulativeNeg = 0.0;
        $points = [];
        $last = count($predictions) - 1;

        foreach ($predictions as $index => [$probability, $isFailed]) {
            if ($index === 0 || $probability > $threshold) {
                $tp -= $cumulativePos;
                $fn += $cumulativePos;
                $fp -= $cumulativeNeg;
                $tn += $cumulativeNeg;
                $threshold = $probability;
                $points[] = [$tp, $fp];
                $cumulativePos = 0.0;
                $cumulativeNeg = 0.0;

                if ($index === $last) {
                    break;
                }
            }

            $isFailed ? $cumulativePos++ : $cumulativeNeg++;
        }

        if ($fn != $totalPos || $tn != $totalNeg) {
            $points[] = [0.0, 0.0];
        }

        $area = 0.0;
        $cumulativeNegative = 0.0;
        $count = count($points);

        for ($i = 0; $i < $count; $i++) {
            if ($i < $count - 1) {
                $cip = $points[$i][0] - $points[$i + 1][0];
                $cin = $points[$i][1] - $points[$i + 1][1];
            } else {
                $cip = $points[$count - 1][0];
                $cin = $points[$count - 1][1];
            }

            $area += $cip * ($cumulativeNegative + (0.5 * $cin));
            $cumulativeNegative += $cin;
        }

        return $area / ($totalNeg * $totalPos);
    }

    /**
     * @param  list<array<string, mixed>>  $repeats
     * @return array<string, mixed>
     */
    private function mean(array $repeats): array
    {
        $mean = [];

        foreach ($repeats[0] as $key => $value) {
            if (is_array($value)) {
                foreach (array_keys($value) as $inner) {
                    $mean[$key][$inner] = array_sum(array_map(static fn (array $repeat): float => $repeat[$key][$inner], $repeats)) / count($repeats);
                }
            } else {
                $mean[$key] = array_sum(array_column($repeats, $key)) / count($repeats);
            }
        }

        return $mean;
    }
}
