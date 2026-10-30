<?php

namespace App\Services\MachineLearning;

/**
 * Bayesian Knowledge Tracing (Corbett & Anderson, 1995), one learning
 * opportunity per exam.
 *
 * Percent-correct treats every answer as equally informative and has no memory
 * of the order exams came in, so "weak, weak, strong, strong" and "strong,
 * strong, weak, weak" both read as the same average -- one student climbing
 * out of a gap and one falling into it, scored identically.
 *
 * BKT instead models mastery as a hidden state and estimates it from the
 * observable sequence, allowing for the two ways an answer lies:
 *
 *   slip  -- the student knows it and still answers wrong (careless error)
 *   guess -- the student does not know it and still answers right
 *
 * Inside one exam the order of answers means nothing: items are shuffled,
 * students skip around, and nobody is told mid-exam whether they were right.
 * So each exam is one opportunity. Its answers on the skill are weighed
 * together as one batch of evidence (Bayes' rule), and then one learning step
 * allows that sitting the exam may itself have taught something. Order
 * across exams still matters, and that is the order read here.
 *
 * One exam can only move the estimate so far. A skill with n items in an
 * exam has its evidence weighted by w = min(1, C / n), C = EXAM_EVIDENCE_CAP:
 * up to C items it is ordinary BKT on the batch; past C, the exam counts as
 * much as C items at its proportion correct.
 *
 *   log L_known   = w * sum[ c_i log(1 - S) + (1 - c_i) log S ]
 *   log L_unknown = w * sum[ c_i log G_i   + (1 - c_i) log(1 - G_i) ]
 *   posterior     = p L_known / (p L_known + (1 - p) L_unknown)
 *   p_next        = posterior + (1 - posterior) T
 *
 * Nothing here is trained. SLIP/PRIOR/LEARN_RATE are the standard values from
 * the intelligent-tutoring literature, and the guess rate is not fitted at all
 * -- it falls out of the item format, since one option in four is a 25% chance
 * of a blind correct answer.
 */
class KnowledgeTracingService
{
    /** P(L0) -- chance the student knew the skill before any exam. */
    public const PRIOR = 0.25;

    /** P(T) -- chance one exam moves them to knowing it. */
    public const LEARN_RATE = 0.15;

    /** P(S) -- chance of answering wrong despite knowing the skill. */
    public const SLIP = 0.10;

    /** P(G) when an item's option count is unknown (assumes 4 options). */
    public const DEFAULT_GUESS = 0.25;

    /**
     * C: how many items' worth of evidence one exam can give on one skill.
     * With 3, one exam at 75% reads 0.63 (developing), not "mastered".
     */
    public const EXAM_EVIDENCE_CAP = 3;

    /**
     * Guess and slip must both stay under a half or the model goes degenerate:
     * above it, answering correctly would *lower* the mastery estimate.
     */
    private const MAX_GUESS = 0.5;

    /** Mastery at or above this reads as "known" in the UI. */
    public const MASTERED_AT = 0.7;

    /** Mastery at or below this reads as "not known yet". */
    public const STRUGGLING_AT = 0.4;

    /** Mastery has to move at least this much between exams to count as a trend, not noise. */
    private const TREND_DEADBAND = 0.05;

    /**
     * Trace one skill across exams.
     *
     * @param  list<list<array{correct: bool, option_count?: int|null}>>  $exams
     *         One entry per exam, oldest first; each is the skill's items in
     *         that exam (a blank counts as not correct, as in scoring). An
     *         exam with no items on the skill leaves the estimate unchanged.
     * @return array{
     *     mastery: float,
     *     mastery_percent: float,
     *     predicted_correct: float,
     *     opportunities: int,
     *     items: int,
     *     correct: int,
     *     trend: string,
     *     band: string
     * }
     */
    public function traceExams(array $exams): array
    {
        $mastery = self::PRIOR;
        $afterEachExam = [];
        $items = 0;
        $correct = 0;

        foreach ($exams as $exam) {
            $count = count($exam);

            if ($count === 0) {
                continue;
            }

            $weight = min(1.0, self::EXAM_EVIDENCE_CAP / $count);
            $logKnown = 0.0;
            $logUnknown = 0.0;

            foreach ($exam as $item) {
                $isCorrect = (bool) ($item['correct'] ?? false);
                $guess = $this->guessRateFor($item['option_count'] ?? null);

                $logKnown += $isCorrect ? log(1.0 - self::SLIP) : log(self::SLIP);
                $logUnknown += $isCorrect ? log($guess) : log(1.0 - $guess);

                $items++;
                $correct += $isCorrect ? 1 : 0;
            }

            // Evidence: Bayes' rule on the weighted batch, written with the
            // likelihood ratio so nothing under- or overflows.
            $ratio = exp($weight * ($logUnknown - $logKnown));
            $posterior = $mastery / ($mastery + (1.0 - $mastery) * $ratio);

            // Learning: one step for the exam as a whole.
            $mastery = $posterior + ((1.0 - $posterior) * self::LEARN_RATE);
            $afterEachExam[] = $mastery;
        }

        return [
            'mastery' => round($mastery, 4),
            'mastery_percent' => round($mastery * 100, 1),
            'predicted_correct' => round($this->predictCorrect($mastery), 4),
            'opportunities' => count($afterEachExam),
            'items' => $items,
            'correct' => $correct,
            'trend' => $this->trendOf($afterEachExam),
            'band' => $this->bandOf($mastery),
        ];
    }

    /**
     * Probability the student answers the next item on this skill correctly --
     * they know it and do not slip, or they do not know it and guess right.
     */
    public function predictCorrect(float $mastery, ?int $optionCount = null): float
    {
        $guess = $this->guessRateFor($optionCount);

        return ($mastery * (1.0 - self::SLIP)) + ((1.0 - $mastery) * $guess);
    }

    /**
     * P(G) straight from the item format: one option in four is a 25% chance
     * of a blind correct answer, true/false is 50%. This is the parameter that
     * most obviously should not be a fitted constant -- the format already
     * tells us what it is.
     */
    private function guessRateFor(?int $optionCount): float
    {
        if ($optionCount === null || $optionCount < 2) {
            return self::DEFAULT_GUESS;
        }

        return min(1.0 / $optionCount, self::MAX_GUESS);
    }

    /**
     * Mastery after the latest exam against mastery after the one before it.
     * With fewer than two exams on the skill there is nothing to compare.
     *
     * @param  list<float>  $afterEachExam
     */
    private function trendOf(array $afterEachExam): string
    {
        $count = count($afterEachExam);

        if ($count < 2) {
            return 'unclear';
        }

        $movement = $afterEachExam[$count - 1] - $afterEachExam[$count - 2];

        if ($movement > self::TREND_DEADBAND) {
            return 'improving';
        }

        if ($movement < -self::TREND_DEADBAND) {
            return 'slipping';
        }

        return 'steady';
    }

    private function bandOf(float $mastery): string
    {
        if ($mastery >= self::MASTERED_AT) {
            return 'mastered';
        }

        if ($mastery <= self::STRUGGLING_AT) {
            return 'struggling';
        }

        return 'developing';
    }
}
