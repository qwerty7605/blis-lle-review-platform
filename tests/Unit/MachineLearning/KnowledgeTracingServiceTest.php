<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\KnowledgeTracingService;
use PHPUnit\Framework\TestCase;

/**
 * Bayesian Knowledge Tracing, one update per exam, checked against values
 * worked out outside PHP (Python, from the formula below).
 *
 * Parameters: P(L0) = 0.25, P(T) = 0.15, P(S) = 0.10, P(G) = 1 / number of
 * choices (0.25 for four, capped at 0.5), C = 3. For one skill with n items
 * in an exam, c_i = 1 when item i was right, and w = min(1, C / n):
 *
 *   log L_known   = w * sum[ c_i log(1 - S) + (1 - c_i) log S ]
 *   log L_unknown = w * sum[ c_i log G_i   + (1 - c_i) log(1 - G_i) ]
 *   posterior     = p L_known / (p L_known + (1 - p) L_unknown)
 *   p_next        = posterior + (1 - posterior) T
 *
 * Worked example, 2 of 2 four-choice items right (n below the cap, w = 1):
 *   L_known / L_unknown = (0.9 / 0.25)^2 = 12.96
 *   posterior = 0.25 x 12.96 / (0.25 x 12.96 + 0.75) = 0.8120
 *   p_next = 0.8120 + 0.1880 x 0.15 = 0.8402
 * (Answer by answer, with a learning step after each, it used to be 0.8735.)
 */
class KnowledgeTracingServiceTest extends TestCase
{
    private KnowledgeTracingService $bkt;

    protected function setUp(): void
    {
        $this->bkt = new KnowledgeTracingService();
    }

    /** An exam's items on the skill: $correct of $total right, all with $options choices. */
    private function exam(int $correct, int $total, ?int $options = 4): array
    {
        return array_map(
            fn (int $index): array => ['correct' => $index < $correct, 'option_count' => $options],
            range(0, $total - 1),
        );
    }

    public function test_the_two_cases_worked_out_for_twenty_items_per_exam(): void
    {
        $falling = $this->bkt->traceExams([$this->exam(15, 20), $this->exam(10, 20)]);
        $rising = $this->bkt->traceExams([$this->exam(10, 20), $this->exam(15, 20)]);

        // The same 25 of 40 right, read in the order the exams were taken.
        $this->assertSame(0.4594, $falling['mastery']);
        $this->assertSame(0.6152, $rising['mastery']);
        $this->assertSame(2, $falling['opportunities']);
        $this->assertSame(40, $falling['items']);
        $this->assertSame(25, $falling['correct']);
        $this->assertSame('slipping', $falling['trend']);
        $this->assertSame('improving', $rising['trend']);
    }

    public function test_one_exam_can_only_move_the_estimate_as_far_as_three_items(): void
    {
        $this->assertSame(KnowledgeTracingService::EXAM_EVIDENCE_CAP, 3);

        // One exam at 75%: 0.6325 with C = 3 (it would be 0.8012 with C = 5).
        $result = $this->bkt->traceExams([$this->exam(15, 20)]);
        $this->assertSame(0.6325, $result['mastery']);
        $this->assertSame('developing', $result['band']);
        $this->assertSame('unclear', $result['trend']);
    }

    public function test_below_the_cap_it_is_ordinary_bkt_on_the_batch_with_one_learning_step(): void
    {
        $this->assertSame(0.8402, $this->bkt->traceExams([$this->exam(2, 2)])['mastery']);
        $this->assertSame(0.2672, $this->bkt->traceExams([$this->exam(1, 2)])['mastery']);
        $this->assertSame(0.6136, $this->bkt->traceExams([$this->exam(1, 1)])['mastery']);
    }

    public function test_items_with_different_numbers_of_choices_are_weighed_by_their_own_guess_rate(): void
    {
        // Right on a four-choice item, right on a true/false item, wrong on a
        // four-choice item.
        $mixed = [
            ['correct' => true, 'option_count' => 4],
            ['correct' => true, 'option_count' => 2],
            ['correct' => false, 'option_count' => 4],
        ];

        $this->assertSame(0.3401, $this->bkt->traceExams([$mixed])['mastery']);
        // A correct true/false answer is weaker evidence than a four-choice one.
        $this->assertSame(0.4688, $this->bkt->traceExams([$this->exam(1, 1, 2)])['mastery']);
        // An unknown number of choices is taken as four.
        $this->assertSame(0.6136, $this->bkt->traceExams([$this->exam(1, 1, null)])['mastery']);
        $this->assertSame(0.6136, $this->bkt->traceExams([$this->exam(1, 1, 1)])['mastery']);
    }

    public function test_an_exam_without_items_on_the_skill_leaves_mastery_unchanged(): void
    {
        $with = $this->bkt->traceExams([$this->exam(15, 20), [], $this->exam(10, 20)]);
        $without = $this->bkt->traceExams([$this->exam(15, 20), $this->exam(10, 20)]);

        $this->assertSame($without, $with);
        $this->assertSame(2, $with['opportunities']);
    }

    public function test_with_no_exams_mastery_is_the_prior(): void
    {
        $result = $this->bkt->traceExams([]);

        $this->assertSame(0.25, $result['mastery']);
        $this->assertSame(0.4125, $result['predicted_correct']);
        $this->assertSame(0, $result['opportunities']);
        $this->assertSame('unclear', $result['trend']);
        $this->assertSame('struggling', $result['band']);
    }

    public function test_the_trend_compares_the_last_exam_with_the_one_before(): void
    {
        // 0.1507 then 0.1504: within the 0.05 deadband.
        $this->assertSame('steady', $this->bkt->traceExams([$this->exam(0, 20), $this->exam(0, 20)])['trend']);
        $this->assertSame(0.1504, $this->bkt->traceExams([$this->exam(0, 20), $this->exam(0, 20)])['mastery']);
        // 0.9486, 0.9990, 1.0000: the last step is within the deadband too.
        $this->assertSame('steady', $this->bkt->traceExams(array_fill(0, 3, $this->exam(20, 20)))['trend']);
        $this->assertSame('mastered', $this->bkt->traceExams(array_fill(0, 3, $this->exam(20, 20)))['band']);
    }

    public function test_the_chance_of_a_correct_next_answer_includes_guessing(): void
    {
        // 0.5 x 0.9 + 0.5 x 0.25 = 0.575; with two choices 0.5 x 0.9 + 0.5 x 0.5 = 0.7
        $this->assertEqualsWithDelta(0.575, $this->bkt->predictCorrect(0.5), 1e-9);
        $this->assertEqualsWithDelta(0.7, $this->bkt->predictCorrect(0.5, 2), 1e-9);
    }
}
