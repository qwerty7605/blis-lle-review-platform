<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * What may still change about an exam once students have started it
 * (ExamEditRules): everyone sits the same exam, nobody taking it is locked
 * out or loses time, and a closing time that already cut someone short does
 * not then hand later students more.
 */
class LiveExamEditingTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    private QuestionBank $bank;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
        $this->bank = $this->makeBank($this->teacher, 4);
        $this->exam = $this->makeExam($this->teacher, $this->bank, [$this->room]);

        Sanctum::actingAs($this->teacher);
    }

    public function test_before_anyone_starts_everything_can_change(): void
    {
        $other = $this->makeBank($this->teacher, 6);

        $this->patchJson("/api/exams/{$this->exam->id}", [
            'total_items' => 3,
            'duration_minutes' => 45,
            'shuffle_questions' => true,
            'one_take_only' => true,
            'question_bank_ids' => [$this->bank->id, $other->id],
        ])->assertOk();
    }

    public function test_once_a_student_has_started_the_questions_and_time_are_fixed(): void
    {
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, true, false, true]);
        $other = $this->makeBank($this->teacher, 6);

        $changes = [
            'total_items' => ['total_items' => 3],
            'duration_minutes' => ['duration_minutes' => 45],
            'shuffle_questions' => ['shuffle_questions' => true],
            'question_bank_ids' => ['question_bank_ids' => [$this->bank->id, $other->id]],
        ];

        foreach ($changes as $field => $change) {
            $this->patchJson("/api/exams/{$this->exam->id}", $change)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $exam = $this->exam->fresh();
        $this->assertSame(4, (int) $exam->total_items);
        $this->assertSame(30, (int) $exam->duration_minutes);
        $this->assertSame([$this->bank->id], $exam->resolvedQuestionBankIds());

        // The same values sent again (the form sends everything), and what
        // stays editable, still save.
        $this->patchJson("/api/exams/{$this->exam->id}", [
            'title' => 'Renamed',
            'description' => 'Read the instructions first.',
            'total_items' => 4,
            'duration_minutes' => 30,
            'shuffle_questions' => false,
            'question_bank_ids' => [$this->bank->id],
            'results_visibility_mode' => 'hidden',
        ])->assertOk();

        $this->assertSame('Renamed', $this->exam->fresh()->title);
    }

    public function test_one_take_only_cannot_be_turned_on_once_someone_has_retaken_it(): void
    {
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, false, false, false], now()->subHour());

        // One attempt each so far: everyone would still get the same.
        $this->patchJson("/api/exams/{$this->exam->id}", ['one_take_only' => true])->assertOk();
        $this->patchJson("/api/exams/{$this->exam->id}", ['one_take_only' => false])->assertOk();

        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, true, false, false]);

        $this->patchJson("/api/exams/{$this->exam->id}", ['one_take_only' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['one_take_only']);

        $this->assertFalse((bool) $this->exam->fresh()->one_take_only);
    }

    public function test_nobody_taking_it_is_locked_out_or_has_time_taken_away(): void
    {
        $this->exam->update([
            'scheduled_at' => now()->subHour(),
            'schedule_start_at' => now()->subHour(),
            'schedule_end_at' => now()->addHours(2),
            'ends_at_close' => true,
        ]);
        // Started ten minutes ago with thirty to go: twenty minutes left.
        $attempt = $this->makeOpenAttempt($this->exam, $this->room, $this->student, [], now()->subMinutes(10));
        $deadline = $attempt->expires_at->copy();
        $other = $this->makeRoom($this->teacher);

        $this->patchJson("/api/exams/{$this->exam->id}", ['schedule_start_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_start_at']);

        $this->patchJson("/api/exams/{$this->exam->id}", ['schedule_end_at' => now()->addMinutes(5)->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_end_at']);

        $this->patchJson("/api/exams/{$this->exam->id}", ['room_ids' => [$other->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['room_ids']);

        $this->assertTrue($attempt->fresh()->expires_at->eq($deadline));

        // A later end time, and a room added, are fine.
        $this->patchJson("/api/exams/{$this->exam->id}", [
            'schedule_end_at' => now()->addHours(3)->toIso8601String(),
            'room_ids' => [$this->room->id, $other->id],
        ])->assertOk();
    }

    public function test_end_for_everyone_is_not_switched_on_over_someone_mid_exam(): void
    {
        $this->exam->update(['schedule_end_at' => now()->addMinutes(5), 'ends_at_close' => false]);
        $this->makeOpenAttempt($this->exam, $this->room, $this->student, [], now()->subMinutes(10));

        // Their twenty minutes would become five.
        $this->patchJson("/api/exams/{$this->exam->id}", ['ends_at_close' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at_close']);
    }

    public function test_a_closing_time_that_already_ended_exams_early_does_not_move_later(): void
    {
        $closedAt = now()->subHour();
        $this->exam->update([
            'scheduled_at' => now()->subHours(3),
            'schedule_start_at' => now()->subHours(3),
            'schedule_end_at' => $closedAt,
            'ends_at_close' => true,
        ]);

        // Started ten minutes before the close: handed in at it, with twenty
        // of its thirty minutes unused.
        ExamAttempt::create([
            'exam_id' => $this->exam->id,
            'room_id' => $this->room->id,
            'user_id' => $this->student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 4,
            'duration_minutes' => 30,
            'answered_count' => 2,
            'correct_answers' => 1,
            'score_percent' => 25,
            'started_at' => $closedAt->copy()->subMinutes(10),
            'expires_at' => $closedAt,
            'submitted_at' => $closedAt,
        ]);

        $this->patchJson("/api/exams/{$this->exam->id}", ['schedule_end_at' => now()->addHour()->toIso8601String()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['schedule_end_at']);

        $this->patchJson("/api/exams/{$this->exam->id}", ['ends_at_close' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ends_at_close']);

        $this->getJson("/api/exams/{$this->exam->id}/editing")
            ->assertOk()
            ->assertJsonPath('editing.ended_by_closing_time', 1);
    }

    public function test_a_room_with_results_is_archived_from_the_exam_not_removed(): void
    {
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, true, true, true]);
        $other = $this->makeRoom($this->teacher);

        $response = $this->patchJson("/api/exams/{$this->exam->id}", ['room_ids' => [$other->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['room_ids']);

        $this->assertStringContainsString('Archive', $response->json('errors.room_ids.0'));
        $this->assertDatabaseHas('exam_room', ['exam_id' => $this->exam->id, 'room_id' => $this->room->id]);
    }

    public function test_the_edit_form_is_told_what_students_have_done(): void
    {
        $classmate = $this->makeStudent();
        $this->room->members()->attach($classmate->id);
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, false, true, true], now()->subHour());
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, true, true, true]);
        $this->makeOpenAttempt($this->exam, $this->room, $classmate);

        $this->getJson("/api/exams/{$this->exam->id}/editing")
            ->assertOk()
            ->assertJsonPath('editing.students_started', 2)
            ->assertJsonPath('editing.students_taking_now', 1)
            ->assertJsonPath('editing.students_retaken', 1)
            ->assertJsonPath('editing.rooms_with_attempts.0.id', $this->room->id)
            ->assertJsonPath('editing.rooms_with_attempts.0.taking_now', true);

        Sanctum::actingAs($this->makeTeacher());
        $this->getJson("/api/exams/{$this->exam->id}/editing")->assertForbidden();

        Sanctum::actingAs($this->student);
        $this->getJson("/api/exams/{$this->exam->id}/editing")->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson("/api/exams/{$this->exam->id}/editing")->assertOk();
    }

    public function test_an_administrator_is_held_to_the_same_rules(): void
    {
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->student, [true, true, false, true]);

        Sanctum::actingAs($this->makeAdmin());

        $this->patchJson("/api/exams/{$this->exam->id}", ['duration_minutes' => 60])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['duration_minutes']);
    }
}
