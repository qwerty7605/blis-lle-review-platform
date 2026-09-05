<?php

namespace Tests\Feature;

use App\Exceptions\StudentHasAcademicHistoryException;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LleResult;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LleResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_record_a_licensure_result(): void
    {
        $staff = $this->makeUser('lle-staff@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-student@example.com', User::ROLE_STUDENT, '2301900', 4);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'taken_at' => '2026-09-01',
            'result' => LleResult::RESULT_PASS,
            'rating' => 84.25,
        ])
            ->assertCreated()
            ->assertJsonPath('result.result', LleResult::RESULT_PASS)
            ->assertJsonPath('result.student.student_id', '2301900');

        $this->assertDatabaseHas('lle_results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
            'recorded_by' => $staff->id,
        ]);
    }

    public function test_recording_the_same_sitting_twice_corrects_it_instead_of_duplicating(): void
    {
        $staff = $this->makeUser('lle-staff-2@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-student-2@example.com', User::ROLE_STUDENT, '2301901', 4);

        Sanctum::actingAs($staff);

        $payload = [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_FAIL,
        ];

        $this->postJson('/api/lle-results', $payload)->assertCreated();

        // A transcription fix, not a second sitting.
        $this->postJson('/api/lle-results', array_merge($payload, [
            'result' => LleResult::RESULT_PASS,
            'rating' => 79.5,
        ]))
            ->assertOk()
            ->assertJsonPath('result.result', LleResult::RESULT_PASS);

        $this->assertSame(1, LleResult::where('user_id', $student->id)->count());
    }

    public function test_a_retake_in_a_later_year_is_a_separate_record(): void
    {
        $staff = $this->makeUser('lle-staff-3@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-student-3@example.com', User::ROLE_STUDENT, '2301902', 4);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_FAIL,
        ])->assertCreated();

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2027,
            'result' => LleResult::RESULT_PASS,
        ])->assertCreated();

        $this->assertSame(2, LleResult::where('user_id', $student->id)->count());
    }

    public function test_results_cannot_be_recorded_against_non_student_accounts(): void
    {
        $staff = $this->makeUser('lle-staff-4@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $otherStaff = $this->makeUser('lle-other-staff@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results', [
            'user_id' => $otherStaff->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ])->assertStatus(422);
    }

    public function test_students_cannot_reach_the_licensure_endpoints(): void
    {
        $student = $this->makeUser('lle-student-4@example.com', User::ROLE_STUDENT, '2301903', 4);

        Sanctum::actingAs($student);

        $this->getJson('/api/lle-results')->assertForbidden();
        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ])->assertForbidden();
    }

    public function test_results_can_be_listed_and_filtered(): void
    {
        $staff = $this->makeUser('lle-staff-5@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $passer = $this->makeUser('lle-passer@example.com', User::ROLE_STUDENT, '2301904', 4);
        $failer = $this->makeUser('lle-failer@example.com', User::ROLE_STUDENT, '2301905', 4);

        LleResult::create([
            'user_id' => $passer->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ]);
        LleResult::create([
            'user_id' => $failer->id,
            'exam_year' => 2025,
            'result' => LleResult::RESULT_FAIL,
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/lle-results')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/lle-results?result=pass')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('results.0.user_id', $passer->id);

        $this->getJson('/api/lle-results?exam_year=2025')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('results.0.user_id', $failer->id);

        $this->getJson('/api/lle-results?search=2301904')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_recorded_result_can_be_removed(): void
    {
        $staff = $this->makeUser('lle-staff-6@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-student-6@example.com', User::ROLE_STUDENT, '2301906', 4);

        $result = LleResult::create([
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ]);

        Sanctum::actingAs($staff);

        $this->deleteJson("/api/lle-results/{$result->id}")->assertOk();
        $this->assertDatabaseMissing('lle_results', ['id' => $result->id]);
    }

    public function test_a_student_with_attempt_history_cannot_be_deleted(): void
    {
        $staff = $this->makeUser('lle-staff-7@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-student-7@example.com', User::ROLE_STUDENT, '2301907', 4);

        $room = Room::create(['name' => 'LLE Room', 'code' => 'LLE001', 'created_by' => $staff->id]);
        $bank = QuestionBank::create([
            'title' => 'LLE Bank',
            'subject' => 'Cataloging and Classification',
            'total_items' => 50,
            'created_by' => $staff->id,
        ]);
        $exam = Exam::create([
            'title' => 'LLE Mock',
            'subject' => 'Cataloging and Classification',
            'question_bank_id' => $bank->id,
            'total_items' => 50,
            'duration_minutes' => 60,
            'created_by' => $staff->id,
        ]);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 50,
            'duration_minutes' => 60,
            'answered_count' => 50,
            'correct_answers' => 40,
            'score_percent' => 80,
            'started_at' => now()->subDay(),
            'submitted_at' => now()->subDay()->addHour(),
        ]);

        // The attempt history is the feature half of the LLE training set, so
        // deleting the student has to be refused rather than silently cascading.
        $this->expectException(StudentHasAcademicHistoryException::class);

        $student->delete();
    }

    public function test_a_student_with_no_history_can_still_be_deleted(): void
    {
        $student = $this->makeUser('lle-student-8@example.com', User::ROLE_STUDENT, '2301908', 1);
        $id = $student->id;

        $student->delete();

        $this->assertDatabaseMissing('users', ['id' => $id]);
    }

    private function makeUser(string $email, string $role, ?string $studentId = null, ?int $yearLevel = null): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'student_id' => $studentId,
            'year_level' => $role === User::ROLE_STUDENT ? $yearLevel : null,
            'is_active' => true,
        ]);
    }
}
