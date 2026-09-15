<?php

namespace App\Services\MachineLearning;

/**
 * Bayesian Knowledge Tracing (Corbett & Anderson, 1995).
 *
 * Percent-correct treats every answer as equally informative and has no memory
 * of the order they arrived in, so "wrong, wrong, right, right" and "right,
 * right, wrong, wrong" both read as 50% -- one student climbing out of a gap
 * and one falling into it, scored identically.
 *
 * BKT instead models mastery as a hidden state and estimates it from the
 * observable sequence, allowing for the two ways the observation lies:
 *
 *   slip  -- the student knows it and still answers wrong (careless error)
 *   guess -- the student does not know it and still answers right
 *
 * Each response drives two updates. First Bayes' rule revises the belief about
 * what the student knew *at that moment* given how they answered; then the
 * learning step allows that the practice opportunity itself may have taught
 * them something.
 *
 * Nothing here is trained. SLIP/PRIOR/LEARN_RATE are the standard values from
 * the intelligent-tutoring literature, and the guess rate is not fitted at all
 * -- it falls out of the item format, since one option in four is a 25% chance
 * of a blind correct answer. Fitting these to a specific cohort (EM or a grid
 * search over held-out sequences) is a refinement, not a prerequisite.
 *
 * @see LetPredictionService for the other model in this namespace -- same
 *      principle of keeping inference cheap enough to run inside a request.
 */
class KnowledgeTracingService
{
    /** P(L0) -- chance the student knew the skill before any practice. */
    public const PRIOR = 0.25;

    /** P(T) -- chance one practice opportunity moves them to knowing it. */
    public const LEARN_RATE = 0.15;

    /** P(S) -- chance of answering wrong despite knowing the skill. */
    public const SLIP = 0.10;

    /** P(G) when an item's option count is unknown (assumes 4 options). */
    public const DEFAULT_GUESS = 0.25;

    /**
     * Guess and slip must both stay under a half or the model goes degenerate:
     * above it, answering correctly would *lower* the mastery estimate.
     */
    private const MAX_GUESS = 0.5;

    /** Mastery at or above this reads as "known" in the UI. */
    public const MASTERED_AT = 0.7;

    /** Mastery at or below this reads as "not known yet". */
    public const STRUGGLING_AT = 0.4;

    /** Mastery has to move at least this much to count as a trend, not noise. */
    private const TREND_DEADBAND = 0.05;

    /**
     * Trace one ordered sequence of responses for a single skill.
     *
     * @param  list<array{correct: bool, option_count?: int|null}>  $responses
     *         Chronological -- oldest answer first. Order is the entire point;
     *         passing these unordered produces a number that means nothing.
     * @return array{
     *     mastery: float,
     *     mastery_percent: float,
     *     predicted_correct: float,
     *     opportunities: int,
     *     correct: int,
     *     trend: string,
     *     band: string
     * }
     */
    public function trace(array $responses): array
    {
        $mastery = self::PRIOR;
        $series = [$mastery];
        $correct = 0;

        foreach ($responses as $response) {
            $isCorrect = (bool) ($response['correct'] ?? false);
            $guess = $this->guessRateFor($response['option_count'] ?? null);

            if ($isCorrect) {
                $correct++;
            }

            // Step 1 -- evidence. Given how they answered, what did they know
            // at that moment? A correct answer is explained either by knowing
            // it (and not slipping) or by not knowing it (and guessing).
            $knewAndAnsweredThisWay = $isCorrect
                ? $mastery * (1.0 - self::SLIP)
                : $mastery * self::SLIP;

            $didNotKnowAndAnsweredThisWay = $isCorrect
                ? (1.0 - $mastery) * $guess
                : (1.0 - $mastery) * (1.0 - $guess);

            $evidence = $knewAndAnsweredThisWay + $didNotKnowAndAnsweredThisWay;

            $posterior = $evidence > 0.0
                ? $knewAndAnsweredThisWay / $evidence
                : $mastery;

            // Step 2 -- learning. They have now had one more go at the skill,
            // so some of the remaining "doesn't know it" mass transitions.
            $mastery = $posterior + ((1.0 - $posterior) * self::LEARN_RATE);
            $series[] = $mastery;
        }

        return [
            'mastery' => round($mastery, 4),
            'mastery_percent' => round($mastery * 100, 1),
            'predicted_correct' => round($this->predictCorrect($mastery), 4),
            'opportunities' => count($responses),
            'correct' => $correct,
            'trend' => $this->trendOf($series),
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
     * Which way mastery is moving, comparing where it ended against where it
     * stood halfway through. Needs a few opportunities before the question
     * means anything at all.
     */
    private function trendOf(array $series): string
    {
        $count = count($series);

        if ($count < 4) {
            return 'unclear';
        }

        $midpoint = $series[intdiv($count, 2)];
        $final = $series[$count - 1];
        $movement = $final - $midpoint;

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
