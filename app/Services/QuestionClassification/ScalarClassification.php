<?php

namespace App\Services\QuestionClassification;

/**
 * Result of a single-value heuristic (cognitive level or difficulty): the
 * chosen enum value plus a 0-1 confidence score. Shared shape for both
 * dimensions since neither needs anything beyond value + confidence.
 */
final class ScalarClassification
{
    public function __construct(
        public readonly string $value,
        public readonly float $confidence,
    ) {
    }
}
