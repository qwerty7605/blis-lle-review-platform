<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------- status / archived_at sync

    public function test_a_non_enrolled_status_archives_the_student(): void
    {
        $student = $this->makeStudent();
        $this->assertNull($student->archived_at);

        $student->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();

        $this->assertSame(User::STUDENT_STATUS_GRADUATED, $student->student_status);
        $this->assertNotNull($student->archived_at, 'a graduate must leave circulation');
    }

    public function test_returning_a_student_to_enrolled_clears_the_archive(): void
    {
        $student = $this->makeStudent();
        $student->applyStudentStatus(User::STUDENT_STATUS_DROPPED)->save();
        $this->assertNotNull($student->archived_at);

        $student->applyStudentStatus(User::STUDENT_STATUS_ENROLLED)->save();

        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $student->student_status);
        $this->assertNull($student->archived_at);
    }

    public function test_reclassifying_an_archived_student_keeps_the_original_leaving_date(): void
    {
        $student = $this->makeStudent();
        $student->applyStudentStatus(User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED)->save();

        $originalDate = $student->fresh()->archived_at;
        $this->travel(2)->days();

        // Classifying why they left is not the same event as leaving.
        $student->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();

        $this->assertEquals(
            $originalDate->toDateTimeString(),
            $student->fresh()->archived_at->toDateTimeString(),
        );
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeStudent()->applyStudentStatus('expelled_maybe');
    }

    // ------------------------------------------------------ admin endpoint

    public function test_staff_can_record_an_irregular_year_level_beyond_the_standard_ladder(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}", ['year_level' => 5])
            ->assertOk();

        $this->assertSame(5, (int) $student->fresh()->year_level);
    }

    public function test_a_year_level_past_the_maximum_residency_is_still_rejected(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}", ['year_level' => 99])
            ->assertStatus(422);
    }

    public function test_year_level_is_required_while_enrolled_but_not_after_graduating(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}", ['year_level' => null])
            ->assertStatus(422);

        // A graduate has no current year level, and forcing one to stay is how
        // they end up indistinguishable from a real fourth year.
        $this->patchJson("/api/admin/users/{$student->id}", [
            'year_level' => null,
            'student_status' => User::STUDENT_STATUS_GRADUATED,
        ])->assertOk();

        $fresh = $student->fresh();
        $this->assertNull($fresh->year_level);
        $this->assertSame(User::STUDENT_STATUS_GRADUATED, $fresh->student_status);
        $this->assertNotNull($fresh->archived_at);
    }

    public function test_setting_a_status_through_the_endpoint_archives_the_student(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}", [
            'student_status' => User::STUDENT_STATUS_TRANSFERRED,
        ])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame(User::STUDENT_STATUS_TRANSFERRED, $fresh->student_status);
        $this->assertNotNull($fresh->archived_at);
    }

    public function test_the_archive_checkbox_still_works_and_lands_in_the_unspecified_state(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();

        Sanctum::actingAs($admin);

        // Ticking "archived" without saying why must not claim they graduated.
        $this->patchJson("/api/admin/users/{$student->id}", ['archived' => true])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame(User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED, $fresh->student_status);
        $this->assertNotNull($fresh->archived_at);
    }

    public function test_unarchiving_through_the_checkbox_returns_the_student_to_enrolled(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent();
        $student->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$student->id}", ['archived' => false])->assertOk();

        $fresh = $student->fresh();
        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $fresh->student_status);
        $this->assertNull($fresh->archived_at);
    }

    public function test_a_created_student_starts_enrolled_with_a_cohort(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users', [
            'name' => 'New Student',
            'email' => 'new-lifecycle-student@example.com',
            'student_id' => '2401234',
            'role' => User::ROLE_STUDENT,
            'year_level' => 1,
            'admission_year' => 2026,
            'password' => 'password123',
        ])->assertSuccessful();

        $created = User::where('email', 'new-lifecycle-student@example.com')->first();

        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $created->student_status);
        $this->assertSame(2026, (int) $created->admission_year);
        $this->assertNull($created->archived_at);
    }

    public function test_non_students_never_carry_a_lifecycle_status(): void
    {
        $admin = $this->makeAdmin();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users', [
            'name' => 'New Staff',
            'email' => 'new-lifecycle-staff@example.com',
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'password' => 'password123',
        ])->assertSuccessful();

        $created = User::where('email', 'new-lifecycle-staff@example.com')->first();

        $this->assertNull($created->student_status);
        $this->assertNull($created->archived_at);
    }

    // ---------------------------------------------------------- directory

    public function test_the_directory_can_be_filtered_by_status_and_by_cohort(): void
    {
        $graduate = $this->makeStudent('grad@example.com', '2201111', 4, 2022);
        $graduate->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();

        $dropout = $this->makeStudent('drop@example.com', '2202222', 3, 2022);
        $dropout->applyStudentStatus(User::STUDENT_STATUS_DROPPED)->save();

        $this->makeStudent('current@example.com', '2603333', 1, 2026);

        $service = app(DashboardAnalyticsService::class);

        $this->assertSame(1, $service->paginateStudentDirectory([
            'archive_state' => 'all',
            'student_status' => User::STUDENT_STATUS_GRADUATED,
        ])['meta']['total']);

        // A dropout is not a graduate -- the whole point of separating them.
        $this->assertSame(1, $service->paginateStudentDirectory([
            'archive_state' => 'all',
            'student_status' => User::STUDENT_STATUS_DROPPED,
        ])['meta']['total']);

        $this->assertSame(2, $service->paginateStudentDirectory([
            'archive_state' => 'all',
            'admission_year' => 2022,
        ])['meta']['total'], 'batch 2022 holds both the graduate and the dropout');

        $rows = $service->paginateStudentDirectory(['per_page' => 10])['students'];

        $this->assertSame(1, count($rows), 'the default view still shows only enrolled students');
        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $rows[0]['student_status']);
        $this->assertSame(2026, (int) $rows[0]['admission_year']);
    }

    // ------------------------------------------------------------ helpers

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'email' => 'lifecycle-admin@example.com',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeStudent(
        string $email = 'lifecycle-student@example.com',
        string $studentId = '2309999',
        int $yearLevel = 2,
        ?int $admissionYear = 2025,
    ): User {
        return User::factory()->create([
            'email' => $email,
            'student_id' => $studentId,
            'role' => User::ROLE_STUDENT,
            'year_level' => $yearLevel,
            'student_status' => User::STUDENT_STATUS_ENROLLED,
            'admission_year' => $admissionYear,
            'is_active' => true,
        ]);
    }
}
