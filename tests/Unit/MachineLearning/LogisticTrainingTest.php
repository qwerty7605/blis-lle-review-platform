<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\JavaRandom;
use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LogisticModel;
use App\Services\MachineLearning\LogisticRegressionTrainer;
use App\Services\MachineLearning\RidgeSelector;
use App\Services\MachineLearning\WekaFolds;
use Tests\TestCase;

/**
 * Retraining in PHP reproduces WEKA 3.8.6: on the base dataset the ridge
 * CVParameterSelection picks, every candidate's cross-validated error rate,
 * the coefficients (to 1e-4) and the 10 x 10-fold evaluation all match
 * ml/models/logistic-v1.json, which WEKA wrote. More datasets are checked by
 * `php ml/tools/compare_logistic.php check`.
 */
class LogisticTrainingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function weka(): array
    {
        return json_decode((string) file_get_contents(base_path('ml/models/logistic-v1.json')), true);
    }

    public function test_the_trainer_reproduces_version_one_and_its_choice_of_ridge(): void
    {
        $rows = (new LetBaseDataset())->rows();
        $weka = $this->weka();
        $fit = app(RidgeSelector::class)->fit($rows);
        $wekaModel = LogisticModel::fromWekaExport($weka);

        $this->assertSame(0.5, $fit['selection']['ridge']);
        $this->assertCount(20, $fit['selection']['candidates']);
        foreach ($weka['candidates'] as $index => $candidate) {
            $this->assertEqualsWithDelta($candidate['ridge'], $fit['selection']['candidates'][$index]['ridge'], 1e-12);
            $this->assertEqualsWithDelta($candidate['error_rate'], $fit['selection']['candidates'][$index]['error_rate'], 1e-12, "ridge {$candidate['ridge']}");
        }

        // 0.5, 1.0 and 2.5 tie at 18 of 128 wrong; a tie keeps the first.
        $this->assertEqualsWithDelta(18 / 128, $fit['selection']['candidates'][0]['error_rate'], 1e-12);
        $this->assertEqualsWithDelta(18 / 128, $fit['selection']['candidates'][1]['error_rate'], 1e-12);

        $this->assertEqualsWithDelta($wekaModel->intercept, $fit['model']->intercept, 1e-4);
        foreach ($wekaModel->inputs as $key => $input) {
            $this->assertEqualsWithDelta($input['coefficient'], $fit['model']->inputs[$key]['coefficient'], 1e-4, $key);
            $this->assertEqualsWithDelta($input['mean'], $fit['model']->inputs[$key]['mean'], 1e-9, $key);
            $this->assertEqualsWithDelta($input['sd'], $fit['model']->inputs[$key]['sd'], 1e-9, $key);
            $this->assertEqualsWithDelta($input['fill'], $fit['model']->inputs[$key]['fill'], 1e-9, $key);
        }
    }

    public function test_the_evaluation_reproduces_weka_s_ten_repeats_of_ten_folds(): void
    {
        $evaluation = app(LetModelEvaluator::class)->evaluate((new LetBaseDataset())->rows());
        $weka = $this->weka()['evaluation'];

        $this->assertSame(128, $evaluation['rows']);
        $this->assertSame(27, $evaluation['failed_rows']);

        foreach (['model', 'zero_r'] as $which) {
            foreach (['roc_area', 'failed_recall', 'false_alarm_rate', 'failed_precision', 'failed_f_measure', 'accuracy', 'kappa'] as $figure) {
                $this->assertEqualsWithDelta($weka[$which][$figure], $evaluation[$which][$figure], 1e-9, "{$which} {$figure}");
            }
            foreach ($weka[$which]['confusion'] as $cell => $value) {
                $this->assertEqualsWithDelta($value, $evaluation[$which]['confusion'][$cell], 1e-9, "{$which} {$cell}");
            }
        }

        foreach ($weka['per_repeat'] as $index => $repeat) {
            $this->assertEqualsWithDelta($repeat['roc_area'], $evaluation['per_repeat'][$index]['roc_area'], 1e-9, "seed {$repeat['seed']}");
        }

        // Objective 3's targets, met by version 1.
        $this->assertGreaterThanOrEqual(0.70, $evaluation['model']['roc_area']);
        $this->assertGreaterThanOrEqual(0.60, $evaluation['model']['failed_recall']);
        $this->assertGreaterThan($evaluation['zero_r']['kappa'], $evaluation['model']['kappa']);
    }

    public function test_the_ridge_candidates_are_stepped_as_weka_steps_them(): void
    {
        $candidates = RidgeSelector::candidates();

        $this->assertCount(20, $candidates);
        $this->assertSame(0.5, $candidates[0]);
        $this->assertSame(10.0, $candidates[19]);
        $this->assertSame(2.5, $candidates[4]);
    }

    public function test_the_roc_area_counts_ties_as_half_as_weka_s_curve_does(): void
    {
        // FAILED rows score 0.9 and 0.4; PASSED rows 0.4 and 0.1. Of the four
        // pairs, three rank the failed row higher and one is a tie: 3.5 / 4.
        $predictions = [[0.9, true], [0.4, true], [0.4, false], [0.1, false]];
        $this->assertEqualsWithDelta(0.875, LetModelEvaluator::rocArea($predictions), 1e-12);

        // Every score the same: no better than chance.
        $this->assertEqualsWithDelta(0.5, LetModelEvaluator::rocArea([[0.2, true], [0.2, false], [0.2, false]]), 1e-12);
    }

    public function test_figures_at_the_cutoff_follow_their_definitions(): void
    {
        // Two who failed (one flagged), three who passed (one flagged).
        $figures = LetModelEvaluator::figures([[0.6, true], [0.1, true], [0.3, false], [0.2, false], [0.05, false]]);

        $this->assertSame(0.5, $figures['failed_recall']);
        $this->assertEqualsWithDelta(1 / 3, $figures['false_alarm_rate'], 1e-12);
        $this->assertSame(0.5, $figures['failed_precision']);
        $this->assertSame(0.6, $figures['accuracy']);
        $this->assertEqualsWithDelta((0.6 - 0.52) / 0.48, $figures['kappa'], 1e-12);
        $this->assertSame(['failed_at_risk' => 1.0, 'failed_not_at_risk' => 1.0, 'passed_at_risk' => 1.0, 'passed_not_at_risk' => 2.0], $figures['confusion']);
    }

    public function test_folds_are_dealt_the_way_weka_deals_them(): void
    {
        $rows = array_map(fn (int $i): array => ['id' => $i, 'label' => $i % 3 === 0 ? 'FAIL' : 'PASS'], range(1, 12));
        $dealt = WekaFolds::stratify(WekaFolds::randomize($rows, new JavaRandom(1)), 3);

        // Every row once, each fold with its share of both outcomes.
        $this->assertEqualsCanonicalizing(range(1, 12), array_column($dealt, 'id'));
        for ($fold = 0; $fold < 3; $fold++) {
            $test = WekaFolds::test($dealt, 3, $fold);
            $this->assertCount(4, $test);
            $this->assertCount(8, WekaFolds::train($dealt, 3, $fold));
            $labels = array_column($test, 'label');
            $this->assertContains('FAIL', $labels, "fold {$fold}");
            $this->assertContains('PASS', $labels, "fold {$fold}");
        }
    }

    public function test_a_constant_input_is_left_out_as_weka_s_remove_useless_does(): void
    {
        $rows = [];
        foreach ([[40, 'FAIL'], [45, 'FAIL'], [70, 'PASS'], [80, 'PASS'], [60, 'PASS'], [50, 'FAIL']] as $index => [$score, $label]) {
            $rows[] = ['features' => ['exam_type' => 'pre_test', 'management' => $score, 'reference' => 50.0 + $index, 'indexing' => 60.0, 'selection' => null, 'cataloging' => 55.0 - $index, 'it' => 50.0], 'label' => $label];
        }

        $model = (new LogisticRegressionTrainer())->fit($rows, 1.0);

        // Exam type, indexing, IT and the never-recorded selection are the same for everyone.
        $this->assertSame(['management', 'reference', 'cataloging'], array_keys($model->inputs));
        $this->assertLessThan(0.5, $model->probabilityOfFailing(['management' => 85, 'reference' => 52, 'cataloging' => 53]));
    }
}
