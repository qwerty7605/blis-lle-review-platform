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
 * An exam may be taken twice. Both sittings are kept and the student can see
 * both, but the latest is the one score the exam has -- on the student's own
 * pages as much as in the teacher's reports, which already used it. Counting
 * both used to make one exam read as two and average the retake with the
 * attempt it was meant to improve on.
 */
class ExamRetakeScoresTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_student_s_figures_use_the_latest_sitting_of_each_exam(): void
    {
        [$student, $room, $exam] = $this->scenario();
        $this->sitting($exam, $room, $student, 40, now()->subDays(2));
        $this->sitting($exam, $room, $student, 80, now()->subDay());

        Sanctum::actingAs($student);

        $analytics = $this->getJson('/api/student/analytics')->assertOk();

        // One exam, at 80: not two exams averaging 60.
        $this->assertSame(1, $analytics->json('stats.completed_attempts'));
        $this->assertEquals(80.0, $analytics->json('stats.average_score_percent'));
        $this->assertEquals(80.0, $analytics->json('stats.latest_score_percent'));

        $this->assertEquals(80.0, $this->getJson('/api/student/dashboard')->assertOk()->json('stats.average_score_percent'));

        $overview = $this->getJson('/api/student/analytics/overview')->assertOk();
        $this->assertEquals(80.0, $overview->json('summary.average_score_percent'));
        $this->assertEquals(100.0, $overview->json('summary.pass_rate_percent'));
        // Both sittings are still counted as work done.
        $this->assertSame(2, $overview->json('summary.attempts_submitted'));
    }

    public function test_a_retake_that_went_worse_is_still_the_score_that_counts(): void
    {
        [$student, $room, $exam] = $this->scenario();
        $this->sitting($exam, $room, $student, 90, now()->subDays(2));
        $this->sitting($exam, $room, $student, 55, now()->subDay());

        Sanctum::actingAs($student);

        $this->assertEquals(55.0, $this->getJson('/api/student/analytics')->assertOk()->json('stats.average_score_percent'));
    }

    public function test_the_exam_history_lists_every_sitting_and_marks_the_one_that_counts(): void
    {
        [$student, $room, $exam] = $this->scenario();
        $first = $this->sitting($exam, $room, $student, 40, now()->subDays(2));
        $second = $this->sitting($exam, $room, $student, 80, now()->subDay());

        $other = Exam::create(['title' => 'Other Exam', 'subject' => 'Reference, Bibliography and User Services', 'total_items' => 10, 'duration_minutes' => 30, 'created_by' => $exam->created_by]);
        $only = $this->sitting($other, $room, $student, 70, now()->subDays(3));

        Sanctum::actingAs($student);

        $this->getJson('/api/student/analytics')
            ->assertOk()
            ->assertJsonCount(3, 'exam_history')
            // Newest first.
            ->assertJsonPath('exam_history.0.attempt_id', $second->id)
            ->assertJsonPath('exam_history.0.attempt_number', 2)
            ->assertJsonPath('exam_history.0.attempts_total', 2)
            ->assertJsonPath('exam_history.0.counts', true)
            ->assertJsonPath('exam_history.1.attempt_id', $first->id)
            ->assertJsonPath('exam_history.1.attempt_number', 1)
            ->assertJsonPath('exam_history.1.counts', false)
            ->assertJsonPath('exam_history.2.attempt_id', $only->id)
            ->assertJsonPath('exam_history.2.attempt_number', 1)
            ->assertJsonPath('exam_history.2.attempts_total', 1)
            ->assertJsonPath('exam_history.2.counts', true);
    }

    public function test_a_result_names_the_other_sitting_and_the_first_one_can_still_be_opened(): void
    {
        [$student, $room, $exam] = $this->scenario();
        $first = $this->sitting($exam, $room, $student, 40, now()->subDays(2));
        $second = $this->sitting($exam, $room, $student, 80, now()->subDay());

        Sanctum::actingAs($student);

        foreach ([$first, $second] as $opened) {
            $this->getJson("/api/student/exam-attempts/{$opened->id}")
                ->assertOk()
                ->assertJsonPath('attempt.id', $opened->id)
                ->assertJsonCount(2, 'sittings')
                ->assertJsonPath('sittings.0.id', $first->id)
                ->assertJsonPath('sittings.0.number', 1)
                ->assertJsonPath('sittings.0.counts', false)
                ->assertJsonPath('sittings.1.id', $second->id)
                ->assertJsonPath('sittings.1.number', 2)
                ->assertJsonPath('sittings.1.counts', true);
        }

        // Someone else's sitting of the same exam is not in the list.
        $classmate = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $room->members()->attach($classmate->id);
        $theirs = $this->sitting($exam, $room, $classmate, 99, now());

        $this->getJson("/api/student/exam-attempts/{$second->id}")->assertOk()->assertJsonCount(2, 'sittings');
        $this->getJson("/api/student/exam-attempts/{$theirs->id}")->assertForbidden();
    }

    public function test_the_room_page_gives_the_latest_score_with_the_attempt_count(): void
    {
        [$student, $room, $exam] = $this->scenario();
        $this->sitting($exam, $room, $student, 40, now()->subDays(2));
        $second = $this->sitting($exam, $room, $student, 80, now()->subDay());

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertSame(2, $response->json('room.assigned_exams.0.student_submitted_attempts'));
        $this->assertSame($second->id, $response->json('room.assigned_exams.0.student_attempt_id'));
        $this->assertEquals(80.0, $response->json('room.assigned_exams.0.student_latest_score_percent'));
    }

    /** @return array{0: User, 1: Room, 2: Exam} */
    private function scenario(): array
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => 'BLIS 4A', 'code' => 'RETAKE', 'created_by' => $staff->id]);
        $room->members()->attach($student->id);

        $exam = Exam::create([
            'title' => 'Cataloging Mock Board',
            'subject' => 'Cataloging and Classification',
            'total_items' => 10,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, ['assigned_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$student, $room, $exam];
    }

    private function sitting(Exam $exam, Room $room, User $student, int $score, \DateTimeInterface $submittedAt): ExamAttempt
    {
        return ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 10,
            'duration_minutes' => 30,
            'answered_count' => 10,
            'correct_answers' => (int) round($score / 10),
            'score_percent' => $score,
            'started_at' => (clone $submittedAt)->modify('-20 minutes'),
            'expires_at' => (clone $submittedAt)->modify('+10 minutes'),
            'submitted_at' => $submittedAt,
        ]);
    }
}
