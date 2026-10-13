<?php

namespace Tests\Feature;

use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\LetPredictionService;
use Tests\TestCase;

/**
 * The tree-growing half of the self-learning readiness model. Its agreement
 * with Weka's J48 is checked outside the suite (ml/tools/compare_with_weka.php,
 * which needs Java); these pin the behaviour the app relies on.
 */
class DecisionTreeTrainerTest extends TestCase
{
    public function test_it_finds_the_score_that_separates_passes_from_fails(): void
    {
        $rows = [];
        foreach (range(40, 95) as $score) {
            $rows[] = $this->row(['mock_total' => $score, 'sem1' => 100 - $score % 7], $score > 70 ? 'PASS' : 'FAIL');
        }

        $tree = (new DecisionTreeTrainer())->train($rows, ['sem1', 'mock_total']);

        $this->assertSame('mock_total', $tree['attribute']);
        $this->assertEquals(70, $tree['threshold']);
        $this->assertSame('FAIL', $tree['le']['leaf']);
        $this->assertSame('PASS', $tree['gt']['leaf']);

        // The result is the shape the predictor already walks.
        $predictor = new LetPredictionService();
        $this->assertSame('PASS', $predictor->predict(['mock_total' => 88, 'sem1' => 50], $tree)['prediction']);
        $this->assertSame('FAIL', $predictor->predict(['mock_total' => 55, 'sem1' => 50], $tree)['prediction']);
    }

    public function test_a_missing_score_is_left_out_of_the_cut_and_never_read_as_zero(): void
    {
        // Selection decides the outcome. A third of the students have no
        // Selection score at all, and they passed. Read as zero they would all
        // sit below the cut and wreck it.
        $rows = [];
        foreach (range(30, 89) as $score) {
            $rows[] = $this->row(['selection' => $score], $score > 50 ? 'PASS' : 'FAIL');
        }
        foreach (range(1, 20) as $index) {
            $rows[] = ['features' => ['selection' => null], 'label' => 'PASS'];
        }

        $tree = (new DecisionTreeTrainer())->train($rows, ['selection']);

        $this->assertSame('selection', $tree['attribute']);
        $this->assertEquals(50, $tree['threshold']);
        $this->assertSame('FAIL', $tree['le']['leaf']);

        // The students without a score are shared between the two sides, so
        // the 80 rows are all still there, as fractions.
        $this->assertEqualsWithDelta(80.0, $tree['le']['instances'] + $tree['gt']['instances'], 0.001);
        $this->assertGreaterThan(21.0, $tree['le']['instances']);

        // And a student with no score is weighed across both sides.
        $predictor = new LetPredictionService();
        $this->assertSame('FAIL', $predictor->predict(['selection' => 40], $tree)['prediction']);
        $this->assertSame('PASS', $predictor->predict(['selection' => null], $tree)['prediction']);
    }

    public function test_one_outcome_only_gives_a_single_leaf(): void
    {
        $rows = array_map(fn (int $score): array => $this->row(['mock_total' => $score], 'PASS'), range(50, 70));

        $tree = (new DecisionTreeTrainer())->train($rows, ['mock_total']);

        $this->assertSame(['leaf' => 'PASS', 'instances' => 21.0, 'errors' => 0.0], $tree);
    }

    public function test_scores_that_say_nothing_about_the_outcome_are_not_split_on(): void
    {
        // Outcome alternates with no relation to the score: any split would
        // be fitting noise, and pruning has to take it back out.
        $rows = [];
        foreach (range(1, 40) as $index) {
            $rows[] = $this->row(['mock_total' => 50 + ($index * 37) % 41], $index % 2 === 0 ? 'PASS' : 'FAIL');
        }

        $tree = (new DecisionTreeTrainer())->train($rows, ['mock_total']);

        $this->assertArrayHasKey('leaf', $tree);
        $this->assertSame(40.0, $tree['instances']);
        $this->assertSame(20.0, $tree['errors']);
    }

    public function test_the_same_records_always_give_the_same_tree(): void
    {
        $rows = [];
        foreach (range(1, 60) as $index) {
            $it = 40 + ($index * 13) % 55;
            $reference = 40 + ($index * 29) % 55;
            $rows[] = $this->row(['mock_it' => $it, 'mock_reference' => $reference], $it + $reference > 130 ? 'PASS' : 'FAIL');
        }

        $trainer = new DecisionTreeTrainer();
        $first = $trainer->train($rows, ['mock_it', 'mock_reference']);

        $this->assertSame($first, $trainer->train(array_reverse($rows), ['mock_it', 'mock_reference']));
        $this->assertArrayHasKey('attribute', $first);
    }

    /**
     * @param  array<string, int|float>  $features
     * @return array{features: array<string, float>, label: string}
     */
    private function row(array $features, string $label): array
    {
        return ['features' => array_map('floatval', $features), 'label' => $label];
    }
}
