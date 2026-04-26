<?php

namespace Tests\Feature;

use App\Jobs\GenerateRemedialQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\RemedialQuestionService;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibraryRemedialGenerationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_question_bank_queues_remedial_generation_when_openai_is_configured(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', 'test-openai-key');

        $staff = $this->makeStaffUser('teacher@example.com');
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/library/banks', [
            'title' => 'Cataloging Mock Set',
            'subject' => 'Cataloging',
            'source_filename' => 'cataloging.docx',
            'questions' => [
                [
                    'item_number' => 1,
                    'text' => 'Which system is used to organize library materials by subject?',
                    'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                    'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
                    'answer_label' => 'A',
                    'answer_text' => 'Dewey Decimal Classification',
                    'options' => [
                        [
                            'label' => 'A',
                            'text' => 'Dewey Decimal Classification',
                            'is_correct' => true,
                        ],
                        [
                            'label' => 'B',
                            'text' => 'ISBN',
                            'is_correct' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('remedial_generation.status', QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED)
            ->assertJsonPath('remedial_generation.queued', 1)
            ->assertJsonPath('bank.remedial_generation_status', QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED);

        $this->assertDatabaseHas('question_banks', [
            'title' => 'Cataloging Mock Set',
            'remedial_generation_status' => QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED,
        ]);

        $this->assertDatabaseHas('question_bank_questions', [
            'question_bank_id' => (int) $response->json('bank.id'),
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'difficulty' => null,
        ]);

        Queue::assertPushed(GenerateRemedialQuestionsJob::class, function (GenerateRemedialQuestionsJob $job): bool {
            return $job->bankId > 0 && count($job->sourceQuestionIds) === 1;
        });
    }

    public function test_creating_question_bank_marks_generation_skipped_when_openai_is_not_configured(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', '');

        $staff = $this->makeStaffUser('teacher-skip@example.com');
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/library/banks', [
            'title' => 'Reference Mock Set',
            'subject' => 'Reference Services',
            'source_filename' => 'reference.docx',
            'questions' => [
                [
                    'item_number' => 1,
                    'text' => 'Which source is best for quick factual lookups?',
                    'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                    'difficulty' => QuestionBankQuestion::DIFFICULTY_EASY,
                    'answer_label' => 'B',
                    'answer_text' => 'Encyclopedia',
                    'options' => [
                        [
                            'label' => 'A',
                            'text' => 'Novel',
                            'is_correct' => false,
                        ],
                        [
                            'label' => 'B',
                            'text' => 'Encyclopedia',
                            'is_correct' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('remedial_generation.status', QuestionBank::REMEDIAL_GENERATION_STATUS_SKIPPED)
            ->assertJsonPath('bank.remedial_generation_status', QuestionBank::REMEDIAL_GENERATION_STATUS_SKIPPED)
            ->assertJsonPath('remedial_generation.queued', 0);

        $this->assertStringContainsString(
            'OPENAI_API_KEY is not configured',
            (string) $response->json('message')
        );

        Queue::assertNothingPushed();
    }

    public function test_retry_endpoint_does_not_queue_duplicate_generation_when_bank_is_already_queued(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', 'test-openai-key');

        $staff = $this->makeStaffUser('teacher-queued@example.com');
        $bank = QuestionBank::create([
            'title' => 'Indexing Practice Bank',
            'subject' => 'Indexing',
            'source_filename' => 'indexing.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
            'remedial_generation_status' => QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED,
            'remedial_generation_message' => 'AI remedial generation queued for 1 assessment question.',
            'remedial_generation_requested_at' => now(),
        ]);

        QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'What is indexing?',
            'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
            'answer_label' => null,
            'answer_text' => 'A guide entry that points users to relevant information.',
        ]);

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/library/banks/{$bank->id}/queue-remedial-generation");

        $response
            ->assertOk()
            ->assertJsonPath('remedial_generation.status', QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED)
            ->assertJsonPath('remedial_generation.queued', 0);

        $this->assertStringContainsString(
            'already queued',
            (string) $response->json('message')
        );

        Queue::assertNothingPushed();
    }

    public function test_retry_generation_deletes_rejected_questions_before_queuing_new_ai_generation(): void
    {
        Queue::fake();
        config()->set('services.openai.api_key', 'test-openai-key');

        $staff = $this->makeStaffUser('teacher-regenerate@example.com');
        $bank = QuestionBank::create([
            'title' => 'Regenerate Bank',
            'subject' => 'Indexing',
            'source_filename' => 'regenerate.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $source = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'difficulty' => null,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_REJECTED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'explanation' => 'Old rejected explanation.',
            'source_question_id' => $source->id,
            'item_number' => 2,
            'question_text' => 'Rejected AI question',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Correct answer',
        ]);

        Sanctum::actingAs($staff);

        $this->postJson("/api/library/banks/{$bank->id}/queue-remedial-generation")
            ->assertOk()
            ->assertJsonPath('remedial_generation.status', QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED);

        $this->assertDatabaseMissing('question_bank_questions', [
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'status' => QuestionBankQuestion::STATUS_REJECTED,
        ]);

        Queue::assertPushed(GenerateRemedialQuestionsJob::class, function (GenerateRemedialQuestionsJob $job) use ($bank): bool {
            return $job->bankId === $bank->id;
        });
    }

    public function test_staff_can_view_question_bank_questions(): void
    {
        $staff = $this->makeStaffUser('teacher-view@example.com');
        $topic = Topic::create([
            'subject' => 'Cataloging',
            'name' => 'Classification',
            'created_by' => $staff->id,
        ]);
        $bank = QuestionBank::create([
            'title' => 'Cataloging Viewer Bank',
            'subject' => 'Cataloging',
            'source_filename' => 'cataloging-view.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging',
            'topic_id' => $topic->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'Which classification system groups books by subject?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Dewey Decimal Classification',
        ]);

        $question->options()->createMany([
            [
                'sort_order' => 1,
                'option_label' => 'A',
                'option_text' => 'Dewey Decimal Classification',
                'is_correct' => true,
            ],
            [
                'sort_order' => 2,
                'option_label' => 'B',
                'option_text' => 'ISBN',
                'is_correct' => false,
            ],
        ]);

        $remedial = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Cataloging',
            'topic_id' => $topic->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION,
            'cognitive_level' => 'comprehension',
            'tested_concept' => 'Classification systems',
            'difference_from_source' => 'Uses the answer concept inside the stem.',
            'explanation' => 'Classification systems group library materials into organized classes.',
            'source_question_id' => $question->id,
            'item_number' => 2,
            'question_text' => 'Which tool helps organize library materials into classes?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Classification system',
        ]);

        $remedial->options()->createMany([
            [
                'sort_order' => 1,
                'option_label' => 'A',
                'option_text' => 'Classification system',
                'is_correct' => true,
            ],
            [
                'sort_order' => 2,
                'option_label' => 'B',
                'option_text' => 'Barcode printer',
                'is_correct' => false,
            ],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->getJson("/api/library/banks/{$bank->id}/questions");

        $response
            ->assertOk()
            ->assertJsonPath('bank.id', $bank->id)
            ->assertJsonPath('questions.0.id', $question->id)
            ->assertJsonPath('questions.0.topic.name', 'Classification')
            ->assertJsonPath('questions.0.options.0.option_label', 'A')
            ->assertJsonPath('questions.0.remedial_variant_count', 1)
            ->assertJsonPath('assessment_questions.0.id', $question->id)
            ->assertJsonPath('remedial_questions.0.id', $remedial->id)
            ->assertJsonPath('remedial_questions.0.item_style', QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION)
            ->assertJsonPath('remedial_questions.0.explanation', 'Classification systems group library materials into organized classes.')
            ->assertJsonPath('remedial_questions.0.source_question.item_number', 1)
            ->assertJsonPath('remedial_questions.0.options.0.option_label', 'A');
    }

    public function test_remedial_queue_hides_rejected_questions_by_default(): void
    {
        $staff = $this->makeStaffUser('teacher-queue-default@example.com');
        $bank = QuestionBank::create([
            'title' => 'Queue Visibility Bank',
            'subject' => 'Cataloging',
            'source_filename' => 'queue-visibility.docx',
            'total_items' => 2,
            'created_by' => $staff->id,
        ]);

        $pending = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Cataloging',
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'explanation' => 'Pending explanation.',
            'item_number' => 1,
            'question_text' => 'Pending question',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Correct answer',
        ]);

        $rejected = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Cataloging',
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_REJECTED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_SCENARIO_BASED,
            'explanation' => 'Rejected explanation.',
            'item_number' => 2,
            'question_text' => 'Rejected question',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Correct answer',
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/library/remedial-queue')
            ->assertOk()
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.id', $pending->id);

        $this->getJson('/api/library/remedial-queue?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.id', $rejected->id);
    }

    public function test_generator_creates_four_supported_ai_styles_per_source_question(): void
    {
        config()->set('services.openai.api_key', 'test-openai-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.model', 'gpt-4o-mini');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'questions' => [
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'reverse_association',
                                    'difficulty' => 'medium',
                                    'cognitive_level' => 'comprehension',
                                    'question_text' => 'PRECIS is best identified as which type of indexing system?',
                                    'options' => [
                                        'A' => 'A pre-coordinate subject indexing system',
                                        'B' => 'An acquisition ledger',
                                        'C' => 'A circulation barcode policy',
                                        'D' => 'A shelving repair workflow',
                                    ],
                                    'correct_answer' => 'A',
                                    'explanation' => 'PRECIS is known as a pre-coordinate indexing system.',
                                    'tested_concept' => 'Nature of PRECIS as an indexing system',
                                    'difference_from_source' => 'Uses the original answer in the stem and asks for the related concept.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'definition_to_term',
                                    'difficulty' => 'easy',
                                    'cognitive_level' => 'recall',
                                    'question_text' => 'Which term refers to the indexing system formerly used by the British National Bibliography?',
                                    'options' => [
                                        'A' => 'POPSI',
                                        'B' => 'PRECIS',
                                        'C' => 'NEPHIS',
                                        'D' => 'CIFT',
                                    ],
                                    'correct_answer' => 'B',
                                    'explanation' => 'PRECIS was the system formerly used by the British National Bibliography.',
                                    'tested_concept' => 'Association of PRECIS with the British National Bibliography',
                                    'difference_from_source' => 'Reframes the source as a term-identification prompt rather than repeating the same wording.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'scenario_based',
                                    'difficulty' => 'hard',
                                    'cognitive_level' => 'application',
                                    'question_text' => 'A review student encounters older British National Bibliography entries built through a pre-coordinate indexing approach. Which system is most likely being referenced?',
                                    'options' => [
                                        'A' => 'POPSI',
                                        'B' => 'PRECIS',
                                        'C' => 'ISBN',
                                        'D' => 'AACR2',
                                    ],
                                    'correct_answer' => 'B',
                                    'explanation' => 'Older BNB entries using that approach point to PRECIS.',
                                    'tested_concept' => 'Applying knowledge of PRECIS in a realistic review scenario',
                                    'difference_from_source' => 'Places the concept inside a realistic licensure-review situation.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'comparison',
                                    'difficulty' => 'medium',
                                    'cognitive_level' => 'analysis',
                                    'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools and standards?',
                                    'options' => [
                                        'A' => 'It is an indexing system formerly used by the British National Bibliography.',
                                        'B' => 'It is a barcode format for circulation desks.',
                                        'C' => 'It is a catalog card size standard.',
                                        'D' => 'It is a shelving hardware guide.',
                                    ],
                                    'correct_answer' => 'A',
                                    'explanation' => 'Only option A accurately distinguishes PRECIS from unrelated tools.',
                                    'tested_concept' => 'Distinguishing PRECIS from other library concepts',
                                    'difference_from_source' => 'Compares the concept against nearby distractor concepts instead of directly asking for the name.',
                                ],
                            ],
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ]),
        ]);

        $staff = $this->makeStaffUser('teacher-generator@example.com');
        $bank = QuestionBank::create([
            'title' => 'Indexing Bank',
            'subject' => 'Indexing',
            'source_filename' => 'indexing.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'NEPHIS', 'is_correct' => false],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'PRECIS', 'is_correct' => true],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'POPSI', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'CIFT', 'is_correct' => false],
        ]);

        $question->load([
            'questionBank:id,subject',
            'options:id,question_bank_question_id,option_label,option_text,is_correct',
        ]);

        $generation = app(RemedialQuestionService::class)->generateForAssessmentQuestions(collect([$question]), false);

        $this->assertSame(4, $generation['created']);
        $this->assertSame([], $generation['errors']);

        $this->assertDatabaseCount('question_bank_questions', 5);

        foreach (QuestionBankQuestion::ITEM_STYLES as $style) {
            $this->assertDatabaseHas('question_bank_questions', [
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
                'source' => QuestionBankQuestion::SOURCE_AI,
                'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
                'ai_generated' => true,
                'source_question_id' => $question->id,
                'item_style' => $style,
            ]);
        }
    }

    public function test_candidate_detection_respects_valid_explanations_even_with_partially_loaded_variants(): void
    {
        $staff = $this->makeStaffUser('teacher-candidates@example.com');
        $bank = QuestionBank::create([
            'title' => 'Candidate Detection Bank',
            'subject' => 'Indexing',
            'source_filename' => 'candidates.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $source = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'difficulty' => null,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        foreach (QuestionBankQuestion::ITEM_STYLES as $offset => $style) {
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
                'subject' => 'Indexing',
                'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'source' => QuestionBankQuestion::SOURCE_AI,
                'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
                'ai_generated' => true,
                'item_style' => $style,
                'cognitive_level' => 'analysis',
                'tested_concept' => 'PRECIS',
                'difference_from_source' => 'Varies the angle from the source question.',
                'explanation' => 'PRECIS identifies an indexing system rather than an unrelated library tool.',
                'source_question_id' => $source->id,
                'item_number' => $offset + 2,
                'question_text' => "Variant for {$style}",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Correct answer',
            ]);
        }

        $partiallyLoadedSource = QuestionBankQuestion::query()
            ->with('remedialVariants:id,source_question_id,item_style')
            ->findOrFail($source->id);

        $candidateIds = app(RemedialQuestionService::class)
            ->collectGenerationCandidateIds(collect([$partiallyLoadedSource]));

        $this->assertSame([], $candidateIds);
    }

    public function test_candidate_detection_treats_rejected_variants_as_regeneration_candidates(): void
    {
        $staff = $this->makeStaffUser('teacher-rejected-candidates@example.com');
        $bank = QuestionBank::create([
            'title' => 'Rejected Candidate Bank',
            'subject' => 'Indexing',
            'source_filename' => 'rejected-candidates.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $source = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'difficulty' => null,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        foreach (QuestionBankQuestion::ITEM_STYLES as $offset => $style) {
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
                'subject' => 'Indexing',
                'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'source' => QuestionBankQuestion::SOURCE_AI,
                'status' => QuestionBankQuestion::STATUS_REJECTED,
                'ai_generated' => true,
                'item_style' => $style,
                'cognitive_level' => 'analysis',
                'tested_concept' => 'PRECIS',
                'difference_from_source' => 'Varies the angle from the source question.',
                'explanation' => 'PRECIS identifies an indexing system rather than an unrelated library tool.',
                'source_question_id' => $source->id,
                'item_number' => $offset + 2,
                'question_text' => "Rejected variant for {$style}",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Correct answer',
            ]);
        }

        $candidateIds = app(RemedialQuestionService::class)
            ->collectGenerationCandidateIds(collect([$source]));

        $this->assertSame([$source->id], $candidateIds);
    }

    public function test_staff_can_edit_remedial_question_metadata_and_options(): void
    {
        $staff = $this->makeStaffUser('teacher-edit@example.com');
        $bank = QuestionBank::create([
            'title' => 'Remedial Edit Bank',
            'subject' => 'Indexing',
            'source_filename' => 'edit.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION,
            'cognitive_level' => 'comprehension',
            'tested_concept' => 'PRECIS',
            'difference_from_source' => 'Old difference text',
            'explanation' => 'Old explanation',
            'source_question_id' => null,
            'item_number' => 2,
            'question_text' => 'Old question text',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Old answer',
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Old answer', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Option B', 'is_correct' => false],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'Option C', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'Option D', 'is_correct' => false],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/library/remedial-questions/{$question->id}", [
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'How PRECIS differs from unrelated concepts',
            'difference_from_source' => 'Shifts the original recall question into a comparison task.',
            'explanation' => 'PRECIS is an indexing system, not a barcode or hardware tool.',
            'correct_answer' => 'C',
            'options' => [
                'A' => 'It is a circulation due-date slip.',
                'B' => 'It is a shelving repair checklist.',
                'C' => 'It is an indexing system formerly used by the British National Bibliography.',
                'D' => 'It is a barcode scanner standard.',
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('question.item_style', QuestionBankQuestion::ITEM_STYLE_COMPARISON)
            ->assertJsonPath('question.cognitive_level', 'analysis')
            ->assertJsonPath('question.answer_label', 'C')
            ->assertJsonPath('question.answer_text', 'It is an indexing system formerly used by the British National Bibliography.')
            ->assertJsonPath('question.options.2.option_label', 'C');

        $this->assertDatabaseHas('question_bank_questions', [
            'id' => $question->id,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'tested_concept' => 'How PRECIS differs from unrelated concepts',
        ]);
    }

    public function test_refresh_replaces_rejected_remedial_question_in_place(): void
    {
        config()->set('services.openai.api_key', 'test-openai-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.model', 'gpt-4o-mini');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'questions' => [[
                                'source_question_id' => 1,
                                'item_style' => 'comparison',
                                'difficulty' => 'medium',
                                'cognitive_level' => 'analysis',
                                'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
                                'options' => [
                                    'A' => 'It is an indexing system formerly used by the British National Bibliography.',
                                    'B' => 'It is a barcode format for circulation desks.',
                                    'C' => 'It is a shelving hardware guide.',
                                    'D' => 'It is a due-date reminder slip.',
                                ],
                                'correct_answer' => 'A',
                                'explanation' => 'PRECIS identifies an indexing system rather than an unrelated library tool.',
                                'tested_concept' => 'How PRECIS differs from unrelated library tools',
                                'difference_from_source' => 'Changes the original recall item into a comparison prompt.',
                            ]],
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ]),
        ]);

        $staff = $this->makeStaffUser('teacher-refresh@example.com');
        $bank = QuestionBank::create([
            'title' => 'Refresh Bank',
            'subject' => 'Indexing',
            'source_filename' => 'refresh.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $source = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'difficulty' => null,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        $source->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'NEPHIS', 'is_correct' => false],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'PRECIS', 'is_correct' => true],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'POPSI', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'CIFT', 'is_correct' => false],
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_REJECTED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'Old concept',
            'difference_from_source' => 'Old difference',
            'explanation' => null,
            'source_question_id' => $source->id,
            'item_number' => 2,
            'question_text' => 'Old rejected question',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Old answer',
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Old answer', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Old option B', 'is_correct' => false],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'Old option C', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'Old option D', 'is_correct' => false],
        ]);

        Sanctum::actingAs($staff);

        $this->postJson("/api/library/questions/{$question->id}/remedial-refresh")
            ->assertOk()
            ->assertJsonPath('question.id', $question->id)
            ->assertJsonPath('question.status', QuestionBankQuestion::STATUS_PENDING_REVIEW)
            ->assertJsonPath('question.explanation', null);

        $this->assertDatabaseHas('question_bank_questions', [
            'id' => $question->id,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'explanation' => null,
        ]);

        $this->assertSame(2, QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->count());
    }

    public function test_generation_reuses_rejected_variant_slot_instead_of_appending_duplicate(): void
    {
        config()->set('services.openai.api_key', 'test-openai-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        config()->set('services.openai.model', 'gpt-4o-mini');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'questions' => [
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'reverse_association',
                                    'difficulty' => 'medium',
                                    'cognitive_level' => 'comprehension',
                                    'question_text' => 'PRECIS is best identified as which type of indexing system?',
                                    'options' => [
                                        'A' => 'A pre-coordinate subject indexing system',
                                        'B' => 'An acquisition ledger',
                                        'C' => 'A circulation barcode policy',
                                        'D' => 'A shelving repair workflow',
                                    ],
                                    'correct_answer' => 'A',
                                    'explanation' => 'PRECIS is known as a pre-coordinate indexing system.',
                                    'tested_concept' => 'Nature of PRECIS as an indexing system',
                                    'difference_from_source' => 'Uses the original answer in the stem and asks for the related concept.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'definition_to_term',
                                    'difficulty' => 'easy',
                                    'cognitive_level' => 'recall',
                                    'question_text' => 'Which term refers to the indexing system formerly used by the British National Bibliography?',
                                    'options' => [
                                        'A' => 'POPSI',
                                        'B' => 'PRECIS',
                                        'C' => 'NEPHIS',
                                        'D' => 'CIFT',
                                    ],
                                    'correct_answer' => 'B',
                                    'explanation' => 'PRECIS was the system formerly used by the British National Bibliography.',
                                    'tested_concept' => 'Association of PRECIS with the British National Bibliography',
                                    'difference_from_source' => 'Reframes the source as a term-identification prompt rather than repeating the same wording.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'scenario_based',
                                    'difficulty' => 'hard',
                                    'cognitive_level' => 'application',
                                    'question_text' => 'A review student encounters older British National Bibliography entries built through a pre-coordinate indexing approach. Which system is most likely being referenced?',
                                    'options' => [
                                        'A' => 'POPSI',
                                        'B' => 'PRECIS',
                                        'C' => 'ISBN',
                                        'D' => 'AACR2',
                                    ],
                                    'correct_answer' => 'B',
                                    'explanation' => 'Older BNB entries using that approach point to PRECIS.',
                                    'tested_concept' => 'Applying knowledge of PRECIS in a realistic review scenario',
                                    'difference_from_source' => 'Places the concept inside a realistic licensure-review situation.',
                                ],
                                [
                                    'source_question_id' => 1,
                                    'item_style' => 'comparison',
                                    'difficulty' => 'medium',
                                    'cognitive_level' => 'analysis',
                                    'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
                                    'options' => [
                                        'A' => 'It is an indexing system formerly used by the British National Bibliography.',
                                        'B' => 'It is a barcode format for circulation desks.',
                                        'C' => 'It is a shelving hardware guide.',
                                        'D' => 'It is a due-date reminder slip.',
                                    ],
                                    'correct_answer' => 'A',
                                    'explanation' => 'PRECIS identifies an indexing system rather than an unrelated library tool.',
                                    'tested_concept' => 'How PRECIS differs from unrelated library tools',
                                    'difference_from_source' => 'Changes the original recall item into a comparison prompt.',
                                ],
                            ],
                        ], JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ]),
        ]);

        $staff = $this->makeStaffUser('teacher-reuse-slot@example.com');
        $bank = QuestionBank::create([
            'title' => 'Reuse Slot Bank',
            'subject' => 'Indexing',
            'source_filename' => 'reuse-slot.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $source = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Indexing',
            'difficulty' => null,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'item_number' => 1,
            'question_text' => 'What indexing system was previously used by the British National Bibliography?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'B',
            'answer_text' => 'PRECIS',
        ]);

        $source->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'NEPHIS', 'is_correct' => false],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'PRECIS', 'is_correct' => true],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'POPSI', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'CIFT', 'is_correct' => false],
        ]);

        $rejected = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_REJECTED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'Old concept',
            'difference_from_source' => 'Old difference',
            'explanation' => 'Old explanation.',
            'source_question_id' => $source->id,
            'item_number' => 2,
            'question_text' => 'Old rejected comparison question',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Old answer',
        ]);

        $source->load([
            'questionBank:id,subject',
            'options:id,question_bank_question_id,option_label,option_text,is_correct',
            'remedialVariants:id,source_question_id,item_style,status,explanation',
        ]);

        $generation = app(RemedialQuestionService::class)->generateForAssessmentQuestions(
            collect([$source]),
            true
        );

        $this->assertSame(4, $generation['created']);
        $this->assertSame([], $generation['errors']);
        $this->assertSame(5, QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->count());

        $this->assertDatabaseHas('question_bank_questions', [
            'id' => $rejected->id,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'explanation' => null,
        ]);
    }

    public function test_ai_remedial_question_can_be_approved_without_explanation(): void
    {
        $staff = $this->makeStaffUser('teacher-approve@example.com');
        $bank = QuestionBank::create([
            'title' => 'Approval Bank',
            'subject' => 'Indexing',
            'source_filename' => 'approval.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'How PRECIS differs from unrelated systems',
            'difference_from_source' => 'Changes a recall prompt into a comparison prompt.',
            'explanation' => null,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'It is an indexing system formerly used by the British National Bibliography.',
        ]);

        Sanctum::actingAs($staff);

        $this->patchJson("/api/library/remedial-questions/{$question->id}/status", [
            'status' => QuestionBankQuestion::STATUS_APPROVED,
        ])
            ->assertOk()
            ->assertJsonPath('question.status', QuestionBankQuestion::STATUS_APPROVED);
    }

    public function test_staff_can_bulk_approve_pending_remedial_questions_for_a_set(): void
    {
        $staff = $this->makeStaffUser('teacher-bulk@example.com');
        $bank = QuestionBank::create([
            'title' => 'Bulk Review Bank',
            'subject' => 'Indexing',
            'source_filename' => 'bulk.docx',
            'total_items' => 2,
            'created_by' => $staff->id,
        ]);

        foreach ([1, 2] as $itemNumber) {
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
                'subject' => 'Indexing',
                'topic_id' => null,
                'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'source' => QuestionBankQuestion::SOURCE_AI,
                'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
                'ai_generated' => true,
                'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
                'cognitive_level' => 'analysis',
                'tested_concept' => 'PRECIS distinction',
                'difference_from_source' => 'Changes recall into comparison.',
                'explanation' => 'PRECIS identifies an indexing system rather than an unrelated library tool.',
                'source_question_id' => null,
                'item_number' => $itemNumber,
                'question_text' => "Bulk question {$itemNumber}",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Correct answer',
            ]);
        }

        Sanctum::actingAs($staff);

        $this->patchJson("/api/library/banks/{$bank->id}/remedial-questions/status", [
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'current_status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
        ])
            ->assertOk()
            ->assertJsonPath('updated_count', 2)
            ->assertJsonPath('skipped_count', 0);

        $this->assertDatabaseMissing('question_bank_questions', [
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
        ]);

        $this->assertDatabaseHas('question_bank_questions', [
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'item_number' => 1,
        ]);
    }

    public function test_staff_cannot_view_another_teachers_question_bank_questions(): void
    {
        $owner = $this->makeStaffUser('teacher-owner@example.com');
        $otherStaff = $this->makeStaffUser('teacher-other@example.com');
        $bank = QuestionBank::create([
            'title' => 'Restricted Bank',
            'subject' => 'Reference Services',
            'source_filename' => 'restricted.docx',
            'total_items' => 0,
            'created_by' => $owner->id,
        ]);

        Sanctum::actingAs($otherStaff);

        $this->getJson("/api/library/banks/{$bank->id}/questions")
            ->assertForbidden();
    }

    private function makeStaffUser(string $email): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
        ]);
    }
}
