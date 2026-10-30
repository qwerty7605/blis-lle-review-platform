<?php

namespace Tests\Feature;

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Topic;
use App\Models\User;
use App\Services\QuestionMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the simplified upload flow (subject auto-detection) and the new
 * post-upload editing capability: renaming/re-subjecting a bank (with its
 * reclassification cascade) and editing a single item's content and
 * classification together.
 */
class LibraryEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggest_subject_for_parsed_questions_detects_strong_signal(): void
    {
        $parsed = [
            [
                'text' => 'Which classification system uses call numbers such as 025.4 and is known as the Dewey Decimal Classification?',
                'answer_text' => 'Dewey Decimal Classification',
                'options' => [
                    ['text' => 'Dewey Decimal Classification'],
                    ['text' => 'Universal Decimal Classification'],
                ],
            ],
            [
                'text' => 'The Library of Congress Classification (LCC) organizes materials by broad subject class letters.',
                'answer_text' => 'Library of Congress Classification',
                'options' => [],
            ],
        ];

        $result = app(QuestionMappingService::class)->suggestSubjectForParsedQuestions($parsed);

        $this->assertSame('Cataloging and Classification', $result['subject']);
        $this->assertGreaterThan(0.0, $result['confidence']);
    }

    public function test_suggest_subject_for_parsed_questions_returns_null_with_no_signal(): void
    {
        $parsed = [
            ['text' => 'Please read the passage below and answer accordingly.', 'answer_text' => '', 'options' => []],
        ];

        $result = app(QuestionMappingService::class)->suggestSubjectForParsedQuestions($parsed);

        $this->assertNull($result['subject']);
    }

    public function test_bank_creation_derives_answer_from_correct_option(): void
    {
        $staff = $this->makeUser('teacher-create@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/library/banks', [
            'title' => 'Created Via Test',
            'subject' => 'Cataloging and Classification',
            'ai_tagging_mode' => 'auto',
            'questions' => [
                [
                    'text' => 'Which is correct?',
                    'question_type' => 'multiple_choice',
                    'options' => [
                        ['label' => 'A', 'text' => 'Wrong', 'is_correct' => false],
                        ['label' => 'B', 'text' => 'Right', 'is_correct' => true],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();

        $bank = QuestionBank::where('title', 'Created Via Test')->firstOrFail();
        $question = $bank->assessmentQuestions()->firstOrFail();

        $this->assertSame('B', $question->answer_label);
        $this->assertSame('Right', $question->answer_text);
    }

    public function test_bank_update_changes_title_and_subject_and_cascades_reclassification(): void
    {
        $staff = $this->makeUser('teacher-edit@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $bank = QuestionBank::create([
            'title' => 'Original Title',
            'subject' => 'Cataloging and Classification',
            'source_filename' => 'original.docx',
            'total_items' => 2,
            'created_by' => $staff->id,
        ]);

        $topic = Topic::create([
            'subject' => 'Cataloging and Classification',
            'name' => '4. Classification',
            'created_by' => $staff->id,
        ]);

        $ruleBasedQuestion = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'topic_id' => $topic->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'cognitive_level' => 'recall',
            'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
            'tag_source' => QuestionBankQuestion::TAG_SOURCE_RULE_BASED,
            'item_number' => 1,
            'question_text' => 'A boolean operator such as AND, OR, or NOT is used in a boolean search of the OPAC.',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Combines search terms',
        ]);
        $ruleBasedQuestion->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Combines search terms', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Prints the shelf list', 'is_correct' => false],
        ]);

        $facultyQuestion = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'topic_id' => $topic->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_HARD,
            'cognitive_level' => 'analysis',
            'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
            'tag_source' => QuestionBankQuestion::TAG_SOURCE_FACULTY,
            'item_number' => 2,
            'question_text' => 'A manually tagged item.',
            'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
        ]);

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/library/banks/{$bank->id}", [
            'title' => 'Renamed Title',
            'subject' => 'Information Technology',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('bank.title', 'Renamed Title')
            ->assertJsonPath('bank.subject', 'Information Technology');

        $ruleBasedQuestion->refresh();
        $facultyQuestion->refresh();

        // The question's own `subject` column stays in sync with the bank's.
        $this->assertSame('Information Technology', $ruleBasedQuestion->subject);
        $this->assertSame('Information Technology', $facultyQuestion->subject);

        // Rule-based tag was reset and re-classified (sync queue) under the new subject.
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $ruleBasedQuestion->tag_source);
        $this->assertNotNull($ruleBasedQuestion->topic_id, 'a best-effort guess should still be written');

        // A teacher's own manually confirmed tag survives the subject change untouched.
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_FACULTY, $facultyQuestion->tag_source);
        $this->assertSame($topic->id, $facultyQuestion->topic_id);
        $this->assertSame(QuestionBankQuestion::DIFFICULTY_HARD, $facultyQuestion->difficulty);
    }

    public function test_bank_update_without_subject_change_does_not_touch_tags(): void
    {
        $staff = $this->makeUser('teacher-rename@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $bank = QuestionBank::create([
            'title' => 'Old Name',
            'subject' => 'Library Organization and Management',
            'source_filename' => null,
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $topic = Topic::create(['subject' => 'Library Organization and Management', 'name' => 'SWOT Topic', 'created_by' => $staff->id]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Library Organization and Management',
            'topic_id' => $topic->id,
            'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
            'tag_source' => QuestionBankQuestion::TAG_SOURCE_RULE_BASED,
            'item_number' => 1,
            'question_text' => 'Some question.',
            'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
        ]);

        Sanctum::actingAs($staff);

        $this->patchJson("/api/library/banks/{$bank->id}", [
            'title' => 'New Name',
            'subject' => 'Library Organization and Management',
        ])->assertOk();

        $question->refresh();

        $this->assertSame($topic->id, $question->topic_id);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_RULE_BASED, $question->tag_source);
    }

    public function test_question_update_edits_content_and_classification_together(): void
    {
        $staff = $this->makeUser('teacher-item-edit@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $bank = QuestionBank::create([
            'title' => 'Edit Target Bank',
            'subject' => 'Cataloging and Classification',
            'source_filename' => 'edit.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'item_number' => 1,
            'question_text' => 'Old question text?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Old answer',
        ]);
        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Old answer', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Old distractor', 'is_correct' => false],
        ]);

        Sanctum::actingAs($staff);

        $response = $this->patchJson("/api/library/questions/{$question->id}", [
            'question_text' => 'What is the correct classification for this item?',
            'question_type' => 'multiple_choice',
            'options' => [
                ['label' => 'A', 'text' => 'New wrong answer', 'is_correct' => false],
                ['label' => 'B', 'text' => 'New correct answer', 'is_correct' => true],
            ],
            'topic_name' => '4. Classification',
            'subtopic_name' => '4.3 Summarize the historical background and general characteristics of DDC',
            'difficulty' => 'hard',
            'cognitive_level' => 'analysis',
        ]);

        $response->assertOk();

        $question->refresh()->load(['options', 'topic', 'subtopic']);

        $this->assertSame('What is the correct classification for this item?', $question->question_text);
        $this->assertCount(2, $question->options);
        $this->assertSame('B', $question->answer_label);
        $this->assertSame('New correct answer', $question->answer_text);
        $this->assertSame('4. Classification', $question->topic?->name);
        $this->assertSame('4.3 Summarize the historical background and general characteristics of DDC', $question->subtopic?->name);
        $this->assertSame('hard', $question->difficulty);
        $this->assertSame('analysis', $question->cognitive_level);
        $this->assertSame(QuestionBankQuestion::TAG_SOURCE_FACULTY, $question->tag_source);
        $this->assertSame(QuestionBankQuestion::TAG_STATUS_CONFIRMED, $question->tag_status);

        // The list a question is edited from carries the whole mapping. It
        // used to leave the subtopic out, so the edit form opened without it
        // and saving the question again cleared it.
        $this->getJson("/api/library/banks/{$bank->id}/questions")
            ->assertOk()
            ->assertJsonPath('questions.0.topic.name', '4. Classification')
            ->assertJsonPath('questions.0.subtopic.name', '4.3 Summarize the historical background and general characteristics of DDC')
            ->assertJsonPath('questions.0.difficulty', 'hard')
            ->assertJsonPath('questions.0.cognitive_level', 'analysis');
    }

    public function test_the_bank_list_counts_tags_still_to_confirm_until_they_are_confirmed(): void
    {
        $staff = $this->makeUser('teacher-confirm@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $bank = QuestionBank::create([
            'title' => 'Tagged Bank',
            'subject' => 'Reference, Bibliography and User Services',
            'total_items' => 3,
            'mapping_status' => QuestionBank::MAPPING_STATUS_COMPLETED,
            'created_by' => $staff->id,
        ]);

        // The tagger was sure of one and unsure of two.
        foreach ([
            QuestionBankQuestion::TAG_STATUS_CONFIRMED,
            QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW,
            QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW,
        ] as $index => $status) {
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => 'Reference, Bibliography and User Services',
                'item_number' => $index + 1,
                'question_text' => 'Question ' . ($index + 1) . '?',
                'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
                'tag_status' => $status,
                'tag_source' => QuestionBankQuestion::TAG_SOURCE_RULE_BASED,
            ]);
        }

        Sanctum::actingAs($staff);

        $this->getJson('/api/library/banks')
            ->assertOk()
            ->assertJsonPath('banks.0.pending_tags_count', 2)
            ->assertJsonPath('banks.0.confirmed_tags_count', 1);

        $this->patchJson("/api/library/banks/{$bank->id}/tags/status")
            ->assertOk()
            ->assertJsonPath('confirmed_count', 2);

        $this->getJson('/api/library/banks')
            ->assertJsonPath('banks.0.pending_tags_count', 0)
            ->assertJsonPath('banks.0.confirmed_tags_count', 3);

        $this->getJson("/api/library/banks/{$bank->id}/questions")
            ->assertJsonMissing(['tag_status' => QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW]);
    }

    public function test_question_update_to_open_ended_clears_options(): void
    {
        $staff = $this->makeUser('teacher-open-ended@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $bank = QuestionBank::create([
            'title' => 'Open Ended Bank',
            'subject' => 'Reference, Bibliography and User Services',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Reference, Bibliography and User Services',
            'item_number' => 1,
            'question_text' => 'A multiple choice question.',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Choice A',
        ]);
        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Choice A', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Choice B', 'is_correct' => false],
        ]);

        Sanctum::actingAs($staff);

        $this->patchJson("/api/library/questions/{$question->id}", [
            'question_text' => 'Now an open-ended question.',
            'question_type' => 'open_ended',
            'answer_text' => 'A free-form expected answer.',
            'options' => [],
        ])->assertOk();

        $question->refresh()->load('options');

        $this->assertSame(QuestionBankQuestion::TYPE_OPEN_ENDED, $question->question_type);
        $this->assertCount(0, $question->options);
        $this->assertNull($question->answer_label);
        $this->assertSame('A free-form expected answer.', $question->answer_text);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'is_active' => true,
        ]);
    }
}
