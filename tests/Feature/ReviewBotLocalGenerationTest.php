<?php

namespace Tests\Feature;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Services\QuestionGeneration\DistractorPool;
use App\Services\QuestionGeneration\LocalReviewQuestionGenerator;
use App\Services\ReviewBotAdminService;
use App\Services\ReviewBotQuestionScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the free/local rule-based Review Bot generator that replaced the
 * old OpenAI-based one (see LocalReviewQuestionGenerator,
 * ReviewBotQuestionScreeningService::embed()). No Http::fake() appears
 * anywhere in this file, and no services.openai.api_key is ever configured
 * -- that's the point of the change: generation and near-duplicate
 * screening are both pure local computation now.
 */
class ReviewBotLocalGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'Cataloging and Classification';
    private const TOPIC = '4. Classification';

    public function test_generator_produces_a_variant_per_cognitive_level_when_a_sibling_subtopic_exists(): void
    {
        [$teacher, $bank, $topic] = $this->makeSubjectFixture();
        $subtopicA = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.3 Summarize the historical background and general characteristics of DDC', 'created_by' => $teacher->id]);
        $subtopicB = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.6 Paraphrase the historical background and general characteristics of LCC Scheme', 'created_by' => $teacher->id]);

        $source = $this->makeAssessmentQuestion(
            $bank, 1, $topic, $subtopicA,
            'What is the classification system that organizes materials into ten main classes using three digit numbers?',
            'Dewey Decimal Classification',
        );
        $this->makeAssessmentQuestion($bank, 2, $topic, $subtopicB, 'Which classification system organizes materials by broad subject class letters?', 'Library of Congress Classification');
        $this->makeAssessmentQuestion($bank, 3, $topic, $subtopicA, 'What numbering system underlies the Dewey Decimal Classification?', 'Base ten notation');
        $this->makeAssessmentQuestion($bank, 4, $topic, $subtopicB, 'Which institution maintains the Library of Congress Classification?', 'The Library of Congress');

        $generator = app(LocalReviewQuestionGenerator::class);
        $variants = $generator->generate(collect([$source]), self::SUBJECT);

        $levels = collect($variants)->pluck('cognitive_level')->all();
        $this->assertEqualsCanonicalizing(['recall', 'comprehension', 'application', 'analysis'], $levels);

        foreach ($variants as $variant) {
            $this->assertSame(0, $variant['source_index']);
            $this->assertSame(QuestionBankQuestion::TYPE_MULTIPLE_CHOICE, $variant['type']);
            $this->assertCount(4, $variant['options']);
            $this->assertNotNull($variant['correct_option_id']);

            $optionIds = collect($variant['options'])->pluck('id');
            $this->assertContains($variant['correct_option_id'], $optionIds->all());

            // Every option must carry real, non-empty, distinct text -- never
            // a fabricated or blank placeholder.
            $optionTexts = collect($variant['options'])->map(fn (array $option): string => strtolower(trim($option['text'])));
            $this->assertTrue($optionTexts->every(fn (string $text): bool => $text !== ''));
            $this->assertSame($optionTexts->count(), $optionTexts->unique()->count());

            $this->assertContains($variant['difficulty'], QuestionBankQuestion::DIFFICULTIES);
        }
    }

    public function test_generator_omits_analysis_when_no_sibling_subtopic_exists(): void
    {
        [$teacher, $bank, $topic] = $this->makeSubjectFixture();
        $subtopic = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.3 Summarize the historical background and general characteristics of DDC', 'created_by' => $teacher->id]);

        $source = $this->makeAssessmentQuestion($bank, 1, $topic, $subtopic, 'What is the DDC?', 'Dewey Decimal Classification');
        // Extra same-subtopic items give the distractor pool enough to draw
        // from without introducing a second subtopic for analysis to pair against.
        $this->makeAssessmentQuestion($bank, 2, $topic, $subtopic, 'Filler question two', 'Universal Decimal Classification');
        $this->makeAssessmentQuestion($bank, 3, $topic, $subtopic, 'Filler question three', 'Colon Classification');
        $this->makeAssessmentQuestion($bank, 4, $topic, $subtopic, 'Filler question four', 'Bliss Bibliographic Classification');

        $generator = app(LocalReviewQuestionGenerator::class);
        $variants = $generator->generate(collect([$source]), self::SUBJECT);

        $levels = collect($variants)->pluck('cognitive_level');
        $this->assertFalse($levels->contains('analysis'), 'no sibling subtopic exists, so analysis must be omitted, not fabricated');
        $this->assertTrue($levels->contains('recall'));
    }

    public function test_distractor_pool_never_includes_or_duplicates_the_correct_answer(): void
    {
        [$teacher, $bank, $topic] = $this->makeSubjectFixture();
        $subtopic = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.3 Summarize the historical background and general characteristics of DDC', 'created_by' => $teacher->id]);

        $source = $this->makeAssessmentQuestion($bank, 1, $topic, $subtopic, 'What is the DDC?', 'Dewey Decimal Classification');
        $this->makeAssessmentQuestion($bank, 2, $topic, $subtopic, 'Filler question two', 'Dewey Decimal Classification');
        $this->makeAssessmentQuestion($bank, 3, $topic, $subtopic, 'Filler question three', 'Universal Decimal Classification');
        $this->makeAssessmentQuestion($bank, 4, $topic, $subtopic, 'Filler question four', 'Library of Congress Classification');
        $this->makeAssessmentQuestion($bank, 5, $topic, $subtopic, 'Filler question five', 'Colon Classification');

        $pool = app(DistractorPool::class);
        $candidates = $pool->candidatesForTopic(self::SUBJECT, $topic->id, $source->id, 'Dewey Decimal Classification');

        $this->assertFalse($candidates->contains(fn (string $text): bool => strtolower($text) === 'dewey decimal classification'));
        $this->assertSame($candidates->count(), $candidates->map(fn (string $text): string => strtolower($text))->unique()->count());
    }

    public function test_generation_runs_end_to_end_and_persists_rule_based_draft_rows(): void
    {
        [$teacher, $bank, $topic] = $this->makeSubjectFixture();
        $subtopicA = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.3 Summarize the historical background and general characteristics of DDC', 'created_by' => $teacher->id]);
        $subtopicB = Subtopic::create(['topic_id' => $topic->id, 'name' => '4.6 Paraphrase the historical background and general characteristics of LCC Scheme', 'created_by' => $teacher->id]);

        $this->makeAssessmentQuestion($bank, 1, $topic, $subtopicA, 'What is the DDC used for in a library?', 'Organizing library materials by subject');
        $this->makeAssessmentQuestion($bank, 2, $topic, $subtopicB, 'What is the LCC used for in a library?', 'Organizing academic and research library collections');
        $this->makeAssessmentQuestion($bank, 3, $topic, $subtopicA, 'Who developed the Dewey Decimal Classification?', 'Melvil Dewey');
        $this->makeAssessmentQuestion($bank, 4, $topic, $subtopicB, 'Which institution maintains the Library of Congress Classification?', 'The Library of Congress');

        $reviewBotBank = QuestionBank::create([
            'title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . self::SUBJECT,
            'subject' => self::SUBJECT,
            'total_items' => 0,
            'created_by' => $teacher->id,
        ]);

        $admin = app(ReviewBotAdminService::class);
        $result = $admin->generate($reviewBotBank, (int) $teacher->id, self::SUBJECT, null, null, 10, (int) $bank->id);

        $this->assertGreaterThan(0, $result['created']);
        $this->assertSame(4, $result['sources_processed']);
        $this->assertSame([], $result['errors']);

        $created = QuestionBankQuestion::query()
            ->with('options')
            ->where('question_bank_id', $reviewBotBank->id)
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->get();

        $this->assertSame($result['created'], $created->count());

        foreach ($created as $question) {
            $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $question->tag_source, 'generated rows must be attributed to the local generator, not "ai"');
            $this->assertSame(QuestionBankQuestion::STATUS_DRAFT, $question->status);
            $this->assertSame(QuestionBankQuestion::TAG_STATUS_CONFIRMED, $question->tag_status);
            $this->assertNotNull($question->source_question_id);
            $this->assertNotNull($question->topic_id);
            $this->assertCount(4, $question->options);
            $this->assertSame(1, $question->options->where('is_correct', true)->count());
            $this->assertContains($question->cognitive_level, QuestionBankQuestion::COGNITIVE_LEVELS);
        }
    }

    public function test_local_embeddings_flag_near_identical_generated_prompts_as_duplicates(): void
    {
        $screening = app(ReviewBotQuestionScreeningService::class);

        $generated = [
            ['prompt' => 'Which of the following best describes the Dewey Decimal Classification system used in libraries?', 'options' => [], 'source_index' => 0],
            ['prompt' => 'Which of the following best describes the Dewey Decimal Classification system used in libraries?', 'options' => [], 'source_index' => 1],
            ['prompt' => 'A patron is trying to check out a book but their library card has expired.', 'options' => [], 'source_index' => 2],
        ];

        $result = $screening->screen($generated, self::SUBJECT);

        // The exact repeat of item 0 must be rejected as a duplicate; the
        // unrelated third prompt must survive.
        $this->assertCount(2, $result['accepted']);
        $this->assertCount(1, $result['rejected']);
        $this->assertSame('duplicate_within_batch', $result['rejected'][0]['reason']);
    }

    public function test_local_embeddings_do_not_flag_shared_template_wording_as_duplicates(): void
    {
        // Same comprehension-style template, genuinely different content --
        // this is exactly the shape LocalReviewQuestionGenerator produces
        // across different source items, and must NOT be treated as
        // duplicates just because they share boilerplate wording.
        $screening = app(ReviewBotQuestionScreeningService::class);

        $generated = [
            ['prompt' => 'Which of the following is best identified by this description: "Dewey Decimal Classification"?', 'options' => [], 'source_index' => 0],
            ['prompt' => 'Which of the following is best identified by this description: "Library of Congress Classification"?', 'options' => [], 'source_index' => 1],
        ];

        $result = $screening->screen($generated, self::SUBJECT);

        $this->assertCount(2, $result['accepted']);
        $this->assertCount(0, $result['rejected']);
    }

    /**
     * @return array{0: User, 1: QuestionBank, 2: Topic}
     */
    private function makeSubjectFixture(): array
    {
        $teacher = User::factory()->create([
            'email' => 'rb-gen-teacher-' . uniqid() . '@example.com',
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
        ]);

        $bank = QuestionBank::create([
            'title' => 'Cataloging Midterm',
            'subject' => self::SUBJECT,
            'total_items' => 0,
            'created_by' => $teacher->id,
        ]);

        $topic = Topic::create(['subject' => self::SUBJECT, 'name' => self::TOPIC, 'created_by' => $teacher->id]);

        return [$teacher, $bank, $topic];
    }

    private function makeAssessmentQuestion(
        QuestionBank $bank,
        int $itemNumber,
        Topic $topic,
        Subtopic $subtopic,
        string $questionText,
        string $answerText,
    ): QuestionBankQuestion {
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => self::SUBJECT,
            'topic_id' => $topic->id,
            'subtopic_id' => $subtopic->id,
            'status' => QuestionBankQuestion::STATUS_PUBLISHED,
            'item_number' => $itemNumber,
            'question_text' => $questionText,
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => $answerText,
        ]);

        QuestionBankOption::create([
            'question_bank_question_id' => $question->id,
            'sort_order' => 1,
            'option_label' => 'A',
            'option_text' => $answerText,
            'is_correct' => true,
        ]);

        foreach (['B', 'C', 'D'] as $index => $label) {
            QuestionBankOption::create([
                'question_bank_question_id' => $question->id,
                'sort_order' => $index + 2,
                'option_label' => $label,
                'option_text' => sprintf('Distractor %s for item %d', $label, $itemNumber),
                'is_correct' => false,
            ]);
        }

        return $question->fresh(['options', 'topic', 'subtopic', 'questionBank']);
    }
}
