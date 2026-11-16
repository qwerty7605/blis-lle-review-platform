<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LleResult;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    public function test_the_roster_shows_each_student_s_other_sittings(): void
    {
        $ana = $this->student('Ana Cruz', 2022);
        $ben = $this->student('Ben Dizon', 2022);

        // Ana passed the year before; Ben failed it and is back.
        LleResult::create(['user_id' => $ana->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);
        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]);
        LleResult::create(['user_id' => $ben->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);

        Sanctum::actingAs($this->staff());

        $this->getJson('/api/lle-results/batch?admission_year=2022&exam_year=2026')
            ->assertOk()
            ->assertJsonPath('students.0.result', null)
            ->assertJsonPath('students.0.other_results', [['exam_year' => 2025, 'result' => LleResult::RESULT_PASS]])
            ->assertJsonPath('students.1.result', LleResult::RESULT_PASS)
            ->assertJsonPath('students.1.other_results', [['exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]])
            ->assertJsonPath('passing_averages.2004', 75);
    }

    public function test_a_list_that_breaks_a_rule_records_nothing_and_says_who(): void
    {
        $ana = $this->student('Ana Cruz', 2022);
        $ben = $this->student('Ben Dizon', 2022);
        $cara = $this->student('Cara Lim', 2022);

        LleResult::create(['user_id' => $ana->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);

        Sanctum::actingAs($this->staff());

        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'entries' => [
                // "Mark the Rest as Failed" on someone who passed in 2025.
                ['user_id' => $ana->id, 'result' => LleResult::RESULT_FAIL],
                // A pass under the passing average.
                ['user_id' => $ben->id, 'result' => LleResult::RESULT_PASS, 'rating' => 70],
                // Failed at 80: a subject under 50%. Fine on its own.
                ['user_id' => $cara->id, 'result' => LleResult::RESULT_FAIL, 'rating' => 80],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors', [
                'entries.0.result' => ['Ana Cruz passed the LLE in 2025, so no result can be recorded for a later year.'],
                'entries.1.rating' => ['Ben Dizon: A general average under 75 is a failing grade (R.A. 9246, Sec. 17). Check the result or the average.'],
            ]);

        $this->assertDatabaseMissing('lle_results', ['exam_year' => 2026]);

        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'entries' => [
                ['user_id' => $ben->id, 'result' => LleResult::RESULT_PASS, 'rating' => 75],
                ['user_id' => $cara->id, 'result' => LleResult::RESULT_FAIL, 'rating' => 80],
            ],
        ])->assertOk()->assertJsonPath('recorded', 2);
    }

    public function test_a_list_cannot_be_for_a_sitting_still_to_come(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Manila'));

        $ana = $this->student('Ana Cruz', 2022);

        Sanctum::actingAs($this->staff());

        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2027,
            'entries' => [['user_id' => $ana->id, 'result' => LleResult::RESULT_FAIL]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.exam_year.0', 'The exam year cannot be later than this year (2026).');

        $this->postJson('/api/lle-results/batch', [
            'exam_year' => 2026,
            'taken_at' => '2025-09-27',
            'entries' => [['user_id' => $ana->id, 'result' => LleResult::RESULT_FAIL]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.taken_at.0', 'This date is in 2025, but the exam year is 2026.');

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
