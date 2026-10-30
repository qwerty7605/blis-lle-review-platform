<?php

namespace App\Services\QuestionClassification;

/**
 * Result of matching a question against the TOS keyword dictionary (see
 * TopicKeywordDictionary). `confidence` is a 0-1 score used to decide whether
 * the guess is trustworthy enough to auto-confirm or should be forced into
 * pending_review regardless of the bank's mode.
 *
 * `unclassified` is a question no keyword matched at all: it is tagged its
 * subject's "Other / Unclassified" topic, with no sub-topic. That is a
 * finding, not a weak guess, so it does not send the question to review on
 * its own.
 */
final class TopicClassification
{
    public function __construct(
        public readonly ?string $topic,
        public readonly ?string $subtopic,
        public readonly float $confidence,
        public readonly bool $unclassified = false,
    ) {
    }
}
