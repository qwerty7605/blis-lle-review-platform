<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deleting an exam or a room used to delete every attempt students had made
 * in it: exam_attempts cascaded from both, and neither route looked first. A
 * teacher tidying up the Exams page erased results without being told.
 *
 * Both routes now refuse once an attempt exists, and so does the database.
 */
class ExamAndRoomDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_exam_students_have_taken_cannot_be_deleted(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->makeAttempt($exam, $room, $student, ExamAttempt::STATUS_SUBMITTED);

        Sanctum::actingAs($staff);

        $response = $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422);

        // The refusal has to say what to do instead, or it is a dead end.
        $this->assertStringContainsString('Archive it', (string) $response->json('message'));

        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        // Refused outright, not half done: the room assignment is still there.
        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $room->id]);
    }

    public function test_an_attempt_still_in_progress_protects_the_exam_too(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->makeAttempt($exam, $room, $student, ExamAttempt::STATUS_IN_PROGRESS);

        Sanctum::actingAs($staff);

        // A student is sitting this exam right now.
        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422);

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
    }

    public function test_an_exam_students_have_taken_can_be_archived_from_all_its_rooms_instead(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->makeAttempt($exam, $room, $student, ExamAttempt::STATUS_SUBMITTED);

        // In a second room too, and already archived from a third.
        $second = Room::create(['name' => 'BLIS 1B', 'code' => 'ARCH1B', 'created_by' => $staff->id]);
        $third = Room::create(['name' => 'BLIS 1C', 'code' => 'ARCH1C', 'created_by' => $staff->id]);
        $exam->rooms()->attach($second->id, ['assigned_by' => $staff->id]);
        $exam->rooms()->attach($third->id, ['assigned_by' => $staff->id, 'archived_at' => now()->subWeek(), 'archived_by' => $staff->id]);

        Sanctum::actingAs($staff);

        $this->deleteJson("/api/exams/{$exam->id}")->assertStatus(422);

        $this->postJson("/api/exams/{$exam->id}/archive")
            ->assertOk()
            ->assertJsonPath('archived_rooms', 2)
            ->assertJsonPath('message', 'Exam archived from 2 rooms. Its results are kept, and each room can restore it.');

        // Off every room's current list, with nothing deleted.
        $this->assertSame(0, DB::table('exam_room')->where('exam_id', $exam->id)->whereNull('archived_at')->count());
        $this->assertSame(3, DB::table('exam_room')->where('exam_id', $exam->id)->count());
        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $room->id, 'archived_by' => $staff->id]);
        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exam.archive', 'actor_id' => $staff->id, 'target_id' => $exam->id]);

        // Nothing left to archive, and a room can put it back.
        $this->postJson("/api/exams/{$exam->id}/archive")->assertStatus(422);
        $this->postJson("/api/rooms/{$room->id}/exams/{$exam->id}/restore")->assertOk();
        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $room->id, 'archived_at' => null]);
    }

    public function test_only_the_exams_teacher_or_an_administrator_can_archive_it(): void
    {
        [, $student, $room, $exam] = $this->scenario();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]));
        $this->postJson("/api/exams/{$exam->id}/archive")->assertForbidden();

        Sanctum::actingAs($student);
        $this->postJson("/api/exams/{$exam->id}/archive")->assertForbidden();

        $this->assertDatabaseHas('exam_room', ['exam_id' => $exam->id, 'room_id' => $room->id, 'archived_at' => null]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->postJson("/api/exams/{$exam->id}/archive")->assertOk()->assertJsonPath('archived_rooms', 1);
    }

    public function test_an_exam_nobody_has_taken_can_still_be_deleted(): void
    {
        [$staff, , $room, $exam] = $this->scenario();

        Sanctum::actingAs($staff);

        $this->deleteJson("/api/exams/{$exam->id}")->assertOk();

        $this->assertDatabaseMissing('exams', ['id' => $exam->id]);
        $this->assertDatabaseMissing('exam_room', ['exam_id' => $exam->id]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_a_room_students_have_taken_exams_in_cannot_be_deleted(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        $attempt = $this->makeAttempt($exam, $room, $student, ExamAttempt::STATUS_SUBMITTED);

        Sanctum::actingAs($staff);

        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(422);

        // An administrator may delete any room, but not this one either.
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $this->deleteJson("/api/rooms/{$room->id}")->assertStatus(422);

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);
    }

    public function test_a_room_with_no_attempts_and_nothing_pending_can_still_be_deleted(): void
    {
        [$staff, $student, $room, $exam] = $this->scenario();
        // Its window has closed, so the class has nothing left to sit here.
        $exam->update(['schedule_start_at' => now()->subDays(3), 'schedule_end_at' => now()->subDay()]);

        Sanctum::actingAs($staff);

        $this->deleteJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        // Enrollments and assignments carry no history of their own, so they go.
        $this->assertDatabaseMissing('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);
        $this->assertDatabaseMissing('exam_room', ['room_id' => $room->id]);
        $this->assertDatabaseHas('exams', ['id' => $exam->id]);
    }

    public function test_the_database_refuses_what_the_routes_refuse(): void
    {
        [, $student, $room, $exam] = $this->scenario();
        $this->makeAttempt($exam, $room, $student, ExamAttempt::STATUS_SUBMITTED);

        // Straight past the routes, the way a manual cleanup would go.
        foreach (['exams' => $exam->id, 'rooms' => $room->id] as $table => $id) {
            try {
                DB::table($table)->where('id', $id)->delete();
                $this->fail("deleting from {$table} should have been refused");
            } catch (QueryException) {
                $this->assertDatabaseHas($table, ['id' => $id]);
            }
        }

        $this->assertDatabaseCount('exam_attempts', 1);
    }

    /** @return array{0: User, 1: User, 2: Room, 3: Exam} */
    private function scenario(): array
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => 'BLIS 3A', 'code' => 'DEL3A1', 'created_by' => $staff->id]);
        $room->members()->attach($student->id);

        $exam = Exam::create([
            'title' => 'Indexing Midterm',
            'subject' => 'Indexing and Abstracting',
            'total_items' => 10,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$staff, $student, $room, $exam];
    }

    private function makeAttempt(Exam $exam, Room $room, User $student, string $status): ExamAttempt
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
