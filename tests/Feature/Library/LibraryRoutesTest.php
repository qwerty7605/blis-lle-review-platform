<?php

namespace Tests\Feature\Library;

use App\Jobs\ClassifyQuestionBankQuestionsJob;
use App\Models\AuditLog;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;
use ZipArchive;

/**
 * The Library's routes (routes/api/library.php): reading a Word file into a
 * preview, saving a set, editing and deleting it, the topic lists, queueing
 * and confirming topic tags, and editing one question. Each is checked for
 * its result and for who may use it.
 */
class LibraryRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Style::resetStyles();
        $this->teacher = $this->makeTeacher();
        Sanctum::actingAs($this->teacher);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function upload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    private function tempFile(string $contents, string $suffix = '.docx'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lib') . $suffix;
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    // --- Preview -------------------------------------------------------------

    public function test_a_word_file_is_read_into_a_preview_with_a_suggested_subject(): void
    {
        $path = base_path('docs/testing/Sample-Cataloging-10-items.docx');

        $response = $this->post('/api/library/import/preview', [
            'file' => $this->upload($path, 'Sample-Cataloging-10-items.docx'),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(10, $response->json('preview.total_items'));
        $this->assertSame('Sample-Cataloging-10-items.docx', $response->json('preview.source_filename'));
        $this->assertArrayHasKey('suggested_subject', $response->json('preview'));
        $this->assertTrue(AuditLog::where('action', 'library.import.preview')->exists());
    }

    public function test_a_word_file_with_no_questions_is_refused_with_its_warnings(): void
    {
        $word = new PhpWord();
        $word->addSection()->addText('Just a title page, nothing to answer here');
        $path = tempnam(sys_get_temp_dir(), 'lib') . '.docx';
        $this->files[] = $path;
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->post('/api/library/import/preview', ['file' => $this->upload($path, 'empty.docx')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No valid question pattern detected from this DOCX file.')
            ->assertJsonPath('preview.total_items', 0);
    }

    public function test_an_old_doc_file_is_refused_with_how_to_fix_it(): void
    {
        $this->post('/api/library/import/preview', [
            'file' => $this->upload($this->tempFile('binary', '.doc'), 'exam.doc'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'Legacy .doc files are not supported']);
    }

    public function test_a_file_that_is_not_word_at_all_is_refused(): void
    {
        $this->post('/api/library/import/preview', [
            'file' => $this->upload($this->tempFile('plain text', '.txt'), 'notes.txt'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'Please upload a valid .docx file.']);
    }

    public function test_a_docx_that_is_not_a_zip_is_refused_as_corrupted(): void
    {
        $this->post('/api/library/import/preview', [
            'file' => $this->upload($this->tempFile('this is not a zip'), 'broken.docx'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'invalid or corrupted']);
    }

    public function test_a_zip_missing_the_word_parts_is_refused_as_corrupted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lib') . '.docx';
        $this->files[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'not a document');
        $zip->close();

        $this->post('/api/library/import/preview', ['file' => $this->upload($path, 'fake.docx')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file' => 'invalid or corrupted']);
    }

    public function test_a_docx_whose_contents_cannot_be_parsed_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lib') . '.docx';
        $this->files[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('_rels/.rels', '<Relationships/>');
        $zip->addFromString('word/document.xml', 'not xml at all <<<');
        $zip->close();

        $this->post('/api/library/import/preview', ['file' => $this->upload($path, 'garbled.docx')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_a_parser_failure_is_answered_with_a_plain_message(): void
    {
        $this->mock(\App\Services\Library\DocxQuestionParser::class)
            ->shouldReceive('parse')
            ->andThrow(new \RuntimeException('bad numbering'));

        $this->post('/api/library/import/preview', [
            'file' => $this->upload(base_path('docs/testing/Sample-Cataloging-10-items.docx'), 'a.docx'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Unable to parse DOCX file. Please check the file format.');
    }

    public function test_a_student_cannot_preview_a_file(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->post('/api/library/import/preview', [
            'file' => $this->upload(base_path('docs/testing/Sample-Cataloging-10-items.docx'), 'a.docx'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    // --- Saving a set ------------------------------------------------------

    public function test_a_set_is_saved_with_its_file_name_and_a_teacher_entered_topic(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/library/banks', [
            'title' => '  Reference Set  ',
            // An old spelling is saved under the official name.
            'subject' => 'Reference Services',
            'source_filename' => ' reference.docx ',
            'questions' => [
                [
                    'text' => 'What is a ready reference question?',
                    'topic_name' => '3. Reference and Information Services',
                    'options' => [
                        ['label' => 'A', 'text' => 'A quick factual question', 'is_correct' => true],
                        ['label' => 'B', 'text' => 'A research project'],
                    ],
                ],
            ],
        ])->assertCreated();

        $bank = QuestionBank::findOrFail($response->json('bank.id'));
        $this->assertSame('Reference Set', $bank->title);
        $this->assertSame('reference.docx', $bank->source_filename);
        $this->assertSame('Reference, Bibliography and User Services', $bank->subject);
        $question = $bank->questions()->first();
        $this->assertSame('3. Reference and Information Services', $question->topic?->name);
        $this->assertSame('3', $question->topic?->code);
        $this->assertSame(45, $question->topic?->tos_items);
    }

    public function test_a_set_with_no_subject_is_saved_without_one(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/library/banks', [
            'title' => 'Mixed Set',
            'questions' => [['text' => 'True or false: a catalog lists holdings.', 'question_type' => 'true_false', 'answer_label' => 'A']],
        ])->assertCreated();

        $this->assertNull(QuestionBank::findOrFail($response->json('bank.id'))->subject);
    }

    public function test_a_student_cannot_save_a_set(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->postJson('/api/library/banks', ['title' => 'X', 'questions' => [['text' => 'Q?']]])->assertForbidden();
    }

    // --- Editing and deleting a set -----------------------------------------

    public function test_a_review_bot_bank_cannot_be_edited_or_deleted_from_the_library(): void
    {
        $bank = $this->makeBank($this->teacher, 1, ['title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . 'Reference']);

        $this->patchJson("/api/library/banks/{$bank->id}", ['title' => 'Renamed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This is a Review Bot bank, not a Library set -- it cannot be edited here.');
        $this->deleteJson("/api/library/banks/{$bank->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This is a Review Bot bank, not a Library set -- it cannot be deleted here.');
    }

    public function test_an_unused_set_is_deleted_and_audited(): void
    {
        $bank = $this->makeBank($this->teacher, 2);

        $this->deleteJson("/api/library/banks/{$bank->id}")->assertOk()->assertJsonPath('message', 'Question bank deleted.');

        $this->assertNull(QuestionBank::find($bank->id));
        $this->assertTrue(AuditLog::where('action', 'library.bank.delete')->exists());
    }

    public function test_a_set_students_have_answered_cannot_be_deleted(): void
    {
        $student = $this->makeStudent();
        $room = $this->makeRoom($this->teacher, [$student]);
        $bank = $this->makeBank($this->teacher, 2);
        $exam = $this->makeExam($this->teacher, $bank, [$room]);
        $this->makeSubmittedAttempt($exam, $room, $student, [true, false]);

        $this->deleteJson("/api/library/banks/{$bank->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete this question bank because it already has exam attempt records.');
    }

    public function test_only_the_owner_or_an_administrator_may_edit_or_delete_a_set(): void
    {
        $bank = $this->makeBank($this->teacher, 1);

        Sanctum::actingAs($this->makeTeacher());
        $this->patchJson("/api/library/banks/{$bank->id}", ['title' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/library/banks/{$bank->id}")->assertForbidden();
        $this->getJson("/api/library/banks/{$bank->id}/questions")->assertForbidden();
        $this->postJson("/api/library/banks/{$bank->id}/queue-mapping")->assertForbidden();
        $this->patchJson("/api/library/banks/{$bank->id}/tags/status")->assertForbidden();
        $this->patchJson('/api/library/questions/' . $bank->questions->first()->id, ['question_text' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->makeStudent());
        foreach (['/api/library/banks', "/api/library/banks/{$bank->id}/questions", '/api/library/topics', '/api/library/mapping-queue'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->patchJson("/api/library/banks/{$bank->id}", ['title' => 'x'])->assertForbidden();
        $this->deleteJson("/api/library/banks/{$bank->id}")->assertForbidden();
        $this->postJson("/api/library/banks/{$bank->id}/queue-mapping")->assertForbidden();
        $this->patchJson("/api/library/banks/{$bank->id}/tags/status")->assertForbidden();
        $this->patchJson('/api/library/questions/' . $bank->questions->first()->id, ['question_text' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->deleteJson("/api/library/banks/{$bank->id}")->assertOk();
    }

    public function test_a_disabled_account_is_signed_out_of_every_library_route(): void
    {
        $bank = $this->makeBank($this->teacher, 1);
        $question = $bank->questions->first();
        $this->teacher->forceFill(['is_active' => false])->save();

        $requests = [
            ['GET', '/api/library/banks'],
            ['POST', '/api/library/import/preview'],
            ['POST', '/api/library/banks'],
            ['PATCH', "/api/library/banks/{$bank->id}"],
            ['DELETE', "/api/library/banks/{$bank->id}"],
            ['GET', "/api/library/banks/{$bank->id}/questions"],
            ['GET', '/api/library/topics'],
            ['POST', "/api/library/banks/{$bank->id}/queue-mapping"],
            ['GET', '/api/library/mapping-queue'],
            ['PATCH', "/api/library/banks/{$bank->id}/tags/status"],
            ['PATCH', "/api/library/questions/{$question->id}"],
        ];

        foreach ($requests as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }

    // --- Topic lists -------------------------------------------------------

    public function test_with_no_subject_the_topic_list_is_the_curated_one(): void
    {
        $this->getJson('/api/library/topics')
            ->assertOk()
            ->assertJsonPath('topics', [])
            ->assertJsonStructure(['curated_topics']);
    }

    public function test_a_subject_s_topics_are_the_tos_topics_in_order_whatever_was_typed_before(): void
    {
        // A topic someone once typed by hand is not offered: the TOS is the list.
        $this->makeTopic('Reference, Bibliography and User Services', 'A Local Topic');

        $response = $this->getJson('/api/library/topics?subject=Reference Services&topic=2. Reference and Information Sources')->assertOk();

        $this->assertSame('Reference, Bibliography and User Services', $response->json('subject'));
        $this->assertSame([
            '1. Knowledge Relating to the Nature, Historical Development and Functions of Reference and Information Services',
            '2. Reference and Information Sources',
            '3. Reference and Information Services',
            'Other / Unclassified',
        ], $response->json('topics'));
        $this->assertSame([
            '2.1 Define the nature and scope of reference and information sources (both general and specific)',
            '2.2 Identify / classify the different types of reference sources',
            '2.3 Evaluate the various reference and information sources',
            '2.4 Utilize appropriate reference sources to various information needs',
        ], $response->json('subtopics'));
        $this->assertSame($response->json('subtopics'), $response->json('curated_subtopics'));
    }

    public function test_without_a_topic_no_subtopic_list_is_sent(): void
    {
        $this->assertArrayNotHasKey('subtopics', $this->getJson('/api/library/topics?subject=Cataloging')->assertOk()->json());
    }

    // --- Topic tagging -----------------------------------------------------

    public function test_queueing_tagging_for_a_set_is_audited_and_reports_its_status(): void
    {
        Queue::fake();
        $bank = $this->makeBank($this->teacher, 2, ['mapping_mode' => QuestionBank::MAPPING_MODE_REVIEW]);

        $response = $this->postJson("/api/library/banks/{$bank->id}/queue-mapping", ['mode' => QuestionBank::MAPPING_MODE_AUTO])->assertOk();

        $this->assertSame($bank->id, $response->json('bank.id'));
        $this->assertArrayHasKey('mapping_generation', $response->json());
        $this->assertTrue(AuditLog::where('action', 'library.mapping.queue')->exists());
        Queue::assertPushed(ClassifyQuestionBankQuestionsJob::class);
    }

    public function test_the_tagging_queue_lists_only_tags_waiting_for_review_in_the_teacher_s_own_sets(): void
    {
        $own = $this->makeBank($this->teacher, 2);
        $own->questions[0]->update(['tag_status' => QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW]);
        $own->questions[1]->update(['tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED]);
        $others = $this->makeBank($this->makeTeacher(), 1);
        $others->questions[0]->update(['tag_status' => QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW]);

        $this->getJson('/api/library/mapping-queue')
            ->assertOk()
            ->assertJsonCount(1, 'questions')
            ->assertJsonPath('questions.0.id', $own->questions[0]->id);

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson('/api/library/mapping-queue')->assertOk()->assertJsonCount(2, 'questions');
    }

    public function test_confirming_all_suggested_tags_says_how_many_or_that_there_were_none(): void
    {
        $bank = $this->makeBank($this->teacher, 3);
        QuestionBankQuestion::where('question_bank_id', $bank->id)->update(['tag_status' => QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW]);

        $this->patchJson("/api/library/banks/{$bank->id}/tags/status")
            ->assertOk()
            ->assertJsonPath('confirmed_count', 3)
            ->assertJsonPath('message', 'Confirmed 3 suggested tag(s) for this set.');

        $this->patchJson("/api/library/banks/{$bank->id}/tags/status")
            ->assertOk()
            ->assertJsonPath('confirmed_count', 0)
            ->assertJsonPath('message', 'No pending tag suggestions were found for this set.');

        $this->assertTrue(AuditLog::where('action', 'library.mapping.bulk_confirm')->exists());
    }
}
