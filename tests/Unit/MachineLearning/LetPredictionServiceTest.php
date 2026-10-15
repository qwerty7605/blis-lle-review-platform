<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LetPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LetPredictionService walks the J48 decision tree. The built-in tree is:
 *
 *   selection <= 43: FAILED (13.17/2.08)
 *   selection > 43
 *   |   it <= 32: FAILED (3.79/0.66)
 *   |   it > 32: PASSED (139.04/15.78)
 *
 * Confidence is the share of training weight at the leaf that the leaf got
 * right, e.g. (139.04 - 15.78) / 139.04 = 88.6%.
 */
class LetPredictionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function scores(array $overrides = []): array
    {
        return ['reference' => 60, 'management' => 60, 'indexing' => 60, 'selection' => 60, 'cataloging' => 60, 'it' => 60, 'overall' => 60, ...$overrides];
    }

    public function test_the_model_reads_seven_attributes_in_the_training_file_s_order(): void
    {
        $this->assertSame(
            ['reference', 'management', 'indexing', 'selection', 'cataloging', 'it', 'overall'],
            LetPredictionService::requiredFeatures(),
        );
    }

    public function test_a_low_selection_score_predicts_failing(): void
    {
        $result = (new LetPredictionService())->predict($this->scores(['selection' => 43]), LetPredictionService::builtInTree());

        $this->assertSame(LetPredictionService::RESULT_FAIL, $result['prediction']);
        $this->assertSame(84.2, $result['confidence']);
        $this->assertSame(['selection <= 43 (was 43)'], $result['path']);
        $this->assertSame(13, $result['support']);
        $this->assertSame(2, $result['leaf_errors']);
    }

    public function test_a_good_selection_score_but_a_low_it_score_predicts_failing(): void
    {
        $result = (new LetPredictionService())->predict($this->scores(['selection' => 70, 'it' => 32]), LetPredictionService::builtInTree());

        $this->assertSame(LetPredictionService::RESULT_FAIL, $result['prediction']);
        $this->assertSame(82.5, $result['confidence']);
        $this->assertSame(['selection > 43 (was 70)', 'it <= 32 (was 32)'], $result['path']);
    }

    public function test_good_selection_and_it_scores_predict_passing(): void
    {
        $result = (new LetPredictionService())->predict($this->scores(['selection' => 70, 'it' => 50.5]), LetPredictionService::builtInTree());

        $this->assertSame(LetPredictionService::RESULT_PASS, $result['prediction']);
        $this->assertSame(88.6, $result['confidence']);
        $this->assertSame(139, $result['support']);
        $this->assertSame(['selection > 43 (was 70)', 'it > 32 (was 50.5)'], $result['path']);
    }

    public function test_a_missing_score_is_weighed_down_both_branches_never_read_as_zero(): void
    {
        $result = (new LetPredictionService())->predict($this->scores(['selection' => null, 'it' => 80]), LetPredictionService::builtInTree());

        // Read as zero it would go to FAILED; weighed, most of the training
        // weight lies on the passing side.
        $this->assertSame(LetPredictionService::RESULT_PASS, $result['prediction']);
        $this->assertSame('selection is missing (both sides of 43 weighed)', $result['path'][0]);
        // 13.17 x 13.17/156 on the low side, plus 139.04 x 142.83/156 on the
        // high side, where `it` is known and goes to the PASSED leaf alone.
        $this->assertSame(128, $result['support']);
    }

    public function test_a_tree_with_no_training_weight_gives_no_confidence(): void
    {
        $tree = [
            'attribute' => 'it', 'threshold' => 50.0,
            'le' => ['leaf' => LetPredictionService::RESULT_PASS, 'instances' => 0, 'errors' => 0],
            'gt' => ['leaf' => LetPredictionService::RESULT_FAIL, 'instances' => 0, 'errors' => 0],
        ];

        $result = (new LetPredictionService())->predict($this->scores(['it' => null]), $tree);

        // Nothing to weigh: a tie, which goes to FAILED, the first class.
        $this->assertSame(LetPredictionService::RESULT_FAIL, $result['prediction']);
        $this->assertSame(0.0, $result['confidence']);
    }

    public function test_without_a_retrained_model_the_built_in_tree_is_live(): void
    {
        $live = (new LetPredictionService())->liveModel();

        $this->assertSame('built_in', $live['source']);
        $this->assertSame(LetPredictionService::BUILT_IN_VERSION, $live['version']);
        $this->assertSame(156, $live['trained_on']);
        $this->assertSame(LetPredictionService::builtInTree(), $live['tree']);
    }
}
