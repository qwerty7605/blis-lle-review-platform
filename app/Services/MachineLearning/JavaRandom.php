<?php

namespace App\Services\MachineLearning;

/**
 * java.util.Random, as far as shuffling needs it. Weka shuffles a dataset
 * with it before dealing the cross-validation folds, so "random seed 1" only
 * means the same thing here if the numbers are drawn the same way.
 */
class JavaRandom
{
    private const MULTIPLIER = 0x5DEECE66D;

    private const MASK = (1 << 48) - 1;

    private int $seed;

    public function __construct(int $seed)
    {
        $this->seed = ($seed ^ self::MULTIPLIER) & self::MASK;
    }

    /** A whole number from 0 up to, not including, $bound. */
    public function nextInt(int $bound): int
    {
        $value = $this->next(31);
        $last = $bound - 1;

        if (($bound & $last) === 0) {
            return ($bound * $value) >> 31; // a power of two
        }

        // Java redraws when the 32-bit sum overflows, to keep the draw even.
        for ($drawn = $value; $drawn - ($value = $drawn % $bound) + $last > 0x7FFFFFFF; $drawn = $this->next(31));

        return $value;
    }

    private function next(int $bits): int
    {
        // seed * MULTIPLIER overflows 64 bits, so the seed is multiplied in
        // two 24-bit halves; only the low 48 bits of the product are kept.
        $high = ($this->seed >> 24) & 0xFFFFFF;
        $low = $this->seed & 0xFFFFFF;

        $this->seed = (((($high * self::MULTIPLIER) & 0xFFFFFF) << 24) + $low * self::MULTIPLIER + 0xB) & self::MASK;

        return $this->seed >> (48 - $bits);
    }
}
