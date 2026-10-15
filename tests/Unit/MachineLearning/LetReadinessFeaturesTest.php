<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The model's inputs: six subject scores and `overall`, their weighted average
 * (reference 0.20, management 0.20, indexing 0.15, selection 0.15,
 * cataloging 0.20, it 0.10) over the subjects that have a score.
 */
class LetReadinessFeaturesTest extends TestCase
{
    public function test_the_weights_add_up_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, array_sum(LetReadinessFeatureBuilder::WEIGHTS), 1e-9);
        $this->assertSame(array_keys(LetReadinessFeatureBuilder::SUBJECTS), array_keys(LetReadinessFeatureBuilder::WEIGHTS));
    }

    public function test_overall_is_the_weighted_average_of_all_six(): void
    {
        $scores = ['reference' => 80, 'management' => 70, 'indexing' => 60, 'selection' => 50, 'cataloging' => 90, 'it' => 40];

        // 0.2*80 + 0.2*70 + 0.15*60 + 0.15*50 + 0.2*90 + 0.1*40 = 68.5
        $this->assertSame(68.5, LetReadinessFeatureBuilder::overall($scores));
    }

    public function test_a_missing_subject_is_left_out_of_the_division_not_counted_as_zero(): void
    {
        // Only reference (0.2) and it (0.1): (0.2*90 + 0.1*60) / 0.3 = 80
        $this->assertSame(80.0, LetReadinessFeatureBuilder::overall(['reference' => 90, 'it' => 60, 'selection' => null]));
        $this->assertNull(LetReadinessFeatureBuilder::overall([]));
    }

    public function test_features_fill_every_subject_and_add_overall(): void
    {
        $features = LetReadinessFeatureBuilder::features(['reference' => '75', 'it' => 'n/a']);

        $this->assertSame(['reference', 'management', 'indexing', 'selection', 'cataloging', 'it', 'overall'], array_keys($features));
        $this->assertSame(75.0, $features['reference']);
        $this->assertNull($features['it']);
        $this->assertNull($features['management']);
        $this->assertSame(75.0, $features['overall']);
    }
}
