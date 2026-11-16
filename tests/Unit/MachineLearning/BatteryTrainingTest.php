<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LogisticModel;
use App\Services\MachineLearning\RidgeSelector;
use Tests\TestCase;

/**
 * The Estimated Outcome model once battery rows are in its training set: the
 * exam type has three values, and WEKA 3.8.6 gives each its own 0/1 input.
 * Against tests/Fixtures/lle_battery_n60.weka.json, which WEKA wrote for
 * lle_battery_n60.arff (60 made-up rows, 24 of them battery; made by
 * `php ml/tools/compare_logistic.php generate` as n60_s3_battery and trained
 * by `LleLogistic compare`): the ridge chosen and every candidate's error
 * rate, the coefficients, the app's P(PASSED) from WEKA's own model, and the
 * 10 x 10-fold figures over every row, the review rows and the battery rows.
 */
class BatteryTrainingTest extends TestCase
{
    /** @return array<string, mixed> */
    private function weka(): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/lle_battery_n60.weka.json')), true);
    }

    /** @return list<array{key: string, features: array<string, mixed>, label: string, source: string}> */
    private function rows(): array
    {
        return (new LetBaseDataset(base_path('tests/Fixtures/lle_battery_n60.arff')))->rows();
    }

    public function test_battery_rows_give_the_exam_type_an_input_per_type_as_weka_does(): void
    {
        $rows = $this->rows();
        $weka = $this->weka();
        $wekaModel = LogisticModel::fromWekaExport($weka);

        $this->assertSame(LogisticModel::ALL_TYPES, LogisticModel::examTypesFor($rows));
        $this->assertSame(['exam_type=pre_test', 'exam_type=mock_board', 'exam_type=battery', ...LogisticModel::SUBJECTS], array_keys($wekaModel->inputs));

        $fit = app(RidgeSelector::class)->fit($rows);

        $this->assertSame((float) $weka['ridge'], $fit['selection']['ridge']);
        foreach ($weka['candidates'] as $index => $candidate) {
            $this->assertEqualsWithDelta($candidate['error_rate'], $fit['selection']['candidates'][$index]['error_rate'], 1e-12, "ridge {$candidate['ridge']}");
        }

        $this->assertSame(array_keys($wekaModel->inputs), array_keys($fit['model']->inputs));
        $this->assertEqualsWithDelta($wekaModel->intercept, $fit['model']->intercept, 1e-4);
        foreach ($wekaModel->inputs as $key => $input) {
            $this->assertEqualsWithDelta($input['coefficient'], $fit['model']->inputs[$key]['coefficient'], 1e-4, $key);
            $this->assertEqualsWithDelta($input['mean'], $fit['model']->inputs[$key]['mean'], 1e-9, $key);
            $this->assertEqualsWithDelta($input['sd'], $fit['model']->inputs[$key]['sd'], 1e-9, $key);
            $this->assertEqualsWithDelta($input['fill'], $fit['model']->inputs[$key]['fill'], 1e-9, $key);
        }

        $this->assertSame(LogisticModel::ALL_TYPES, $fit['model']->examTypes());
    }

    public function test_the_app_gives_weka_s_probabilities_for_every_exam_type_missing_values_included(): void
    {
        $model = LogisticModel::fromWekaExport($this->weka());
        $checked = 0;

        foreach ($this->weka()['predictions'] as $case) {
            $features = ['exam_type' => $case['exam_type'] === null ? null : LogisticModel::normalizeExamType($case['exam_type'])];
            foreach (LogisticModel::SUBJECTS as $position => $subject) {
                $features[$subject] = $case['scores'][$position];
            }

            $this->assertEqualsWithDelta($case['p_passed'], $model->probabilityOfPassing($features), 1e-12, json_encode($case));
            $checked++;
        }

        // The file's 60 rows, then each type and a missing one, with scores and without.
        $this->assertSame(68, $checked);
    }

    public function test_the_figures_over_each_kind_of_row_match_weka_and_add_up_to_the_whole(): void
    {
        $evaluation = app(LetModelEvaluator::class)->evaluate($this->rows());
        $weka = $this->weka()['evaluation'];

        $this->assertSame(36, $evaluation['by_type']['review']['rows']);
        $this->assertSame(24, $evaluation['by_type']['battery']['rows']);

        foreach (['review', 'battery'] as $kind) {
            foreach (['model', 'zero_r'] as $which) {
                foreach (['roc_area', 'failed_recall', 'false_alarm_rate', 'failed_precision', 'failed_f_measure', 'accuracy', 'kappa'] as $figure) {
                    $this->assertEqualsWithDelta($weka['by_type'][$kind][$which][$figure], $evaluation['by_type'][$kind][$which][$figure], 1e-9, "{$kind} {$which} {$figure}");
                }
            }
        }

        foreach (['model', 'zero_r'] as $which) {
            $this->assertEqualsWithDelta($weka[$which]['roc_area'], $evaluation[$which]['roc_area'], 1e-9, "{$which} roc_area");

            // Every held-out row is either kind, so the counts add up.
            foreach ($evaluation[$which]['confusion'] as $cell => $value) {
                $this->assertEqualsWithDelta(
                    $value,
                    $evaluation['by_type']['review'][$which]['confusion'][$cell] + $evaluation['by_type']['battery'][$which]['confusion'][$cell],
                    1e-9,
                    "{$which} {$cell}",
                );
            }
        }
    }

    public function test_without_battery_rows_the_exam_type_stays_the_one_input_it_always_was(): void
    {
        $rows = (new LetBaseDataset())->rows();
        $fit = app(RidgeSelector::class)->fit($rows);

        $this->assertSame(LogisticModel::REVIEW_TYPES, LogisticModel::examTypesFor($rows));
        $this->assertArrayHasKey('exam_type', $fit['model']->inputs);
        $this->assertSame([], array_filter(array_keys($fit['model']->inputs), static fn (string $key): bool => str_starts_with($key, LogisticModel::TYPE_INPUT_PREFIX)));
        $this->assertSame(LogisticModel::REVIEW_TYPES, $fit['model']->examTypes());
    }

    public function test_a_type_a_model_has_no_input_for_is_read_as_missing(): void
    {
        $versionOne = LogisticModel::fromWekaExport(json_decode((string) file_get_contents(base_path('ml/models/logistic-v1.json')), true));
        $scores = array_combine(LogisticModel::SUBJECTS, [70.0, 65.0, 72.0, 60.0, 68.0, 75.0]);

        // A two-type version has no input for battery: as WEKA fills a
        // missing type, it takes the training mode (pre-test).
        $this->assertSame(
            $versionOne->probabilityOfFailing(['exam_type' => null] + $scores),
            $versionOne->probabilityOfFailing(['exam_type' => 'battery'] + $scores),
        );
        $this->assertSame(
            $versionOne->probabilityOfFailing(['exam_type' => 'pre_test'] + $scores),
            $versionOne->probabilityOfFailing(['exam_type' => 'battery'] + $scores),
        );

        // A three-type version reads a battery row by its own input.
        $threeTypes = LogisticModel::fromWekaExport($this->weka());
        $this->assertNotSame(
            $threeTypes->probabilityOfFailing(['exam_type' => 'pre_test'] + $scores),
            $threeTypes->probabilityOfFailing(['exam_type' => 'battery'] + $scores),
        );

        // Stored and read back, it keeps all three.
        $this->assertSame(LogisticModel::ALL_TYPES, LogisticModel::fromArray($threeTypes->toArray())->examTypes());
    }
}
