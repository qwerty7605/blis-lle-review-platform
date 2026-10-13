<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * When a room may be deleted, what archiving one does instead, and what a
 * student in the middle of an exam is protected from.
 *
 * Delete is for a room that was never used: no results (see
 * ExamAndRoomDeletionTest) and no exam still open or scheduled in it. A room
 * that was used is archived: out of everyone's lists, everything in it kept.
 */
class RoomRemovalRulesTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- delete

    public function test_a_room_with_an_exam_open_or_scheduled_cannot_be_deleted(): void
    {
        $windows = [
            'open now' => [now()->subHour(), now()->addDay()],
            'scheduled for later' => [now()->addDays(2), now()->addDays(3)],
            'open with no closing time' => [now()->subDay(), null],
            'no schedule at all' => [null, null],
        ];

        foreach ($windows as $case => [$start, $end]) {
            [$staff, , $room, $exam] = $this->scenario();
            $exam->update(['schedule_start_at' => $start, 'schedule_end_at' => $end]);

            Sanctum::actingAs($staff);

            $response = $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(422);

            $this->assertStringContainsString($exam->title, (string) $response->json('message'), $case);
            $this->assertStringContainsString('Archive', (string) $response->json('message'), $case);
            $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        }
    }

    public function test_an_administrator_is_held_to_the_same_rule(): void
    {
        [, , $room] = $this->scenario();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(422);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_archiving_the_exam_from_the_room_lets_the_room_be_deleted(): void
    {
        [$staff, , $room, $exam] = $this->scenario();

        Sanctum::actingAs($staff);

        $this->postJson("/api/rooms/{$room->id}/exams/{$exam->id}/archive")->assertOk();
        $this->deleteJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_the_room_page_is_told_what_stands_in_the_way(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $this->attempt($exam, $room, $student, ExamAttempt::STATUS_IN_PROGRESS);

        Sanctum::actingAs($staff);

        $this->getJson("/api/rooms/{$room->id}")
            ->assertOk()
            ->assertJsonPath('room.removal.has_results', true)
            ->assertJsonPath('room.removal.pending_exams', [$exam->title])
            ->assertJsonPath('room.removal.attempts_in_progress', 1);

        // Not something a student is shown.
        Sanctum::actingAs($student);
        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonMissingPath('room.removal');
    }

    // --------------------------------------------------------------- archive

    public function test_an_archived_room_leaves_the_lists_and_keeps_everything(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->attempt($exam, $room, $student, ExamAttempt::STATUS_SUBMITTED);

        Sanctum::actingAs($staff);

        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();

        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(0, 'rooms');
        $this->getJson('/api/rooms?archived=1')->assertOk()->assertJsonPath('rooms.0.id', $room->id);
        // The teacher can still open it, to read its results.
        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonPath('room.members_count', 1);

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);
        $this->assertDatabaseHas('exam_room', ['room_id' => $room->id, 'exam_id' => $exam->id]);

        Sanctum::actingAs($student);

        $this->getJson('/api/rooms')->assertOk()->assertJsonCount(0, 'rooms');
        $this->getJson("/api/rooms/{$room->id}")->assertForbidden();
        $this->getJson('/api/student/dashboard')->assertOk();
    }

    public function test_an_archived_room_takes_no_students_and_starts_no_exams(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        Sanctum::actingAs($staff);
        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));
        $this->postJson('/api/rooms/join', ['code' => $room->code])->assertStatus(422);

        $this->assertDatabaseCount('exam_attempts', 0);
        $this->assertDatabaseCount('room_user', 1);
    }

    public function test_a_room_cannot_be_archived_while_a_student_is_mid_exam(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->attempt($exam, $room, $student, ExamAttempt::STATUS_IN_PROGRESS);

        Sanctum::actingAs($staff);

        $this->postJson("/api/rooms/{$room->id}/archive")->assertStatus(422);
        $this->assertNull($room->fresh()->archived_at);

        // Once the clock has run out there is nobody left to cut off.
        $attempt->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();
    }

    public function test_restoring_a_room_brings_it_back_as_it_was(): void
    {
        [$staff, $student, $room] = $this->scenario();
        Sanctum::actingAs($staff);

        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();
        $this->postJson("/api/rooms/{$room->id}/archive")->assertStatus(422);
        $this->postJson("/api/rooms/{$room->id}/restore")->assertOk();
        $this->postJson("/api/rooms/{$room->id}/restore")->assertStatus(422);

        $this->getJson('/api/rooms')->assertOk()->assertJsonPath('rooms.0.id', $room->id);

        Sanctum::actingAs($student);
        $this->getJson("/api/rooms/{$room->id}")->assertOk();
    }

    public function test_only_the_room_s_teacher_or_an_administrator_archives_it(): void
    {
        [, $student, $room] = $this->scenario();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]));
        $this->postJson("/api/rooms/{$room->id}/archive")->assertForbidden();

        Sanctum::actingAs($student);
        $this->postJson("/api/rooms/{$room->id}/archive")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();
    }

    public function test_saving_an_exam_keeps_its_link_to_an_archived_room(): void
    {
        [$staff, , $room, $exam] = $this->scenario();
        $other = Room::create(['name' => 'BLIS 3B', 'code' => 'DEL3B1', 'created_by' => $staff->id]);

        Sanctum::actingAs($staff);
        $this->postJson("/api/rooms/{$room->id}/archive")->assertOk();

        // The exam form lists only rooms in use, so this is all it sends.
        $this->patchJson("/api/exams/{$exam->id}", ['room_ids' => [$other->id]])->assertOk();

        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $other->id]);
        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $room->id]);
    }

    // ------------------------------------------------------- a student mid-exam

    public function test_a_student_mid_exam_cannot_be_removed_from_the_room_or_leave_it(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->attempt($exam, $room, $student, ExamAttempt::STATUS_IN_PROGRESS);

        Sanctum::actingAs($staff);
        $this->deleteJson("/api/rooms/{$room->id}/members/{$student->id}")->assertStatus(422);

        Sanctum::actingAs($student);
        $this->deleteJson("/api/rooms/{$room->id}/leave")->assertStatus(422);

        $this->assertDatabaseHas('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);

        // Submitted: both are allowed again.
        $attempt->update(['status' => ExamAttempt::STATUS_SUBMITTED, 'submitted_at' => now()]);

        $this->deleteJson("/api/rooms/{$room->id}/leave")->assertOk();
        $this->assertDatabaseMissing('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);
    }

    public function test_another_student_s_exam_does_not_hold_this_one_in_the_room(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $classmate = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $room->members()->attach($classmate->id);
        $this->attempt($exam, $room, $classmate, ExamAttempt::STATUS_IN_PROGRESS);

        Sanctum::actingAs($staff);
        $this->deleteJson("/api/rooms/{$room->id}/members/{$student->id}")->assertOk();
    }

    /** @return array{0: User, 1: User, 2: Room, 3: Exam} */
    private function scenario(): array
    {
        static $number = 0;
        $number++;

        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => "BLIS 3A {$number}", 'code' => "RM3A{$number}", 'created_by' => $staff->id]);
        $room->members()->attach($student->id);

        $exam = Exam::create([
            'title' => "Indexing Midterm {$number}",
            'subject' => 'Indexing and Abstracting',
            'total_items' => 10,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, ['assigned_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$staff, $student, $room, $exam];
    }

    private function attempt(Exam $exam, Room $room, User $student, string $status): ExamAttempt
    {
        $submitted = $status === ExamAttempt::STATUS_SUBMITTED;

        return ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => $status,
            'total_items' => 10,
            'duration_minutes' => 30,
            'answered_count' => $submitted ? 10 : 4,
            'correct_answers' => $submitted ? 8 : 3,
            'score_percent' => $submitted ? 80 : null,
            'started_at' => now()->subMinutes(20),
            'expires_at' => now()->addMinutes(10),
            'submitted_at' => $submitted ? now() : null,
        ]);
    }
}
