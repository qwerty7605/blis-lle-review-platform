<?php

namespace App\Services\QuestionClassification;

/**
 * Result of matching a question against the curated topic/subtopic keyword
 * dictionary (see TopicKeywordDictionary). `confidence` is a 0-1 score used
 * to decide whether the guess is trustworthy enough to auto-confirm or
 * should be forced into pending_review regardless of the bank's mode.
 */
final class TopicClassification
{
    public function __construct(
        public readonly ?string $topic,
        public readonly ?string $subtopic,
        public readonly float $confidence,
    ) {
    }
}
