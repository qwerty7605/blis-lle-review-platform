<?php

namespace Tests\Feature;

use App\Exceptions\StudentHasAcademicHistoryException;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LleResult;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use App\Services\LleResultRules;
use Carbon\CarbonImmutable;
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
            'exam_year' => 2025,
            'result' => LleResult::RESULT_FAIL,
        ])->assertCreated();

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ])->assertCreated();

        $this->assertSame(2, LleResult::where('user_id', $student->id)->count());
    }

    public function test_a_pass_needs_the_passing_average_but_a_fail_may_have_more(): void
    {
        $staff = $this->makeUser('lle-staff-mark@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $low = $this->makeUser('lle-low@example.com', User::ROLE_STUDENT, '2301910', 4);
        $exact = $this->makeUser('lle-exact@example.com', User::ROLE_STUDENT, '2301911', 4);
        $failedHigh = $this->makeUser('lle-failed-high@example.com', User::ROLE_STUDENT, '2301912', 4);
        $noAverage = $this->makeUser('lle-no-average@example.com', User::ROLE_STUDENT, '2301913', 4);

        Sanctum::actingAs($staff);

        // R.A. 9246, Sec. 17: under 75 cannot pass.
        $this->postJson('/api/lle-results', [
            'user_id' => $low->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
            'rating' => 74.99,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating'])
            ->assertJsonPath('errors.rating.0', 'A general average under 75 is a failing grade (R.A. 9246, Sec. 17). Check the result or the average.');

        $this->postJson('/api/lle-results', [
            'user_id' => $exact->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
            'rating' => 75,
        ])->assertCreated();

        // 75 or more and still failed: a subject under 50%. Real, so kept.
        $this->postJson('/api/lle-results', [
            'user_id' => $failedHigh->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_FAIL,
            'rating' => 80.5,
        ])->assertCreated();

        // The average is often not known; then there is nothing to check.
        $this->postJson('/api/lle-results', [
            'user_id' => $noAverage->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
        ])->assertCreated();

        $this->assertDatabaseMissing('lle_results', ['user_id' => $low->id]);
    }

    public function test_the_passing_average_belongs_to_the_year_of_the_sitting(): void
    {
        $this->assertSame(75.0, LleResultRules::passingAverageFor(2004));
        $this->assertSame(75.0, LleResultRules::passingAverageFor(2026));
        // Before R.A. 9246 there is no mark here to check against.
        $this->assertNull(LleResultRules::passingAverageFor(2003));
    }

    public function test_nothing_can_be_recorded_after_a_pass(): void
    {
        $staff = $this->makeUser('lle-staff-after@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-licensed@example.com', User::ROLE_STUDENT, '2301914', 4);

        LleResult::create(['user_id' => $student->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);

        Sanctum::actingAs($staff);

        foreach ([LleResult::RESULT_FAIL, LleResult::RESULT_PASS] as $result) {
            $this->postJson('/api/lle-results', [
                'user_id' => $student->id,
                'exam_year' => 2026,
                'result' => $result,
            ])
                ->assertStatus(422)
                ->assertJsonPath('errors.result.0', 'Lle-licensed User passed the LLE in 2025, so no result can be recorded for a later year.');
        }

        // Correcting the pass itself is still allowed.
        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2025,
            'result' => LleResult::RESULT_PASS,
            'rating' => 81.2,
        ])->assertOk();

        $this->assertSame(1, LleResult::where('user_id', $student->id)->count());
    }

    public function test_a_pass_cannot_come_before_another_sitting(): void
    {
        $staff = $this->makeUser('lle-staff-before@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-retaker@example.com', User::ROLE_STUDENT, '2301915', 4);

        LleResult::create(['user_id' => $student->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL]);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2025,
            'result' => LleResult::RESULT_PASS,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.result.0', 'Lle-retaker User already has a result for 2026, and nothing can come after a pass. Record 2025 as Failed, or check the year.');

        // Failing twice is a real history.
        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2025,
            'result' => LleResult::RESULT_FAIL,
        ])->assertCreated();
    }

    public function test_the_sitting_cannot_be_in_the_future_or_outside_its_year(): void
    {
        // 10 a.m. in Manila on 16 November 2026.
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Manila'));

        $staff = $this->makeUser('lle-staff-dates@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-dates@example.com', User::ROLE_STUDENT, '2301916', 4);

        Sanctum::actingAs($staff);

        $payload = ['user_id' => $student->id, 'result' => LleResult::RESULT_FAIL];

        $this->postJson('/api/lle-results', $payload + ['exam_year' => 2027])
            ->assertStatus(422)
            ->assertJsonPath('errors.exam_year.0', 'The exam year cannot be later than this year (2026).');

        $this->postJson('/api/lle-results', $payload + ['exam_year' => 2026, 'taken_at' => '2026-11-17'])
            ->assertStatus(422)
            ->assertJsonPath('errors.taken_at.0', 'Date taken cannot be later than today.');

        $this->postJson('/api/lle-results', $payload + ['exam_year' => 2026, 'taken_at' => '2025-09-27'])
            ->assertStatus(422)
            ->assertJsonPath('errors.taken_at.0', 'This date is in 2025, but the exam year is 2026.');

        $this->assertDatabaseCount('lle_results', 0);
    }

    public function test_today_is_the_school_s_day_not_the_server_s(): void
    {
        // 7 a.m. on 27 September in Manila is still the 26th in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-09-27 07:00', 'Asia/Manila'));

        $staff = $this->makeUser('lle-staff-tz@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-tz@example.com', User::ROLE_STUDENT, '2301917', 4);

        Sanctum::actingAs($staff);

        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'taken_at' => '2026-09-27',
            'result' => LleResult::RESULT_PASS,
        ])->assertCreated();
    }

    public function test_one_student_s_results_can_be_listed_with_the_passing_average(): void
    {
        $staff = $this->makeUser('lle-staff-one@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-one@example.com', User::ROLE_STUDENT, '2301918', 4);
        $other = $this->makeUser('lle-other@example.com', User::ROLE_STUDENT, '2301919', 4);

        LleResult::create(['user_id' => $student->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL]);
        LleResult::create(['user_id' => $student->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);
        LleResult::create(['user_id' => $other->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);

        Sanctum::actingAs($staff);

        $this->getJson("/api/lle-results?user_id={$student->id}&per_page=100")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('passing_averages.2004', 75);
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

    public function test_a_student_who_did_not_take_the_exam_is_recorded_with_no_date_or_average(): void
    {
        $staff = $this->makeUser('lle-staff-dnt@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('lle-absent@example.com', User::ROLE_STUDENT, '2301930', 4);

        Sanctum::actingAs($staff);

        $payload = ['user_id' => $student->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_DID_NOT_TAKE];

        // Nobody who did not sit has a date taken or a general average.
        $this->postJson('/api/lle-results', $payload + ['rating' => 70])
            ->assertStatus(422)
            ->assertJsonPath('errors.rating.0', LleResultRules::NOT_TAKEN_AVERAGE_MESSAGE);
        $this->postJson('/api/lle-results', $payload + ['taken_at' => '2025-09-14'])
            ->assertStatus(422)
            ->assertJsonPath('errors.taken_at.0', LleResultRules::NOT_TAKEN_DATE_MESSAGE);
        $this->assertDatabaseCount('lle_results', 0);

        $this->postJson('/api/lle-results', $payload + ['remarks' => 'Did not apply.'])
            ->assertCreated()
            ->assertJsonPath('result.result', LleResult::RESULT_DID_NOT_TAKE)
            ->assertJsonPath('result.taken_at', null)
            ->assertJsonPath('result.rating', null);

        // A sitting in a later year is the student's first; a pass may follow.
        $this->postJson('/api/lle-results', [
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => LleResult::RESULT_PASS,
            'rating' => 80,
        ])->assertCreated();

        $this->getJson('/api/lle-results?result=did_not_take')
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.exam_year', 2025);
        $this->assertFalse(LleResult::where('exam_year', 2025)->first()->wasTaken());
        $this->assertSame(1, LleResult::query()->taken()->count());
    }

    public function test_did_not_take_follows_the_order_of_the_student_s_other_records(): void
    {
        $staff = $this->makeUser('lle-staff-dnt-order@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $licensed = $this->makeUser('lle-dnt-licensed@example.com', User::ROLE_STUDENT, '2301931', 4);
        $later = $this->makeUser('lle-dnt-later@example.com', User::ROLE_STUDENT, '2301932', 4);

        LleResult::create(['user_id' => $licensed->id, 'exam_year' => 2024, 'result' => LleResult::RESULT_PASS]);
        LleResult::create(['user_id' => $later->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_DID_NOT_TAKE]);

        Sanctum::actingAs($staff);

        // Nothing is recorded after a pass, a did-not-take included.
        $this->postJson('/api/lle-results', ['user_id' => $licensed->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_DID_NOT_TAKE])
            ->assertStatus(422)
            ->assertJsonPath('errors.result.0', 'Lle-dnt-licensed User passed the LLE in 2024, so no result can be recorded for a later year.');

        // A pass before a later record says which record is in the way.
        $this->postJson('/api/lle-results', ['user_id' => $later->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS])
            ->assertStatus(422)
            ->assertJsonPath('errors.result.0', 'Lle-dnt-later User is recorded as not taking the LLE in 2026, and nothing can come after a pass. Remove that record first, or check the year.');

        $this->postJson('/api/lle-results', ['user_id' => $later->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_FAIL])
            ->assertCreated();
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
