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
 * GET /api/students/{student}/analytics -- a student's profile as staff and
 * administrators see it. The point of these tests is the boundary: who may
 * read it, and that what they read is the student's own analytics payload.
 */
class StudentProfileAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_the_same_analytics_the_student_sees(): void
    {
        $staff = $this->makeUser('staff@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student@example.com', User::ROLE_STUDENT, '2301400');
        $room = $this->makeRoom($staff, 'BLIS 1A', 'B1A26');
        $room->members()->attach($student->id);

        $this->submitAttempt($this->makeExam($staff, 'Cataloging Mock', 'Cataloging'), $room, $student, 80);
        $this->submitAttempt($this->makeExam($staff, 'Reference Mock', 'Reference, Bibliography and User Services'), $room, $student, 60);

        Sanctum::actingAs($student);
        $own = $this->getJson('/api/student/analytics')->assertOk()->json();

        Sanctum::actingAs($staff);
        $response = $this->getJson("/api/students/{$student->id}/analytics")->assertOk();

        $response->assertJsonPath('student.id', $student->id);
        $response->assertJsonPath('student.student_id', '2301400');
        $response->assertJsonPath('student.rooms.0.name', 'BLIS 1A');
        $response->assertJsonPath('stats.completed_attempts', 2);

        $this->assertSame($own['stats'], $response->json('stats'));
        $this->assertSame($own['subjects'], $response->json('subjects'));
    }

    public function test_an_administrator_can_open_a_student_profile(): void
    {
        $admin = $this->makeUser('admin@example.com', User::ROLE_ADMIN);
        $student = $this->makeUser('student@example.com', User::ROLE_STUDENT, '2301400');

        Sanctum::actingAs($admin);

        $this->getJson("/api/students/{$student->id}/analytics")
            ->assertOk()
            ->assertJsonPath('student.email', 'student@example.com');
    }

    public function test_a_student_cannot_open_any_profile_including_their_own(): void
    {
        $student = $this->makeUser('student@example.com', User::ROLE_STUDENT, '2301400');
        $classmate = $this->makeUser('classmate@example.com', User::ROLE_STUDENT, '2301401');

        Sanctum::actingAs($student);

        $this->getJson("/api/students/{$classmate->id}/analytics")->assertForbidden();
        $this->getJson("/api/students/{$student->id}/analytics")->assertForbidden();
    }

    public function test_a_signed_out_visitor_is_rejected(): void
    {
        $student = $this->makeUser('student@example.com', User::ROLE_STUDENT, '2301400');

        $this->getJson("/api/students/{$student->id}/analytics")->assertUnauthorized();
    }

    public function test_a_staff_account_has_no_student_profile(): void
    {
        $staff = $this->makeUser('staff@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $colleague = $this->makeUser('colleague@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        Sanctum::actingAs($staff);

        $this->getJson("/api/students/{$colleague->id}/analytics")->assertNotFound();
        $this->getJson('/api/students/999999/analytics')->assertNotFound();
    }

    private function submitAttempt(Exam $exam, Room $room, User $student, int $score): void
    {
        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => (int) round($score / 2),
            'score_percent' => $score,
            'started_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(2)->addHour(),
        ]);
    }

    private function makeUser(string $email, string $role, ?string $studentId = null): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'student_id' => $studentId,
            'is_active' => true,
        ]);
    }

    private function makeRoom(User $staff, string $name, string $code): Room
    {
        return Room::create([
            'name' => $name,
            'code' => $code,
            'created_by' => $staff->id,
        ]);
    }

    private function makeExam(User $staff, string $title, ?string $subject = null): Exam
    {
        return Exam::create([
            'title' => $title,
            'subject' => $subject,
            'description' => null,
            'total_items' => 50,
            'duration_minutes' => 60,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);
    }
}
