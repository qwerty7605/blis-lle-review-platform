<?php

namespace Tests\Unit\QuestionClassification;

use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionClassification\LocalQuestionClassifier;
use App\Services\QuestionClassification\TopicKeywordDictionary;
use App\Support\LleTaxonomy;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/**
 * LocalQuestionClassifier tags a question with no AI: weighted keyword
 * matching for the topic, Bloom's taxonomy cue phrases for the cognitive
 * level, and a wording heuristic for difficulty (negatives, length, a
 * scenario, and how alike the choices are by Levenshtein distance or
 * Jaccard word overlap).
 */
class LocalQuestionClassifierTest extends TestCase
{
    private LocalQuestionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new LocalQuestionClassifier();
    }

    /** An unsaved question with the given choices; the first is correct. */
    private function question(string $text, array $choices = []): QuestionBankQuestion
    {
        $question = new QuestionBankQuestion(['question_text' => $text, 'answer_text' => $choices[0] ?? null]);
        $question->setRelation('options', new Collection(array_map(
            fn (string $choice, int $index) => new QuestionBankOption(['option_text' => $choice, 'is_correct' => $index === 0]),
            $choices,
            array_keys($choices),
        )));

        return $question;
    }

    public function test_the_keyword_dictionary_has_exactly_the_tos_subjects_topics_and_subtopics(): void
    {
        $this->assertSame(LleTaxonomy::keys(), array_keys(TopicKeywordDictionary::KEYWORDS));

        foreach (LleTaxonomy::subjects() as $key => $subject) {
            $tosTopics = array_filter($subject['topics'], fn (array $topic): bool => !$topic['other']);
            $this->assertSame(array_column($tosTopics, 'code'), array_map('strval', array_keys(TopicKeywordDictionary::KEYWORDS[$key])), $key);

            foreach ($tosTopics as $topic) {
                $entries = TopicKeywordDictionary::KEYWORDS[$key][$topic['code']];
                $this->assertSame(array_column($topic['subtopics'], 'code'), array_map('strval', array_keys($entries)), "{$key} {$topic['code']}");

                foreach ($entries as $code => $entry) {
                    $this->assertNotSame('', trim($entry['concept']), "{$key} {$code} has no concept");
                    $this->assertNotEmpty($entry['keywords'], "{$key} {$code} has no keywords");
                }
            }
        }

        // The TOS has 6 subjects, 29 topics and 154 sub-topics.
        $this->assertSame(154, LleTaxonomy::subtopicCount());
        $this->assertSame(29, array_sum(array_map(fn (array $topics): int => count($topics), TopicKeywordDictionary::KEYWORDS)));
    }

    public function test_every_phrase_matches_a_question_that_contains_it(): void
    {
        foreach (TopicKeywordDictionary::KEYWORDS as $subject => $topics) {
            foreach ($topics as $subtopics) {
                foreach ($subtopics as $code => $entry) {
                    $phrases = array_column($entry['keywords'], 0);
                    $this->assertSame(array_unique($phrases), $phrases, "{$subject} {$code} lists a phrase twice");

                    foreach ($entry['keywords'] as [$phrase, $weight]) {
                        $this->assertTrue(
                            $this->classifier->matchesPhrase("Which of the following is true of {$phrase} in libraries?", $phrase),
                            "\"{$phrase}\" ({$subject} {$code}) never matches",
                        );
                        $this->assertGreaterThanOrEqual(1.0, $weight);
                        $this->assertLessThanOrEqual(3.0, $weight);
                    }
                }
            }
        }
    }

    public function test_phrases_with_punctuation_match_however_the_question_writes_them(): void
    {
        $this->assertTrue($this->classifier->matchesPhrase('Which protocol is Z39.50?', 'z39.50'));
        $this->assertTrue($this->classifier->matchesPhrase('the Work - Expression - Manifestation chain', 'work, expression, manifestation'));
        $this->assertTrue($this->classifier->matchesPhrase('What is a précis?', 'precis'));
        $this->assertTrue($this->classifier->matchesPhrase('Under R.A. 9246, who may practise?', 'r.a. 9246'));
        // Whole words only.
        $this->assertFalse($this->classifier->matchesPhrase('The interpretation of rules', 'pret'));
    }

    public function test_a_question_full_of_a_subtopic_s_phrases_is_tagged_with_it_confidently(): void
    {
        $result = $this->classifier->classifyTopic(
            $this->question('During the reference interview, should the librarian use neutral questioning or a closed question first?'),
            'Reference Services',
        );

        $this->assertSame('3. Reference and Information Services', $result->topic);
        $this->assertSame('3.3 Identify the various methods and techniques involved in a reference interview', $result->subtopic);
        $this->assertFalse($result->unclassified);
        $this->assertGreaterThan(0.45, $result->confidence);

        $isbd = $this->classifier->classifyTopic(
            $this->question('In the ISBD, which prescribed punctuation separates the areas of description?'),
            'Cataloging and Classification',
        );
        $this->assertSame('2.3 Identify the basic parts of the International Standard Bibliographic Description', $isbd->subtopic);
    }

    public function test_a_subject_the_dictionary_does_not_know_gets_no_topic(): void
    {
        $result = $this->classifier->classifyTopic($this->question('Anything at all'), 'Archival Studies');

        $this->assertNull($result->topic);
        $this->assertSame(0.0, $result->confidence);
    }

    public function test_with_no_keyword_at_all_the_question_is_other_unclassified(): void
    {
        $result = $this->classifier->classifyTopic($this->question('Zzz qqq xxyy'), 'Reference, Bibliography and User Services');

        $this->assertSame('Other / Unclassified', $result->topic);
        $this->assertNull($result->subtopic);
        $this->assertTrue($result->unclassified);
    }

    public function test_a_whole_file_s_subject_is_guessed_from_its_questions(): void
    {
        $texts = [
            $this->classifier->buildSearchTextFromParts(['Which field of the MARC record holds the main entry?']),
            $this->classifier->buildSearchTextFromParts(['Dewey Decimal Classification divides knowledge into ten main classes.']),
        ];

        $this->assertSame('Cataloging and Classification', $this->classifier->classifySubjectForTexts($texts)->value);
        $this->assertSame('', $this->classifier->classifySubjectForTexts([])->value);
    }

    public function test_bloom_s_level_is_read_from_the_question_s_cue_phrase(): void
    {
        $this->assertSame('analysis', $this->classifier->classifyCognitiveLevel($this->question('Compare and contrast LCC and DDC.'))->value);
        $this->assertSame('application', $this->classifier->classifyCognitiveLevel($this->question('In this scenario, what should the librarian do?'))->value);

        $fallback = $this->classifier->classifyCognitiveLevel($this->question('Zzz qqq.'));
        $this->assertSame('recall', $fallback->value);
        $this->assertSame(0.3, $fallback->confidence);
    }

    public function test_a_short_plain_question_with_distinct_choices_is_easy(): void
    {
        $result = $this->classifier->classifyDifficulty($this->question('What is MARC?', ['A record format', 'A bird', 'A city', 'A song']));

        $this->assertSame(QuestionBankQuestion::DIFFICULTY_EASY, $result->value);
    }

    public function test_a_long_negative_scenario_with_look_alike_choices_is_hard(): void
    {
        $result = $this->classifier->classifyDifficulty($this->question(
            'In this scenario a patron asks for help, and the librarian must decide quickly; which of the following is NOT an appropriate first step for the librarian to take when the request is unclear and urgent?',
            ['Ask an open question', 'Ask an open questions', 'Ask a open question', 'Ask an closed question'],
        ));

        $this->assertSame(QuestionBankQuestion::DIFFICULTY_HARD, $result->value);
    }

    public function test_long_choices_are_compared_by_shared_words(): void
    {
        $long = 'the catalog lists every item held by the library in one place for patrons';
        $result = $this->classifier->classifyDifficulty($this->question('Which statement is correct about union catalogs today?', [
            $long,
            $long . ' and staff',
            'a completely different and unrelated sentence about nothing in particular here',
        ]));

        $this->assertContains($result->value, QuestionBankQuestion::DIFFICULTIES);
    }

    public function test_choices_with_no_correct_one_or_no_text_add_nothing(): void
    {
        $noCorrect = $this->question('What is MARC?', ['A', 'B']);
        $noCorrect->options->each(fn ($option) => $option->is_correct = false);
        $blank = $this->question('What is MARC?', ['', 'B']);
        $alone = $this->question('What is MARC?', ['Only choice']);

        foreach ([$noCorrect, $blank, $alone] as $question) {
            $this->assertSame(QuestionBankQuestion::DIFFICULTY_EASY, $this->classifier->classifyDifficulty($question)->value);
        }
    }
}
