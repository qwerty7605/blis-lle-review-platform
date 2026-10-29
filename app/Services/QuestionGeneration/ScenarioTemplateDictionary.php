<?php

namespace App\Services\QuestionGeneration;

/**
 * Generic per-subject scenario "carrier sentences" for
 * LocalReviewQuestionGenerator's application-level variant. Each template
 * carries a `{concept}` placeholder filled with a source item's sub-topic
 * concept (a short noun phrase from TopicKeywordDictionary, not the TOS
 * competency statement) -- the sentence itself never asserts a fact, it only
 * frames the already-correct answer/distractors in a short realistic setting,
 * so there's no content-invention risk in using a fixed, reusable set of these.
 *
 * Two per subject is enough for variety (picked deterministically per source
 * item, see LocalReviewQuestionGenerator::buildApplicationVariant()) without
 * asking anyone to author a template per topic/subtopic.
 *
 * Keys must be exactly the 6 official subject names of the Table of
 * Specifications (App\Support\LleTaxonomy) -- a subject missing here just
 * means no application-level variant gets generated for it (degrades
 * gracefully, see the class docblock's "omit a level" rule).
 */
class ScenarioTemplateDictionary
{
    public const TEMPLATES = [
        'Cataloging and Classification' => [
            'A cataloger is processing a new item and must decide how to handle {concept}. Which of the following is the correct approach?',
            'While organizing the shelving area, a librarian needs to apply what they know about {concept}. Which of the following applies?',
        ],
        'Indexing and Abstracting' => [
            'An indexer preparing a database entry must apply what they know about {concept}. Which of the following is correct?',
            'A researcher reviewing a set of search results needs to understand {concept}. Which of the following applies?',
        ],
        'Information Technology' => [
            'A systems librarian configuring the library\'s technology must consider {concept}. Which of the following is correct?',
            'A patron using the library\'s online systems runs into an issue related to {concept}. Which of the following applies?',
        ],
        'Library Organization and Management' => [
            'A library director planning ahead must consider {concept}. Which of the following is correct?',
            'During a staff meeting, the topic of {concept} comes up. Which of the following statements applies?',
        ],
        'Reference, Bibliography and User Services' => [
            'A patron approaches the reference desk with a question involving {concept}. Which of the following is the correct response?',
            'During a reference interview, a librarian must draw on {concept} to help the patron. Which of the following applies?',
        ],
        'Selection and Acquisition' => [
            'A selection librarian building the collection must consider {concept}. Which of the following is correct?',
            'While processing a new order, the acquisitions librarian must consider {concept}. Which of the following applies?',
        ],
    ];
}
