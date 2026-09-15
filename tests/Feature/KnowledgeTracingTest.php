<?php

namespace Tests\Feature;

use App\Services\MachineLearning\KnowledgeTracingService;
use Tests\TestCase;

/**
 * Bayesian Knowledge Tracing, the model behind "What To Review Next".
 *
 * The property that matters most is the first one: percent-correct cannot tell
 * a student climbing out of a weak topic apart from one falling into it, and
 * the whole reason for running BKT is that it can.
 */
class KnowledgeTracingTest extends TestCase
{
    private KnowledgeTracingService $tracer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracer = new KnowledgeTracingService();
    }

    public function test_identical_scores_trace_differently_depending_on_order(): void
    {
        $climbing = $this->tracer->trace($this->sequence([false, false, true, true]));
        $falling = $this->tracer->trace($this->sequence([true, true, false, false]));

        // Both students answered 2 of 4 correctly.
        $this->assertSame(2, $climbing['correct']);
        $this->assertSame(2, $falling['correct']);

        // The model separates them by a wide margin anyway.
        $this->assertGreaterThan($falling['mastery'] + 0.4, $climbing['mastery']);
        $this->assertSame('improving', $climbing['trend']);
        $this->assertSame('slipping', $falling['trend']);
    }

    public function test_a_correct_answer_on_true_false_is_weaker_evidence_than_on_four_options(): void
    {
        $trueFalse = $this->tracer->trace([['correct' => true, 'option_count' => 2]]);
        $fourOption = $this->tracer->trace([['correct' => true, 'option_count' => 4]]);

        // Half the true/false answers a student could not answer at all still
        // come out right, so the same correct response says less about mastery.
        $this->assertLessThan($fourOption['mastery'], $trueFalse['mastery']);
    }

    public function test_an_unanswered_skill_sits_at_the_prior(): void
    {
        $result = $this->tracer->trace([]);

        $this->assertSame(KnowledgeTracingService::PRIOR, $result['mastery']);
        $this->assertSame(0, $result['opportunities']);
        $this->assertSame('unclear', $result['trend']);
    }

    public function test_a_short_sequence_does_not_claim_a_trend(): void
    {
        $result = $this->tracer->trace($this->sequence([false, false]));

        $this->assertSame('unclear', $result['trend']);
    }

    public function test_mastery_stays_within_probability_bounds(): void
    {
        $allWrong = $this->tracer->trace($this->sequence(array_fill(0, 25, false)));
        $allRight = $this->tracer->trace($this->sequence(array_fill(0, 25, true)));

        $this->assertGreaterThanOrEqual(0.0, $allWrong['mastery']);
        $this->assertLessThanOrEqual(1.0, $allRight['mastery']);

        // Sustained failure should read as struggling, sustained success as known.
        $this->assertSame('struggling', $allWrong['band']);
        $this->assertSame('mastered', $allRight['band']);
    }

    public function test_predicted_correct_accounts_for_guessing(): void
    {
        // A student with no knowledge at all still gets four-option items right
        // about a quarter of the time, so the floor is the guess rate, not zero.
        $this->assertEqualsWithDelta(0.25, $this->tracer->predictCorrect(0.0, 4), 0.001);

        // And a student who knows it still slips occasionally, so the ceiling
        // is below certainty.
        $this->assertEqualsWithDelta(0.9, $this->tracer->predictCorrect(1.0, 4), 0.001);
    }

    /**
     * @param  list<bool>  $outcomes
     * @return list<array{correct: bool, option_count: int}>
     */
    private function sequence(array $outcomes): array
    {
        return array_map(
            fn (bool $correct): array => ['correct' => $correct, 'option_count' => 4],
            $outcomes,
        );
    }
}
