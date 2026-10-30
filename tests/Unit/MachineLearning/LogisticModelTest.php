<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LogisticModel;
use Tests\TestCase;

/**
 * Version 1 of the Estimated Outcome model, as the app computes it, against
 * the probabilities WEKA 3.8.6 itself gave for the same inputs
 * (ml/tools/logistic_vectors.csv, written by LleLogistic.java). The full
 * check of all 410 vectors is `php ml/tools/compare_logistic.php vectors`.
 */
class LogisticModelTest extends TestCase
{
    private function versionOne(): LogisticModel
    {
        return LogisticModel::fromWekaExport(json_decode((string) file_get_contents(base_path('ml/models/logistic-v1.json')), true));
    }

    /**
     * @param  list<?float>  $scores  in the training file's order: management, reference, indexing, selection, cataloging, it
     */
    private function features(string $type, array $scores): array
    {
        return ['exam_type' => $type] + array_combine(LogisticModel::SUBJECTS, $scores);
    }

    public function test_it_gives_weka_s_probabilities(): void
    {
        $model = $this->versionOne();
        $cases = [
            [['pre_test', [0, 0, 0, 0, 0, 0]], 0.00014301224694917352],
            [['pre_test', [100, 100, 100, 100, 100, 100]], 0.99997362902765790],
            [['pre_test', [50, 50, 50, 50, 50, 50]], 0.69959951679085470],
            [['pre_test', [30, 90, 30, 90, 30, 90]], 0.99955266796728690],
            [['mock_board', [50, 50, 50, 50, 50, 50]], 0.16716283420976788],
            [['mock_board', [71.2, 42.4, 36.2, 41.1, 97.6, 52.2]], 0.026393485354486358],
            [['mock_board', [45.8, 78.6, 26.6, 59.4, 10.2, 16.9]], 0.46274470532636450],
            // Missing scores are filled with the training means, as WEKA fills them.
            [['pre_test', [null, null, null, null, null, null]], 0.95262763563923400],
            [['mock_board', [null, null, null, null, null, null]], 0.63411791070354600],
            [['pre_test', [25.9, 40.4, null, 93.9, 55.2, 9.8]], 0.96374534504634390],
            [['mock_board', [89.0, null, 6.4, 70.1, null, 46.1]], 0.80492560861714960],
        ];

        foreach ($cases as [[$type, $scores], $weka]) {
            $this->assertEqualsWithDelta($weka, $model->probabilityOfPassing($this->features($type, $scores)), 1e-12, json_encode($scores));
            $this->assertEqualsWithDelta(1 - $weka, $model->probabilityOfFailing($this->features($type, $scores)), 1e-12);
        }
    }

    public function test_version_one_is_the_one_trained_in_weka(): void
    {
        $model = $this->versionOne();

        $this->assertSame(0.5, $model->ridge);
        $this->assertSame(LogisticModel::INPUTS, array_keys($model->inputs));
        $this->assertEqualsWithDelta(8.8524372658961300, $model->intercept, 1e-12);
        $this->assertEqualsWithDelta(2.4512612753169400, $model->inputs['exam_type']['coefficient'], 1e-12);
        $this->assertEqualsWithDelta(-0.11348967018418477, $model->inputs['selection']['coefficient'], 1e-12);
        // A missing exam type is the more common one in training: pre-test.
        $this->assertSame(0.0, $model->inputs['exam_type']['fill']);
    }

    public function test_at_risk_is_a_chance_of_failing_of_25_percent_or_more(): void
    {
        $atTheLine = new LogisticModel(log(0.25 / 0.75), [], 0.5);   // P(FAILED) = 0.25 exactly
        $justUnder = new LogisticModel(log(0.2499 / 0.7501), [], 0.5);

        $this->assertEqualsWithDelta(0.25, $atTheLine->probabilityOfFailing([]), 1e-15);
        $this->assertTrue($atTheLine->isAtRisk([]));
        $this->assertFalse($justUnder->isAtRisk([]));
    }

    public function test_it_survives_being_stored_and_read_back(): void
    {
        $model = $this->versionOne();
        $copy = LogisticModel::fromArray(json_decode((string) json_encode($model->toArray()), true));
        $features = $this->features('mock_board', [80, 70, 60, 50, 40, 30]);

        $this->assertSame($model->probabilityOfPassing($features), $copy->probabilityOfPassing($features));
        $this->assertSame(1.0, LogisticModel::encodeExamType('mock_board'));
        $this->assertSame(0.0, LogisticModel::encodeExamType('pretest'));
        $this->assertNull(LogisticModel::encodeExamType('battery'));
    }
}
