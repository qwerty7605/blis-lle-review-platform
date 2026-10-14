<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\JavaRandom;
use App\Services\MachineLearning\LetPredictionService;
use PHPUnit\Framework\TestCase;

/**
 * DecisionTreeTrainer (C4.5 / J48, -C 0.25 -M 2) on larger and noisier data
 * than tests/Feature/DecisionTreeTrainerTest: a big node needs a bigger
 * branch before it may split, noise is pruned away, a missing score is
 * shared out by weight, and the same data always gives the same tree.
 * Agreement with Weka's own J48 is checked by ml/tools (see ml/README.md).
 */
class DecisionTreeTrainerUnitTest extends TestCase
{
    /**
     * Rows whose label follows `selection` > 50, with some labels flipped
     * at random (seeded, so the data is the same on every run).
     */
    private function noisyRows(int $count, int $noisePercent, int $seed, bool $withMissing = false): array
    {
        $random = new JavaRandom($seed);
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $selection = 20 + $random->nextInt(70);
            $it = 20 + $random->nextInt(70);
            $label = $selection > 50 ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL;

            if ($random->nextInt(100) < $noisePercent) {
                $label = $label === LetPredictionService::RESULT_PASS ? LetPredictionService::RESULT_FAIL : LetPredictionService::RESULT_PASS;
            }

            $rows[] = [
                'features' => [
                    'selection' => $withMissing && $random->nextInt(10) === 0 ? null : $selection,
                    'it' => $it,
                ],
                'label' => $label,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function leaves(array $node): array
    {
        return isset($node['leaf']) ? [$node] : [...$this->leaves($node['le']), ...$this->leaves($node['gt'])];
    }

    private function depth(array $node): int
    {
        return isset($node['leaf']) ? 0 : 1 + max($this->depth($node['le']), $this->depth($node['gt']));
    }

    public function test_a_large_noisy_set_is_still_split_on_the_score_that_decides_it(): void
    {
        $tree = (new DecisionTreeTrainer())->train($this->noisyRows(1200, 10, 7), ['selection', 'it']);

        $this->assertSame('selection', $tree['attribute']);
        $this->assertEqualsWithDelta(50, $tree['threshold'], 2);

        $predictor = new LetPredictionService();
        $this->assertSame(LetPredictionService::RESULT_PASS, $predictor->predict(['selection' => 80, 'it' => 50], $tree)['prediction']);
        $this->assertSame(LetPredictionService::RESULT_FAIL, $predictor->predict(['selection' => 25, 'it' => 50], $tree)['prediction']);
    }

    public function test_pruning_keeps_noise_from_growing_a_deep_tree(): void
    {
        $tree = (new DecisionTreeTrainer())->train($this->noisyRows(400, 25, 11), ['selection', 'it']);

        // A quarter of the labels are noise; the pruned tree stays small
        // rather than chasing it.
        $this->assertLessThanOrEqual(4, $this->depth($tree));
        $this->assertLessThan(12, count($this->leaves($tree)));
    }

    public function test_every_leaf_s_weight_adds_up_to_the_training_rows(): void
    {
        $rows = $this->noisyRows(300, 15, 3, withMissing: true);

        $tree = (new DecisionTreeTrainer())->train($rows, ['selection', 'it']);

        $total = array_sum(array_column($this->leaves($tree), 'instances'));
        $this->assertEqualsWithDelta(300, $total, 0.01, 'Rows with a missing score are shared out by weight, none lost.');
        foreach ($this->leaves($tree) as $leaf) {
            $this->assertLessThanOrEqual($leaf['instances'] + 1e-9, $leaf['errors']);
        }
    }

    public function test_the_same_noisy_data_always_gives_the_same_tree(): void
    {
        $rows = $this->noisyRows(500, 20, 5, withMissing: true);

        $this->assertSame(
            (new DecisionTreeTrainer())->train($rows, ['selection', 'it']),
            (new DecisionTreeTrainer())->train($rows, ['selection', 'it']),
        );
    }

    public function test_weighted_rows_count_by_their_weight(): void
    {
        $rows = [];
        foreach (range(1, 30) as $i) {
            $rows[] = ['features' => ['selection' => $i], 'label' => 'FAIL', 'weight' => 0.5];
            $rows[] = ['features' => ['selection' => 60 + $i], 'label' => 'PASS', 'weight' => 0.5];
        }

        $tree = (new DecisionTreeTrainer())->train($rows, ['selection']);

        $this->assertEqualsWithDelta(30, array_sum(array_column($this->leaves($tree), 'instances')), 0.01);
    }
}
