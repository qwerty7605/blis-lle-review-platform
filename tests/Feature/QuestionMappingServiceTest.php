<?php

namespace Tests\Feature;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionClassification\LocalQuestionClassifier;
use App\Services\QuestionClassification\TopicKeywordDictionary;
use App\Services\QuestionMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the free/local rule-based classifier that replaced the old
 * OpenAI-based item mapper. No Http::fake() appears anywhere in this file
 * -- that's the point of the change: classification is a pure local
 * computation, not a network call.
 */
class QuestionMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_keyword_matching_picks_expected_topic_for_representative_subjects(): void
    {
        $classifier = new LocalQuestionClassifier();

        $cataloging = $this->makeQuestion(
            subject: 'Cataloging and Classification',
            questionText: 'Which of the following is the classification system that assigns call numbers, also known as the Dewey Decimal Classification?',
        );
        $topic = $classifier->classifyTopic($cataloging, 'Cataloging and Classification');
        $this->assertSame('Classification Systems and Call Numbers', $topic->topic);
        $this->assertSame('Dewey Decimal Classification (DDC)', $topic->subtopic);
        $this->assertGreaterThan(LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD, $topic->confidence);

        $it = $this->makeQuestion(
            subject: 'Information Technology',
            questionText: 'A boolean operator such as AND, OR, or NOT is used to combine search terms in a boolean search of the library catalog.',
        );
        $topic = $classifier->classifyTopic($it, 'Information Technology');
        $this->assertSame('Boolean Search and Query Strategy', $topic->topic);
        $this->assertSame('AND/OR/NOT Operators', $topic->subtopic);

        $management = $this->makeQuestion(
            subject: 'Library Management',
            questionText: 'Conducting a SWOT analysis helps identify the strengths, weaknesses, opportunities, and threats facing the library.',
        );
        $topic = $classifier->classifyTopic($management, 'Library Management');
        $this->assertSame('Assessment and Performance Indicators', $topic->topic);
        $this->assertSame('SWOT Analysis', $topic->subtopic);
    }

    public function test_cognitive_level_heuristic_matches_each_bloom_tier(): void
    {
        $classifier = new LocalQuestionClassifier();

        $analysis = $this->makeQuestion(questionText: 'Compare and contrast the Dewey Decimal Classification and the Library of Congress Classification systems.');
        $this->assertSame('analysis', $classifier->classifyCognitiveLevel($analysis)->value);

        $application = $this->makeQuestion(questionText: 'A patron approaches the reference desk asking for help finding scholarly articles. What should the librarian do first?');
        $this->assertSame('application', $classifier->classifyCognitiveLevel($application)->value);

        $comprehension = $this->makeQuestion(questionText: 'Why does the Dewey Decimal Classification system use three-digit numbers for main classes?');
        $this->assertSame('comprehension', $classifier->classifyCognitiveLevel($comprehension)->value);

        $recall = $this->makeQuestion(questionText: 'What is the definition of a call number in library cataloging?');
        $this->assertSame('recall', $classifier->classifyCognitiveLevel($recall)->value);

        $noCues = $this->makeQuestion(questionText: 'Overdue fines apply to reference materials kept longer than the allowed loan period.');
        $fallback = $classifier->classifyCognitiveLevel($noCues);
        $this->assertSame('recall', $fallback->value);
        $this->assertLessThan(LocalQuestionClassifier::COGNITIVE_CONFIDENCE_THRESHOLD, $fallback->confidence);
    }

    public function test_difficulty_heuristic_buckets_easy_and_hard_differently(): void
    {
        $classifier = new LocalQuestionClassifier();

        $easy = $this->makeQuestion(
            questionText: 'What is a library?',
            correctOptionText: 'A place housing books and other resources',
            distractorOptionText: 'A restaurant serving food',
        );
        $this->assertSame(QuestionBankQuestion::DIFFICULTY_EASY, $classifier->classifyDifficulty($easy)->value);

        $hard = $this->makeQuestion(
            questionText: 'In this scenario, a patron requests assistance identifying which of the following classification systems is NOT typically used by academic libraries for arranging materials on open shelves, considering every option EXCEPT the one used primarily in specialized archives.',
            correctOptionText: 'Dewey Decimal Classification system',
            distractorOptionText: 'Dewey Decimal Classification method',
        );
        $this->assertSame(QuestionBankQuestion::DIFFICULTY_HARD, $classifier->classifyDifficulty($hard)->value);
    }

    public function test_low_confidence_classification_forces_pending_review_even_in_auto_mode(): void
    {
        $bank = $this->makeBank();
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'item_number' => 1,
            'question_text' => 'Please read the passage below and answer accordingly regarding daily library operations.',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Option A',
        ]);
        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Option A', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Option B', 'is_correct' => false],
        ]);

        app(QuestionMappingService::class)->queueBankMapping($bank, QuestionBank::MAPPING_MODE_AUTO);

        $question->refresh();
        $this->assertNotNull($question->topic_id, 'a best-effort guess should still be written, never left blank');
        $this->assertSame(QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW, $question->tag_status);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $question->tag_source);
    }

    public function test_high_confidence_classification_in_auto_mode_is_confirmed(): void
    {
        $bank = $this->makeBank();
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'item_number' => 1,
            'question_text' => 'Which of the following is the classification system that assigns call numbers, also known as the Dewey Decimal Classification?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Dewey Decimal Classification',
        ]);
        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Dewey Decimal Classification', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Interlibrary Loan Request Form', 'is_correct' => false],
        ]);

        app(QuestionMappingService::class)->queueBankMapping($bank, QuestionBank::MAPPING_MODE_AUTO);

        $question->refresh();
        $this->assertSame(QuestionBankQuestion::TAG_STATUS_CONFIRMED, $question->tag_status);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $question->tag_source);
        $this->assertSame('Classification Systems and Call Numbers', $question->topic?->name);
        $this->assertSame('Dewey Decimal Classification (DDC)', $question->subtopic?->name);
    }

    public function test_queue_bank_mapping_completes_synchronously_without_http_calls(): void
    {
        // No Http::fake() anywhere in this file -- this is the point.
        $bank = $this->makeBank();

        foreach (range(1, 3) as $itemNumber) {
            $question = QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => 'Information Technology',
                'item_number' => $itemNumber,
                'question_text' => "Item {$itemNumber}: what is the purpose of a boolean operator in a boolean search?",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Combines search terms',
            ]);
            $question->options()->createMany([
                ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Combines search terms', 'is_correct' => true],
                ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Prints the shelf list', 'is_correct' => false],
            ]);
        }

        $result = app(QuestionMappingService::class)->queueBankMapping($bank, QuestionBank::MAPPING_MODE_REVIEW);
        $this->assertSame(QuestionBank::MAPPING_STATUS_QUEUED, $result['status']);

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_COMPLETED, $bank->mapping_status);

        $questions = $bank->assessmentQuestions()->get();
        $this->assertCount(3, $questions);

        foreach ($questions as $question) {
            $this->assertNotNull($question->topic_id);
            $this->assertNotNull($question->subtopic_id);
            $this->assertNotNull($question->difficulty);
            $this->assertNotNull($question->cognitive_level);
        }
    }

    public function test_a_subject_with_no_curated_topics_still_gets_difficulty_and_cognitive_level(): void
    {
        // "General" (an unset/undetected subject) has no CURATED_TOPICS
        // entry -- topic/subtopic can't be guessed, but difficulty and
        // cognitive level are subject-independent and should still be
        // applied rather than the whole bank hard-failing.
        $bank = $this->makeBank();
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'General',
            'item_number' => 1,
            'question_text' => 'What is the definition of a call number?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'A code identifying a shelf location',
        ]);
        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'A code identifying a shelf location', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'A patron\'s library card number', 'is_correct' => false],
        ]);

        $result = app(QuestionMappingService::class)->queueBankMapping($bank, QuestionBank::MAPPING_MODE_AUTO);
        $this->assertSame(QuestionBank::MAPPING_STATUS_QUEUED, $result['status']);

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_COMPLETED, $bank->mapping_status, $bank->mapping_message);

        $question->refresh();
        $this->assertNull($question->topic_id);
        $this->assertNotNull($question->difficulty);
        $this->assertNotNull($question->cognitive_level);
        $this->assertSame(QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW, $question->tag_status);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $question->tag_source);
    }

    public function test_topic_dictionary_keys_match_curated_topics_exactly(): void
    {
        $curated = app(QuestionMappingService::class)->curatedTopics();
        $dictionary = TopicKeywordDictionary::KEYWORDS;

        $this->assertSame(array_keys($curated), array_keys($dictionary), 'subjects must match exactly, in the same order');

        foreach ($curated as $subject => $topics) {
            $this->assertArrayHasKey($subject, $dictionary);
            $this->assertSame(
                array_keys($topics),
                array_keys($dictionary[$subject]),
                "topics for subject \"{$subject}\" must match exactly",
            );

            foreach ($topics as $topicName => $subtopicNames) {
                $this->assertSame(
                    $subtopicNames,
                    array_keys($dictionary[$subject][$topicName]),
                    "subtopics for \"{$subject} / {$topicName}\" must match exactly",
                );
            }
        }
    }

    private function makeBank(): QuestionBank
    {
        return QuestionBank::create([
            'title' => 'Local Classifier Test Bank',
            'subject' => 'General',
            'source_filename' => 'test.docx',
            'total_items' => 0,
        ]);
    }

    private function makeQuestion(
        string $subject = 'Cataloging and Classification',
        string $questionText = '',
        string $correctOptionText = 'Correct answer',
        string $distractorOptionText = 'Incorrect answer',
    ): QuestionBankQuestion {
        $question = new QuestionBankQuestion([
            'subject' => $subject,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'question_text' => $questionText,
            'answer_text' => $correctOptionText,
        ]);

        $question->setRelation('options', collect([
            new QuestionBankOption(['option_label' => 'A', 'option_text' => $correctOptionText, 'is_correct' => true]),
            new QuestionBankOption(['option_label' => 'B', 'option_text' => $distractorOptionText, 'is_correct' => false]),
        ]));

        return $question;
    }
}
