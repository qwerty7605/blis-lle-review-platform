<?php

namespace Tests\Feature\Exams;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamSeries;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Every exam has a type; a pre-test or a mock board is one of six subject
 * exams in a series of the same type, one per board subject. Changing the
 * type of an exam with results is written to the audit log.
 */
class ExamTypesAndSeriesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        Sanctum::actingAs($this->teacher);
    }

    private function bank(string $subject): QuestionBank
    {
        return $this->makeBank($this->teacher, 3, ['subject' => $subject]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function body(QuestionBank $bank, array $overrides = []): array
    {
        return [
            'title' => 'Exam ' . uniqid(),
            'question_bank_ids' => [$bank->id],
            'total_items' => 3,
            'duration_minutes' => 30,
            ...$overrides,
        ];
    }

    private function series(string $name = '2026 Pre-test', string $type = Exam::TYPE_PRE_TEST): ExamSeries
    {
        return ExamSeries::create(['name' => $name, 'type' => $type, 'created_by' => $this->teacher->id]);
    }

    public function test_an_exam_is_a_regular_mock_exam_unless_another_type_is_chosen(): void
    {
        $regular = $this->postJson('/api/exams', $this->body($this->bank('Cataloging')))->assertCreated();
        $this->assertSame('regular', $regular->json('exam.exam_type'));
        $this->assertSame('Regular mock exam', $regular->json('exam.exam_type_label'));

        $battery = $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['exam_type' => 'battery']))->assertCreated();
        $this->assertSame('Battery exam', $battery->json('exam.exam_type_label'));
        $this->assertNull($battery->json('exam.exam_series_id'));

        $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['exam_type' => 'final']))->assertStatus(422);

        // The type reaches the Exams list.
        $this->getJson('/api/exams')->assertOk()->assertJsonFragment(['exam_type_label' => 'Battery exam']);
    }

    public function test_a_pre_test_needs_a_series_of_its_own_type_and_one_board_subject(): void
    {
        $preTest = $this->series();
        $mockBoard = $this->series('2026 Mock Board 1', Exam::TYPE_MOCK_BOARD);

        $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['exam_type' => 'pre_test']))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A pre-test is one of six subject exams in a series. Choose its series, or create one.');

        $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['exam_type' => 'pre_test', 'exam_series_id' => $mockBoard->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', '"2026 Mock Board 1" is a mock board series; this exam is a pre-test.');

        $this->postJson('/api/exams', $this->body($this->bank('General'), ['exam_type' => 'pre_test', 'exam_series_id' => $preTest->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'A pre-test exam must draw on one board subject; this one\'s question sets are "General".');

        $mixed = $this->postJson('/api/exams', [
            ...$this->body($this->bank('Cataloging'), ['exam_type' => 'pre_test', 'exam_series_id' => $preTest->id]),
            'question_bank_ids' => [$this->bank('Cataloging')->id, $this->bank('Information Technology')->id],
        ])->assertStatus(422);
        $this->assertStringContainsString('"Multiple Subjects"', $mixed->json('message'));

        // An old spelling of the subject is the same subject.
        $created = $this->postJson('/api/exams', $this->body($this->bank('Reference Services'), ['exam_type' => 'pre_test', 'exam_series_id' => $preTest->id]))
            ->assertCreated();
        $this->assertSame('reference', $created->json('exam.series_subject'));
        $this->assertSame('2026 Pre-test', $created->json('exam.series.name'));
    }

    public function test_a_series_holds_one_exam_per_subject(): void
    {
        $series = $this->series();
        $first = $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['title' => 'Pre-test: Cataloging', 'exam_type' => 'pre_test', 'exam_series_id' => $series->id]))
            ->assertCreated();

        $this->postJson('/api/exams', $this->body($this->bank('Cataloging and Classification'), ['exam_type' => 'pre_test', 'exam_series_id' => $series->id]))
            ->assertStatus(422)
            ->assertJsonPath('message', '"2026 Pre-test" already has its Cataloging and Classification exam ("Pre-test: Cataloging").');

        // Saving the same exam again is not a clash with itself.
        $this->patchJson('/api/exams/' . $first->json('exam.id'), ['title' => 'Pre-test: Cataloging (revised)'])->assertOk();

        $listed = collect($this->getJson('/api/exam-series')->assertOk()->json('series'))->firstWhere('id', $series->id);
        $this->assertSame(['Cataloging and Classification'], array_column($listed['exams'], 'subject'));
        $this->assertCount(5, $listed['missing_subjects']);
    }

    public function test_changing_the_type_away_from_a_series_type_leaves_the_series(): void
    {
        $series = $this->series();
        $exam = $this->postJson('/api/exams', $this->body($this->bank('Cataloging'), ['exam_type' => 'pre_test', 'exam_series_id' => $series->id]))->assertCreated();

        $updated = $this->patchJson('/api/exams/' . $exam->json('exam.id'), ['exam_type' => 'regular'])->assertOk();

        $this->assertSame('regular', $updated->json('exam.exam_type'));
        $this->assertNull($updated->json('exam.exam_series_id'));
        $this->assertNull($updated->json('exam.series_subject'));
    }

    public function test_changing_the_type_of_an_exam_with_results_is_written_to_the_audit_log(): void
    {
        $student = $this->makeStudent();
        $room = $this->makeRoom($this->teacher, [$student]);
        $bank = $this->bank('Cataloging');
        $withResults = $this->makeExam($this->teacher, $bank, [$room], ['title' => 'Graded']);
        $this->makeSubmittedAttempt($withResults, $room, $student, [true, false, true]);
        $withoutResults = $this->makeExam($this->teacher, $bank, [$room], ['title' => 'Untaken']);

        $this->patchJson("/api/exams/{$withoutResults->id}", ['exam_type' => 'battery'])->assertOk();
        $this->assertFalse(AuditLog::where('action', 'exam.type.change')->exists());

        $this->patchJson("/api/exams/{$withResults->id}", ['exam_type' => 'pre_test', 'exam_series_id' => $this->series()->id])->assertOk();

        $entry = AuditLog::where('action', 'exam.type.change')->sole();
        $this->assertSame($withResults->id, (int) $entry->target_id);
        $this->assertStringContainsString('from Regular mock exam to Pre-test with 1 attempt(s)', $entry->description);
        $this->assertSame('pre_test', $entry->metadata['to_type']);
    }

    public function test_series_are_shared_but_only_their_creator_or_an_admin_renames_or_deletes_them(): void
    {
        $created = $this->postJson('/api/exam-series', ['name' => '2026 Mock Board 1', 'type' => 'mock_board'])->assertCreated();
        $id = $created->json('series.id');
        $this->assertSame('Mock board', $created->json('series.type_label'));
        $this->assertCount(6, $created->json('series.missing_subjects'));

        $this->postJson('/api/exam-series', ['name' => '2026 Mock Board 1', 'type' => 'mock_board'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'A series with that name already exists.');
        $this->postJson('/api/exam-series', ['name' => 'Battery series', 'type' => 'battery'])->assertStatus(422);

        // Another teacher sees it and can put an exam in it, but not rename it.
        $other = $this->makeTeacher();
        Sanctum::actingAs($other);
        $this->assertContains($id, array_column($this->getJson('/api/exam-series')->json('series'), 'id'));
        $otherBank = $this->makeBank($other, 3, ['subject' => 'Indexing']);
        $this->postJson('/api/exams', $this->body($otherBank, ['exam_type' => 'mock_board', 'exam_series_id' => $id]))->assertCreated();
        $this->patchJson("/api/exam-series/{$id}", ['name' => 'Renamed'])->assertForbidden();

        // A series with an exam in it is not deleted.
        Sanctum::actingAs($this->teacher);
        $this->patchJson("/api/exam-series/{$id}", ['name' => '2026 Mock Board One'])->assertOk()->assertJsonPath('series.name', '2026 Mock Board One');
        $this->deleteJson("/api/exam-series/{$id}")->assertStatus(422);

        $empty = $this->series('Empty');
        Sanctum::actingAs($this->makeAdmin());
        $this->deleteJson("/api/exam-series/{$empty->id}")->assertOk();
        $this->assertNull(ExamSeries::find($empty->id));
        $this->assertTrue(AuditLog::where('action', 'exam.series.delete')->exists());

        Sanctum::actingAs($this->makeStudent());
        $this->getJson('/api/exam-series')->assertForbidden();
    }
}
