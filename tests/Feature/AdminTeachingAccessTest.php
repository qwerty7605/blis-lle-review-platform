<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An administrator can do everything a teacher can, and with every teacher's
 * rooms, exams and Library files rather than only their own -- so the school
 * can step in for a teacher who is away. A teacher is still limited to what
 * they made, and what an administrator changes of a teacher's is recorded
 * with that teacher's name.
 */
class AdminTeachingAccessTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------- an administrator's own

    public function test_an_administrator_can_make_a_room_a_library_file_and_an_exam(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $roomId = $this->postJson('/api/rooms', ['name' => 'BLIS 4A'])->assertCreated()->json('room.id');
        $bankId = $this->postJson('/api/library/banks', $this->bankPayload())->assertCreated()->json('bank.id');

        $examId = $this->postJson('/api/exams', [
            'title' => 'Admin Mock Board',
            'question_bank_ids' => [$bankId],
            'total_items' => 2,
            'duration_minutes' => 30,
            'room_ids' => [$roomId],
        ])->assertCreated()->json('exam.id');

        // What the administrator makes is the administrator's.
        $this->assertSame($admin->id, (int) Room::find($roomId)->created_by);
        $this->assertSame($admin->id, (int) QuestionBank::find($bankId)->created_by);
        $this->assertSame($admin->id, (int) Exam::find($examId)->created_by);
        $this->assertDatabaseHas('exam_room', ['exam_id' => $examId, 'room_id' => $roomId]);
    }

    // ------------------------------------------------------ every teacher's

    public function test_an_administrator_sees_every_teacher_s_exams_and_files_and_a_teacher_only_theirs(): void
    {
        [$first] = $this->teacherWithContent('First');
        [$second] = $this->teacherWithContent('Second');

        Sanctum::actingAs($this->admin());

        $exams = $this->getJson('/api/exams')->assertOk()->assertJsonCount(2, 'exams');
        $this->assertEqualsCanonicalizing([$first->name, $second->name], array_column(array_column($exams->json('exams'), 'creator'), 'name'));

        $banks = $this->getJson('/api/library/banks')->assertOk()->assertJsonCount(2, 'banks');
        $this->assertEqualsCanonicalizing([$first->name, $second->name], array_column(array_column($banks->json('banks'), 'creator'), 'name'));

        Sanctum::actingAs($first);

        $this->getJson('/api/exams')->assertOk()->assertJsonCount(1, 'exams');
        $this->getJson('/api/library/banks')->assertOk()->assertJsonCount(1, 'banks');
    }

    public function test_an_administrator_can_open_and_change_a_teacher_s_exam_and_file(): void
    {
        [$teacher, $room, $bank, $exam] = $this->teacherWithContent('Owner');

        Sanctum::actingAs($this->admin());

        $this->getJson("/api/library/banks/{$bank->id}/questions")->assertOk();
        $this->patchJson("/api/library/banks/{$bank->id}", ['title' => 'Renamed by Admin'])->assertOk();
        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'Rescheduled by Admin', 'duration_minutes' => 45])->assertOk();

        $this->assertSame('Renamed by Admin', $bank->fresh()->title);
        $this->assertSame('Rescheduled by Admin', $exam->fresh()->title);
        // Changing it does not take it over.
        $this->assertSame($teacher->id, (int) $exam->fresh()->created_by);
        $this->assertSame($teacher->id, (int) $bank->fresh()->created_by);

        // Another teacher still cannot.
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]));

        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'Nope'])->assertForbidden();
        $this->patchJson("/api/library/banks/{$bank->id}", ['title' => 'Nope'])->assertForbidden();
        $this->getJson("/api/library/banks/{$bank->id}/questions")->assertForbidden();
    }

    public function test_an_administrator_can_put_their_exam_in_a_teacher_s_room_but_a_teacher_cannot_use_another_s(): void
    {
        [, $room, $bank] = $this->teacherWithContent('Owner');

        $payload = ['title' => 'Shared Exam', 'question_bank_ids' => [$bank->id], 'total_items' => 2, 'duration_minutes' => 30, 'room_ids' => [$room->id]];

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]));
        $this->postJson('/api/exams', $payload)->assertStatus(422);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/exams', $payload)->assertCreated();
    }

    public function test_what_an_administrator_changes_of_a_teacher_s_is_recorded_with_the_teacher_s_name(): void
    {
        [$teacher, , , $exam] = $this->teacherWithContent('Owner');
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'Fixed Answer Key'])->assertOk();

        $entry = AuditLog::query()->where('action', 'exam.update')->latest('id')->firstOrFail();

        $this->assertSame($admin->id, (int) $entry->actor_id);
        $this->assertStringContainsString($teacher->name, (string) $entry->description);
        $this->assertSame($teacher->id, (int) ($entry->metadata['owner_id'] ?? 0));

        // A teacher changing their own exam is not marked this way.
        Sanctum::actingAs($teacher);
        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'My Own Edit'])->assertOk();

        $own = AuditLog::query()->where('action', 'exam.update')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('belongs to', (string) $own->description);
    }

    public function test_deleting_a_teacher_s_unused_exam_is_recorded_with_the_teacher_s_name_too(): void
    {
        [$teacher, , , $exam] = $this->teacherWithContent('Owner');

        Sanctum::actingAs($this->admin());
        $this->deleteJson("/api/exams/{$exam->id}")->assertOk();

        $entry = AuditLog::query()->where('action', 'exam.delete')->latest('id')->firstOrFail();
        $this->assertStringContainsString($teacher->name, (string) $entry->description);
    }

    // --------------------------------------------------------------- reports

    public function test_an_administrator_s_dashboard_covers_the_programme_and_narrows_to_one_teacher(): void
    {
        [$first, $firstRoom, , $firstExam] = $this->teacherWithContent('First');
        [$second, $secondRoom, , $secondExam] = $this->teacherWithContent('Second');

        $this->submittedAttempt($firstExam, $firstRoom, 80);
        $this->submittedAttempt($secondExam, $secondRoom, 40);
        $this->submittedAttempt($secondExam, $secondRoom, 60);

        Sanctum::actingAs($this->admin());

        $all = $this->getJson('/api/reports/overview')->assertOk();
        $this->assertSame(2, $all->json('metrics.managed_rooms'));
        $this->assertSame(3, $all->json('analytics.graded_attempts'));
        $this->assertNull($all->json('scope.teacher_id'));
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($all->json('scope.teachers'), 'id'));

        $one = $this->getJson("/api/reports/overview?teacher_id={$second->id}")->assertOk();
        $this->assertSame(1, $one->json('metrics.managed_rooms'));
        $this->assertSame(2, $one->json('analytics.graded_attempts'));
        $this->assertSame($second->id, $one->json('scope.teacher_id'));

        // A teacher's is still their own, and asking for someone else's changes nothing.
        Sanctum::actingAs($first);

        $mine = $this->getJson("/api/reports/overview?teacher_id={$second->id}")->assertOk();
        $this->assertSame(1, $mine->json('metrics.managed_rooms'));
        $this->assertSame(1, $mine->json('analytics.graded_attempts'));
        $this->assertNull($mine->json('scope'));
    }

    public function test_a_student_gets_none_of_it(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));

        $this->getJson('/api/exams')->assertForbidden();
        $this->getJson('/api/library/banks')->assertForbidden();
        $this->getJson('/api/reports/overview')->assertForbidden();
        $this->postJson('/api/rooms', ['name' => 'Mine'])->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Admin Person']);
    }

    /** @return array{0: User, 1: Room, 2: QuestionBank, 3: Exam} */
    private function teacherWithContent(string $label): array
    {
        $teacher = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER, 'name' => "{$label} Teacher"]);

        Sanctum::actingAs($teacher);

        $room = Room::findOrFail($this->postJson('/api/rooms', ['name' => "{$label} Room"])->assertCreated()->json('room.id'));
        $bank = QuestionBank::findOrFail($this->postJson('/api/library/banks', $this->bankPayload("{$label} File"))->assertCreated()->json('bank.id'));

        $exam = Exam::findOrFail($this->postJson('/api/exams', [
            'title' => "{$label} Exam",
            'question_bank_ids' => [$bank->id],
            'total_items' => 2,
            'duration_minutes' => 30,
            'room_ids' => [$room->id],
        ])->assertCreated()->json('exam.id'));

        return [$teacher, $room, $bank, $exam];
    }

    /** @return array<string, mixed> */
    private function bankPayload(string $title = 'Reference Services Reviewer'): array
    {
        $question = fn (string $text): array => [
            'text' => $text,
            'question_type' => 'multiple_choice',
            'answer_label' => 'A',
            'options' => [
                ['label' => 'A', 'text' => 'Right', 'is_correct' => true],
                ['label' => 'B', 'text' => 'Wrong', 'is_correct' => false],
            ],
        ];

        return [
            'title' => $title,
            'subject' => 'Reference Services',
            'questions' => [$question('First question?'), $question('Second question?')],
        ];
    }

    private function submittedAttempt(Exam $exam, Room $room, int $score): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $room->members()->attach($student->id);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 10,
            'duration_minutes' => 30,
            'answered_count' => 10,
            'correct_answers' => (int) ($score / 10),
            'score_percent' => $score,
            'started_at' => now()->subHour(),
            'expires_at' => now()->subMinutes(30),
            'submitted_at' => now()->subMinutes(35),
        ]);
    }
}
