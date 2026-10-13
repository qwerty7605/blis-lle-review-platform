<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LleResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recording a batch's licensure results in one go. A batch is an admission
 * year: its students sit the board exam together and the PRC publishes their
 * results as one list, so the page shows the batch's roster to fill in rather
 * than making the teacher look each student up.
 */
class LleResultBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_roster_of_a_batch_comes_with_what_is_already_recorded(): void
    {
        $ana = $this->student('Ana Cruz', 2022);
        $ben = $this->student('Ben Dizon', 2022);
        $this->student('Carl Reyes', 2023);

        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL, 'rating' => 71.5]);
        // Another sitting, which is not the one being filled in.
        LleResult::create(['user_id' => $ana->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]);

        Sanctum::actingAs($this->staff());

        $this->getJson('/api/lle-results/batch')
            ->assertOk()
            ->assertJsonPath('batches', [
                ['admission_year' => 2023, 'students_count' => 1],
                ['admission_year' => 2022, 'students_count' => 2],
            ])
            ->assertJsonCount(0, 'students');

        $this->getJson('/api/lle-results/batch?admission_year=2022&exam_year=2026')
            ->assertOk()
            ->assertJsonCount(2, 'students')
            ->assertJsonPath('students.0.name', 'Ana Cruz')
            ->assertJsonPath('students.0.result', null)
            ->assertJsonPath('students.1.name', 'Ben Dizon')
            ->assertJsonPath('students.1.result', LleResult::RESULT_FAIL);
    }

    public function test_a_whole_list_is_recorded_in_one_request(): void
    {
        $staff = $this->staff();
        $ana = $this->student('Ana Cruz', 2022);
        $ben = $this->student('Ben Dizon', 2022);
        $cara = $this->student('Cara Lim', 2022);

        // Ben is already on record as failed; the published list says passed.
        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL]);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'taken_at' => '2026-09-13',
            'entries' => [
                ['user_id' => $ana->id, 'result' => LleResult::RESULT_PASS, 'rating' => 86.4],
                ['user_id' => $ben->id, 'result' => LleResult::RESULT_PASS],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('recorded', 2)
            ->assertJsonPath('new', 1)
            ->assertJsonPath('corrected', 1);

        $this->assertDatabaseHas('lle_results', ['user_id' => $ana->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS, 'recorded_by' => $staff->id]);
        $this->assertDatabaseHas('lle_results', ['user_id' => $ben->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);
        // Left blank on the page, so not sent, so nothing is recorded for her.
        $this->assertDatabaseMissing('lle_results', ['user_id' => $cara->id]);
        $this->assertDatabaseCount('lle_results', 2);

        // One entry in the audit log for the list.
        $this->assertSame(1, AuditLog::query()->where('action', 'lle-result.record-batch')->count());
    }

    public function test_a_list_with_a_problem_in_it_records_nothing(): void
    {
        $ana = $this->student('Ana Cruz', 2022);
        $teacher = $this->staff();

        Sanctum::actingAs($teacher);

        // Someone who is not a student.
        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'entries' => [
                ['user_id' => $ana->id, 'result' => LleResult::RESULT_PASS],
                ['user_id' => $teacher->id, 'result' => LleResult::RESULT_PASS],
            ],
        ])->assertStatus(422);

        // A result that is neither passed nor failed, and a student twice.
        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'entries' => [
                ['user_id' => $ana->id, 'result' => 'maybe'],
                ['user_id' => $ana->id, 'result' => LleResult::RESULT_PASS],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['entries.0.result', 'entries.0.user_id']);

        $this->postJson('/api/lle-results/batch', ['exam_year' => 2026, 'entries' => []])->assertStatus(422);

        $this->assertDatabaseCount('lle_results', 0);
    }

    public function test_it_is_for_staff_and_administrators_only(): void
    {
        $ana = $this->student('Ana Cruz', 2022);
        $payload = ['exam_year' => 2026, 'entries' => [['user_id' => $ana->id, 'result' => LleResult::RESULT_PASS]]];

        Sanctum::actingAs($ana);
        $this->getJson('/api/lle-results/batch')->assertForbidden();
        $this->postJson('/api/lle-results/batch', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->getJson('/api/lle-results/batch?admission_year=2022')->assertOk();
        $this->postJson('/api/lle-results/batch', $payload)->assertOk();
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
    }

    private function student(string $name, int $admissionYear): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => $name]);
        $student->forceFill(['admission_year' => $admissionYear])->save();

        return $student;
    }
}
