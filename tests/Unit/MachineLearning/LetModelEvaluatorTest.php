<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LetPredictionService;
use Tests\TestCase;

/**
 * LetModelEvaluator scores a training set by 10-fold cross-validation, seed 1,
 * as Weka does: accuracy, Cohen's Kappa, recall on FAILED, and the baseline of
 * always answering PASSED. (Agreement with Weka itself is checked by the tools
 * in ml/tools; see ml/README.md.)
 */
class LetModelEvaluatorTest extends TestCase
{
    private function evaluator(): LetModelEvaluator
    {
        return new LetModelEvaluator(new DecisionTreeTrainer(), new LetPredictionService());
    }

    private function row(float $selection, string $label): array
    {
        return ['features' => ['selection' => $selection], 'label' => $label];
    }

    public function test_a_separable_set_scores_as_weka_would_including_its_one_miss(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = $this->row(20 + $i, LetPredictionService::RESULT_FAIL);
            $rows[] = $this->row(70 + $i, LetPredictionService::RESULT_PASS);
        }

        $scores = $this->evaluator()->evaluate($rows, ['selection']);

        // J48 cuts at the highest training value on the low side, not midway.
        // In the fold that holds out the top failing score (39), the cut is at
        // 38, so 39 is read as passing: one miss in forty.
        $this->assertSame(40, $scores['rows']);
        $this->assertSame(20, $scores['failed']);
        $this->assertSame(97.5, $scores['accuracy']);
        $this->assertSame(0.95, $scores['kappa']);
        $this->assertEquals(95.0, $scores['failed_recall']);
        $this->assertEquals(50.0, $scores['baseline']);
        $this->assertSame([
            LetPredictionService::RESULT_FAIL => [LetPredictionService::RESULT_FAIL => 19, LetPredictionService::RESULT_PASS => 1],
            LetPredictionService::RESULT_PASS => [LetPredictionService::RESULT_FAIL => 0, LetPredictionService::RESULT_PASS => 20],
        ], $scores['matrix']);
    }

    public function test_a_set_with_no_failures_has_no_failed_recall(): void
    {
        $rows = array_map(fn ($i) => $this->row(50 + $i, LetPredictionService::RESULT_PASS), range(1, 12));

        $scores = $this->evaluator()->evaluate($rows, ['selection']);

        $this->assertNull($scores['failed_recall']);
        $this->assertEquals(100.0, $scores['baseline']);
        $this->assertEquals(100.0, $scores['accuracy']);
    }

    public function test_one_row_cannot_be_cross_validated(): void
    {
        $scores = $this->evaluator()->evaluate([$this->row(50, LetPredictionService::RESULT_PASS)], ['selection']);

        $this->assertSame(1, $scores['rows']);
        $this->assertSame(0.0, $scores['accuracy']);
    }
}
