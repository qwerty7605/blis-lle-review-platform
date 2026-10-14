<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\KnowledgeTracingService;
use PHPUnit\Framework\TestCase;

/**
 * Bayesian Knowledge Tracing, checked against values worked out by hand.
 *
 * Parameters: P(L0) = 0.25, P(T) = 0.15, P(S) = 0.10, and P(G) = 1 / number
 * of choices (0.25 for four, capped at 0.5). After each answer:
 *
 *   correct:  P(L|obs) = P(L)(1-S) / [P(L)(1-S) + (1-P(L))G]
 *   wrong:    P(L|obs) = P(L)S     / [P(L)S     + (1-P(L))(1-G)]
 *   then:     P(L)     = P(L|obs) + (1 - P(L|obs)) T
 *
 * Worked example, one correct answer on a four-choice item:
 *   0.25 x 0.9 = 0.225;  0.75 x 0.25 = 0.1875;  0.225 / 0.4125 = 0.5455
 *   0.5455 + 0.4545 x 0.15 = 0.6136
 */
class KnowledgeTracingServiceTest extends TestCase
{
    private KnowledgeTracingService $bkt;

    protected function setUp(): void
    {
        $this->bkt = new KnowledgeTracingService();
    }

    private function answers(array $marks, ?int $options = 4): array
    {
        return array_map(fn (bool $correct) => ['correct' => $correct, 'option_count' => $options], $marks);
    }

    public function test_one_correct_answer_matches_the_worked_example(): void
    {
        $result = $this->bkt->trace($this->answers([true]));

        $this->assertSame(0.6136, $result['mastery']);
        $this->assertSame(61.4, $result['mastery_percent']);
        $this->assertSame(0.6489, $result['predicted_correct']);
        $this->assertSame(1, $result['opportunities']);
        $this->assertSame(1, $result['correct']);
    }

    public function test_hand_computed_sequences(): void
    {
        $this->assertSame(0.8735, $this->bkt->trace($this->answers([true, true]))['mastery']);
        $this->assertSame(0.1862, $this->bkt->trace($this->answers([false]))['mastery']);
        $this->assertSame(0.8256, $this->bkt->trace($this->answers([false, false, true, true]))['mastery']);
        $this->assertSame(0.2722, $this->bkt->trace($this->answers([true, true, false, false]))['mastery']);
    }

    public function test_a_correct_true_or_false_answer_is_weaker_evidence(): void
    {
        // Guess = 0.5 for two choices: 0.4688, against 0.6136 for four.
        $this->assertSame(0.4688, $this->bkt->trace($this->answers([true], 2))['mastery']);
    }

    public function test_an_unknown_choice_count_is_taken_as_four(): void
    {
        $this->assertSame(0.6136, $this->bkt->trace($this->answers([true], null))['mastery']);
        $this->assertSame(0.6136, $this->bkt->trace($this->answers([true], 1))['mastery']);
    }

    public function test_with_no_answers_mastery_is_the_prior(): void
    {
        $result = $this->bkt->trace([]);

        $this->assertSame(0.25, $result['mastery']);
        $this->assertSame(0.4125, $result['predicted_correct']);
        $this->assertSame('unclear', $result['trend']);
    }

    public function test_bands_follow_the_mastery_cut_offs(): void
    {
        $this->assertSame('mastered', $this->bkt->trace($this->answers([true, true]))['band']);
        $this->assertSame('struggling', $this->bkt->trace($this->answers([false]))['band']);
        $this->assertSame('developing', $this->bkt->trace($this->answers([true]))['band']);
    }

    public function test_the_trend_compares_the_end_with_the_midpoint(): void
    {
        $this->assertSame('improving', $this->bkt->trace($this->answers([false, false, true, true]))['trend']);
        $this->assertSame('slipping', $this->bkt->trace($this->answers([true, true, false, false]))['trend']);
        $this->assertSame('steady', $this->bkt->trace($this->answers([true, true, true, true, true, true, true, true]))['trend']);
    }

    public function test_the_chance_of_a_correct_next_answer_includes_guessing(): void
    {
        // 0.5 x 0.9 + 0.5 x 0.25 = 0.575; with two choices 0.5 x 0.9 + 0.5 x 0.5 = 0.7
        $this->assertEqualsWithDelta(0.575, $this->bkt->predictCorrect(0.5), 1e-9);
        $this->assertEqualsWithDelta(0.7, $this->bkt->predictCorrect(0.5, 2), 1e-9);
    }
}
