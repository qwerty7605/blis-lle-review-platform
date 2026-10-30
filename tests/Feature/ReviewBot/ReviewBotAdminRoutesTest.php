<?php

namespace Tests\Feature\ReviewBot;

use App\Jobs\GenerateReviewBotQuestionsJob;
use App\Models\AuditLog;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The teacher's Review Bot routes (routes/api/review-bot-admin.php):
 * generating drafts from a Library file, listing and editing them,
 * publishing, unpublishing, declining and deleting, and the coverage report.
 */
class ReviewBotAdminRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Cataloging and Classification';

    private User $teacher;

    private QuestionBank $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->source = $this->makeBank($this->teacher, 3, ['subject' => self::SUBJECT]);

        Sanctum::actingAs($this->teacher);
    }

    /** @return array<int, QuestionBankQuestion> */
    private function drafts(int $count, string $status = QuestionBankQuestion::STATUS_DRAFT): array
    {
        $bank = QuestionBank::firstOrCreate(
            ['title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . self::SUBJECT, 'subject' => self::SUBJECT],
            ['created_by' => $this->teacher->id, 'total_items' => 0],
        );

        $drafts = [];
        $already = QuestionBankQuestion::where('question_bank_id', $bank->id)->count();
        for ($number = 1; $number <= $count; $number++) {
            $drafts[] = $this->makeQuestion($bank, $already + $number, [
                'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                'status' => $status,
                'subject' => self::SUBJECT,
                'source_question_id' => $this->source->questions[($number - 1) % 3]->id,
            ]);
        }

        return $drafts;
    }

    public function test_generating_from_a_library_file_queues_the_job_and_is_audited(): void
    {
        Queue::fake();

        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => $this->source->id])
            ->assertOk()
            ->assertJsonPath('status', 'queued');

        Queue::assertPushed(GenerateReviewBotQuestionsJob::class);
        $this->assertTrue(AuditLog::where('action', 'review-bot.admin.generate')->exists());
    }

    public function test_a_file_with_no_subject_or_no_questions_is_skipped_with_the_reason(): void
    {
        Queue::fake();
        $noSubject = $this->makeBank($this->teacher, 2, ['subject' => '']);
        $noQuestions = QuestionBank::create(['title' => 'Empty', 'subject' => self::SUBJECT, 'total_items' => 0, 'created_by' => $this->teacher->id]);

        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => $noSubject->id])
            ->assertOk()
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('message', 'This file has no subject set yet. Assign it a subject in the Library before generating review questions from it.');

        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => $noQuestions->id])
            ->assertOk()
            ->assertJsonPath('status', 'skipped')
            ->assertJsonPath('message', 'This file has no exam questions to generate from.');

        Queue::assertNothingPushed();
    }

    public function test_a_file_that_is_missing_or_belongs_to_another_teacher_is_not_found(): void
    {
        $others = $this->makeBank($this->makeTeacher(), 1, ['subject' => self::SUBJECT]);

        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => 999999])
            ->assertNotFound()
            ->assertJsonPath('message', 'That file was not found in your Library.');
        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => $others->id])->assertNotFound();
    }

    public function test_the_list_filters_by_status_and_counts_every_status_for_a_file(): void
    {
        $this->drafts(2);
        $this->drafts(1, QuestionBankQuestion::STATUS_PUBLISHED);

        $response = $this->getJson('/api/library/review-bot/questions?source_bank_id=' . $this->source->id . '&status=draft')->assertOk();

        $this->assertCount(2, $response->json('questions'));
        $this->assertFalse($response->json('generating'));
        $this->assertSame(2, $response->json('counts.draft'));
        $this->assertSame(1, $response->json('counts.published'));

        $this->assertNull($this->getJson('/api/library/review-bot/questions?subject=' . urlencode(self::SUBJECT))->assertOk()->json('counts'));
    }

    public function test_editing_a_draft_changes_its_wording_choices_and_topic(): void
    {
        [$draft] = $this->drafts(1);
        $options = $draft->options;

        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", [
            'question_text' => 'Reworded question?',
            'explanation' => 'Because of the rule.',
            'difficulty' => 'hard',
            'cognitive_level' => 'analysis',
            'topic_name' => '2. Descriptive Cataloging',
            'subtopic_name' => '2.6 Design a name authority file',
            'options' => [
                ['id' => $options[0]->id, 'text' => 'Now wrong', 'is_correct' => false],
                ['id' => $options[1]->id, 'text' => 'Now right', 'is_correct' => true],
                ['id' => 0, 'text' => 'ignored'],
            ],
        ])->assertOk()->assertJsonPath('question.question_text', 'Reworded question?');

        $draft->refresh()->load('topic', 'subtopic');
        $this->assertSame('hard', $draft->difficulty);
        $this->assertSame('analysis', $draft->cognitive_level);
        $this->assertSame('2. Descriptive Cataloging', $draft->topic->name);
        $this->assertSame('2.6 Design a name authority file', $draft->subtopic->name);
        $this->assertSame('B', $draft->answer_label);
        $this->assertSame('Now right', $draft->answer_text);
        $this->assertTrue(AuditLog::where('action', 'review-bot.admin.update-question')->exists());
    }

    public function test_a_new_topic_clears_the_old_subtopic_and_an_empty_subtopic_removes_it(): void
    {
        [$draft] = $this->drafts(1);
        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['topic_name' => '2. Descriptive Cataloging', 'subtopic_name' => '2.6 Design a name authority file'])->assertOk();

        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['topic_name' => '3. Subject Cataloging'])->assertOk();
        $this->assertNull($draft->fresh()->subtopic_id);

        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['subtopic_name' => '3.4'])->assertOk();
        $this->assertNotNull($draft->fresh()->subtopic_id);
        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['subtopic_name' => ''])->assertOk();
        $this->assertNull($draft->fresh()->subtopic_id);

        // Only the Table of Specifications' topics and sub-topics are taken.
        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['topic_name' => 'Invented Topic'])
            ->assertStatus(422)
            ->assertJsonPath('message', '"Invented Topic" is not a topic of Cataloging and Classification in the Table of Specifications.');
        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['subtopic_name' => '1.1'])->assertStatus(422);
    }

    public function test_publish_unpublish_and_decline_only_count_rows_that_change(): void
    {
        $drafts = $this->drafts(3);
        $ids = array_map(fn ($draft) => $draft->id, $drafts);

        $this->postJson('/api/library/review-bot/publish', ['question_ids' => $ids])->assertOk()->assertJsonPath('published', 3);
        $this->postJson('/api/library/review-bot/publish', ['question_ids' => $ids])->assertOk()->assertJsonPath('published', 0);

        $this->postJson('/api/library/review-bot/unpublish', ['question_ids' => [$ids[0]]])->assertOk()->assertJsonPath('unpublished', 1);
        $this->postJson('/api/library/review-bot/unpublish', ['question_ids' => [$ids[0]]])->assertOk()->assertJsonPath('unpublished', 0);

        $this->postJson('/api/library/review-bot/reject', ['question_ids' => $ids])->assertOk()->assertJsonPath('rejected', 3);
        $this->postJson('/api/library/review-bot/reject', ['question_ids' => $ids])->assertOk()->assertJsonPath('rejected', 0);

        foreach (['publish', 'unpublish', 'reject'] as $action) {
            $this->assertTrue(AuditLog::where('action', 'review-bot.admin.' . $action)->exists(), $action);
        }
    }

    public function test_the_coverage_report_lists_each_exam_item_or_nothing_for_an_empty_subject(): void
    {
        $this->drafts(1);

        $coverage = $this->getJson('/api/library/review-bot/coverage?subject=' . urlencode(self::SUBJECT) . '&source_bank_id=' . $this->source->id)->assertOk()->json('coverage');
        $this->assertCount(3, $coverage);

        $this->getJson('/api/library/review-bot/coverage?subject=Nothing Here')->assertOk()->assertJsonPath('coverage', []);
        $this->getJson('/api/library/review-bot/coverage')->assertJsonValidationErrors('subject');
    }

    public function test_a_review_bot_question_is_deleted(): void
    {
        [$draft] = $this->drafts(1);

        $this->deleteJson("/api/library/review-bot/questions/{$draft->id}")->assertOk()->assertJsonPath('deleted', true);

        $this->assertNull(QuestionBankQuestion::find($draft->id));
        $this->assertTrue(AuditLog::where('action', 'review-bot.admin.delete-question')->exists());
    }

    public function test_an_exam_question_cannot_be_edited_or_deleted_through_the_review_bot(): void
    {
        $examQuestion = $this->source->questions->first();

        $this->patchJson("/api/library/review-bot/questions/{$examQuestion->id}", ['question_text' => 'x'])->assertForbidden();
        $this->deleteJson("/api/library/review-bot/questions/{$examQuestion->id}")->assertForbidden();
    }

    public function test_students_cannot_use_any_review_bot_admin_route(): void
    {
        [$draft] = $this->drafts(1);
        Sanctum::actingAs($this->makeStudent());

        $this->postJson('/api/library/review-bot/generate', ['source_bank_id' => $this->source->id])->assertForbidden();
        $this->getJson('/api/library/review-bot/questions')->assertForbidden();
        $this->patchJson("/api/library/review-bot/questions/{$draft->id}", ['question_text' => 'x'])->assertForbidden();
        $this->postJson('/api/library/review-bot/publish', ['question_ids' => [$draft->id]])->assertForbidden();
        $this->postJson('/api/library/review-bot/reject', ['question_ids' => [$draft->id]])->assertForbidden();
        $this->postJson('/api/library/review-bot/unpublish', ['question_ids' => [$draft->id]])->assertForbidden();
        $this->getJson('/api/library/review-bot/coverage?subject=x')->assertForbidden();
        $this->deleteJson("/api/library/review-bot/questions/{$draft->id}")->assertForbidden();
    }

    public function test_a_disabled_teacher_is_signed_out_of_the_review_bot_admin_routes(): void
    {
        [$draft] = $this->drafts(1);
        $this->teacher->forceFill(['is_active' => false])->save();

        foreach ([
            ['POST', '/api/library/review-bot/generate'],
            ['GET', '/api/library/review-bot/questions'],
            ['PATCH', "/api/library/review-bot/questions/{$draft->id}"],
            ['POST', '/api/library/review-bot/publish'],
            ['POST', '/api/library/review-bot/reject'],
            ['GET', '/api/library/review-bot/coverage'],
            ['POST', '/api/library/review-bot/unpublish'],
            ['DELETE', "/api/library/review-bot/questions/{$draft->id}"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
