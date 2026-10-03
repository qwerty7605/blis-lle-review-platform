<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * student_can_start_attempt used to mean only "attempts remaining > 0", so it
 * reported true for an exam whose window had closed -- an enabled button that
 * answers 422 the moment it is pressed. It now has to agree with the three
 * conditions the start endpoint actually rejects on.
 */
class StudentExamAvailabilityFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_closed_window_cannot_be_started_even_with_attempts_left(): void
    {
        [$student, $room, $exam] = $this->scenario([
            'schedule_start_at' => now()->subMonth(),
            'schedule_end_at' => now()->subDay(),
        ]);

        $flags = $this->examFlags($student, $room, $exam);

        $this->assertSame(2, $flags['student_attempts_remaining'], 'the attempts are genuinely unused');
        $this->assertFalse($flags['student_can_start_attempt'], 'but the window has closed');

        // And the flag told the truth: starting really is refused.
        $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id])
            ->assertStatus(422);
    }

    public function test_a_window_that_has_not_opened_yet_cannot_be_started(): void
    {
        [$student, $room, $exam] = $this->scenario([
            'schedule_start_at' => now()->addWeek(),
            'schedule_end_at' => now()->addMonth(),
        ]);

        $this->assertFalse($this->examFlags($student, $room, $exam)['student_can_start_attempt']);
    }

    public function test_an_exam_with_no_question_set_cannot_be_started(): void
    {
        [$student, $room, $exam] = $this->scenario([
            'schedule_start_at' => now()->subDay(),
            'schedule_end_at' => now()->addWeek(),
        ]);

        // No question bank was ever linked, so there is nothing to serve.
        $this->assertFalse($this->examFlags($student, $room, $exam)['student_can_start_attempt']);

        $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id])
            ->assertStatus(422);
    }

    public function test_an_unbounded_schedule_is_treated_as_open(): void
    {
        [$student, $room, $exam] = $this->scenario([
            'schedule_start_at' => null,
            'schedule_end_at' => null,
        ]);

        // Still false here only because no question set is linked -- the
        // window itself is open, which is what the model helper reports.
        $this->assertTrue($exam->fresh()->isWithinScheduleWindow());
    }

    /** @return array{0: User, 1: Room, 2: Exam} */
    private function scenario(array $schedule): array
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => 'Room A', 'code' => 'ROOMA1', 'created_by' => $staff->id]);
        $room->members()->attach($student->id);

        $exam = Exam::create(array_merge([
            'title' => 'Scheduled Mock',
            'subject' => 'Cataloging and Classification',
            'total_items' => 10,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ], $schedule));

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($student);

        return [$student, $room, $exam];
    }

    private function examFlags(User $student, Room $room, Exam $exam): array
    {
        $payload = $this->getJson("/api/rooms/{$room->id}")->assertOk()->json();

        foreach ($payload['room']['assigned_exams'] as $assigned) {
            if ((int) $assigned['id'] === (int) $exam->id) {
                return $assigned;
            }
        }

        $this->fail('the exam was not present in the room payload');
    }
}
