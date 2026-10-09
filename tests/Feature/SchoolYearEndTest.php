<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Closing a school year is a proposal an admin confirms, not something the
 * system decides: it has no grades, so it cannot know who actually finished.
 * These pin the two halves -- what is proposed, and that only the students
 * the admin sent back are changed, once.
 */
class SchoolYearEndTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ proposal

    public function test_the_proposal_graduates_fourth_years_and_moves_everyone_else_up(): void
    {
        $first = $this->makeStudent(1);
        $third = $this->makeStudent(3);
        $fourth = $this->makeStudent(4);
        $irregular = $this->makeStudent(6);

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->getJson('/api/admin/year-end')->assertOk();

        $response->assertJsonPath('summary', ['students' => 4, 'graduate' => 2, 'advance' => 2, 'none' => 0]);

        $rows = collect($response->json('students'))->keyBy('id');

        $this->assertSame('advance', $rows[$first->id]['proposed']);
        $this->assertSame(2, $rows[$first->id]['next_year_level']);
        $this->assertSame('advance', $rows[$third->id]['proposed']);
        $this->assertSame(4, $rows[$third->id]['next_year_level']);
        $this->assertSame('graduate', $rows[$fourth->id]['proposed']);
        $this->assertNull($rows[$fourth->id]['next_year_level']);
        // Past the standard four years: still a finishing student, not one to
        // push to a seventh.
        $this->assertSame('graduate', $rows[$irregular->id]['proposed']);
    }

    public function test_the_proposal_leaves_out_archived_students_and_staff(): void
    {
        $current = $this->makeStudent(2);
        $graduate = $this->makeStudent(4);
        $graduate->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();
        User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);

        Sanctum::actingAs($this->makeAdmin());

        $ids = collect($this->getJson('/api/admin/year-end')->assertOk()->json('students'))->pluck('id')->all();

        $this->assertSame([$current->id], $ids);
    }

    public function test_a_student_with_no_year_level_gets_no_proposal(): void
    {
        $student = $this->makeStudent(null);

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->getJson('/api/admin/year-end')->assertOk();

        $response->assertJsonPath('summary.none', 1);
        $this->assertNull($response->json('students.0.proposed'));
        $this->assertSame($student->id, $response->json('students.0.id'));
    }

    public function test_reading_the_proposal_changes_nothing(): void
    {
        $student = $this->makeStudent(4);

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson('/api/admin/year-end')->assertOk();

        $student->refresh();
        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $student->student_status);
        $this->assertSame(4, $student->year_level);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_proposal_says_when_the_year_was_last_closed(): void
    {
        $admin = $this->makeAdmin();
        $student = $this->makeStudent(1);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/year-end')->assertOk()->assertJsonPath('last_closed', null);

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'advance', 'year_level' => 1],
        ]])->assertOk();

        // Closing it again by accident would move the cohort up twice, so the
        // screen is told it has already been done, by whom, and how much.
        $this->getJson('/api/admin/year-end')
            ->assertOk()
            ->assertJsonPath('last_closed.by', $admin->name)
            ->assertJsonPath('last_closed.graduated', 0)
            ->assertJsonPath('last_closed.advanced', 1);
    }

    public function test_only_an_admin_can_read_or_apply_it(): void
    {
        $student = $this->makeStudent(1);
        $decision = ['students' => [['id' => $student->id, 'action' => 'advance', 'year_level' => 1]]];

        foreach ([User::ROLE_STAFF_MASTER_EXAMINER, User::ROLE_STUDENT] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));

            $this->getJson('/api/admin/year-end')->assertStatus(403);
            $this->postJson('/api/admin/year-end', $decision)->assertStatus(403);
        }

        $this->assertSame(1, $student->fresh()->year_level);
    }

    // --------------------------------------------------------------- apply

    public function test_confirmed_students_are_graduated_and_moved_up_and_the_rest_are_left_alone(): void
    {
        $graduating = $this->makeStudent(4);
        $retained = $this->makeStudent(4);
        $moving = $this->makeStudent(2);
        $repeating = $this->makeStudent(2);

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $graduating->id, 'action' => 'graduate', 'year_level' => 4],
            ['id' => $moving->id, 'action' => 'advance', 'year_level' => 2],
        ]])
            ->assertOk()
            ->assertJsonPath('graduated', 1)
            ->assertJsonPath('advanced', 1)
            ->assertJsonPath('skipped', []);

        $graduating->refresh();
        $this->assertSame(User::STUDENT_STATUS_GRADUATED, $graduating->student_status);
        $this->assertNotNull($graduating->archived_at, 'a graduate must leave the rosters');

        $this->assertSame(3, $moving->fresh()->year_level);

        // Not sent back, so not touched: the fourth year who is not finishing
        // and the second year who is repeating.
        $retained->refresh();
        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $retained->student_status);
        $this->assertNull($retained->archived_at);
        $this->assertSame(4, $retained->year_level);
        $this->assertSame(2, $repeating->fresh()->year_level);
    }

    public function test_submitting_twice_does_not_move_anyone_up_twice(): void
    {
        $moving = $this->makeStudent(1);
        $graduating = $this->makeStudent(4);

        Sanctum::actingAs($this->makeAdmin());

        $decisions = ['students' => [
            ['id' => $moving->id, 'action' => 'advance', 'year_level' => 1],
            ['id' => $graduating->id, 'action' => 'graduate', 'year_level' => 4],
        ]];

        $this->postJson('/api/admin/year-end', $decisions)->assertOk()->assertJsonPath('advanced', 1);

        $second = $this->postJson('/api/admin/year-end', $decisions)->assertOk();

        $second->assertJsonPath('advanced', 0)->assertJsonPath('graduated', 0);
        $this->assertCount(2, $second->json('skipped'));
        $this->assertSame(2, $moving->fresh()->year_level, 'the second submission must not reach third year');
    }

    public function test_a_record_that_changed_after_the_list_was_loaded_is_skipped(): void
    {
        $student = $this->makeStudent(2);

        Sanctum::actingAs($this->makeAdmin());

        // Someone corrected the level in another tab while the list sat open.
        $student->update(['year_level' => 3]);

        $response = $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'advance', 'year_level' => 2],
        ]])->assertOk();

        $response->assertJsonPath('advanced', 0)->assertJsonPath('skipped.0.id', $student->id);
        $this->assertStringContainsString('changed', $response->json('skipped.0.reason'));
        $this->assertSame(3, $student->fresh()->year_level);
    }

    public function test_staff_and_students_no_longer_enrolled_are_skipped(): void
    {
        $teacher = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $dropped = $this->makeStudent(2);
        $dropped->applyStudentStatus(User::STUDENT_STATUS_DROPPED)->save();

        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $teacher->id, 'action' => 'graduate', 'year_level' => 4],
            ['id' => $dropped->id, 'action' => 'advance', 'year_level' => 2],
            ['id' => 999999, 'action' => 'advance', 'year_level' => 1],
        ]])->assertOk();

        $response->assertJsonPath('graduated', 0)->assertJsonPath('advanced', 0);
        $this->assertCount(3, $response->json('skipped'));

        $this->assertNull($teacher->fresh()->student_status);
        $this->assertSame(User::STUDENT_STATUS_DROPPED, $dropped->fresh()->student_status);
        $this->assertSame(2, $dropped->fresh()->year_level);
    }

    public function test_nobody_moves_past_the_highest_year_level(): void
    {
        $student = $this->makeStudent(User::YEAR_LEVEL_MAX);

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'advance', 'year_level' => User::YEAR_LEVEL_MAX],
        ]])->assertOk()->assertJsonPath('advanced', 0)->assertJsonPath('skipped.0.id', $student->id);

        $this->assertSame(User::YEAR_LEVEL_MAX, $student->fresh()->year_level);
    }

    public function test_every_change_is_logged(): void
    {
        $admin = $this->makeAdmin();
        $graduating = $this->makeStudent(4);
        $moving = $this->makeStudent(1);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $graduating->id, 'action' => 'graduate', 'year_level' => 4],
            ['id' => $moving->id, 'action' => 'advance', 'year_level' => 1],
        ]])->assertOk();

        $this->assertSame(3, AuditLog::count());

        $graduateEntry = AuditLog::firstWhere('action', 'admin.year_end.graduate');
        $this->assertSame($graduating->id, (int) $graduateEntry->target_id);
        $this->assertSame($admin->id, (int) $graduateEntry->actor_id);

        $advanceEntry = AuditLog::firstWhere('action', 'admin.year_end.advance');
        $this->assertSame($moving->id, (int) $advanceEntry->target_id);
        $this->assertSame(['student_id' => $moving->student_id, 'from_year_level' => 1, 'to_year_level' => 2], $advanceEntry->metadata);

        $summary = AuditLog::firstWhere('action', 'admin.year_end.apply');
        $this->assertSame(['graduated' => 1, 'advanced' => 1, 'skipped' => 0], $summary->metadata);
        // Newest first is how the log reads, so the summary has to be the
        // last row written to sit above the students it covers.
        $this->assertSame(AuditLog::max('id'), $summary->id);
    }

    public function test_a_student_graduated_by_mistake_can_be_restored(): void
    {
        $student = $this->makeStudent(4);

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'graduate', 'year_level' => 4],
        ]])->assertOk();

        $this->patchJson("/api/admin/users/{$student->id}", ['archived' => false])->assertOk();

        $student->refresh();
        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $student->student_status);
        $this->assertNull($student->archived_at);
        $this->assertSame(4, $student->year_level);
    }

    public function test_malformed_decisions_are_rejected_before_anything_changes(): void
    {
        $student = $this->makeStudent(1);

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/year-end', ['students' => []])
            ->assertStatus(422)->assertJsonValidationErrors('students');

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'expel', 'year_level' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('students.0.action');

        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'advance'],
        ]])->assertStatus(422)->assertJsonValidationErrors('students.0.year_level');

        // The same student twice would otherwise be moved up once and then
        // reported as skipped, which reads as an error that never happened.
        $this->postJson('/api/admin/year-end', ['students' => [
            ['id' => $student->id, 'action' => 'advance', 'year_level' => 1],
            ['id' => $student->id, 'action' => 'advance', 'year_level' => 1],
        ]])->assertStatus(422)->assertJsonValidationErrors('students.0.id');

        $this->assertSame(1, $student->fresh()->year_level);
        $this->assertSame(0, AuditLog::count());
    }

    // --------------------------------------------------------------- helpers

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'email' => 'registrar@lnu.edu.ph',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeStudent(?int $yearLevel): User
    {
        static $sequence = 0;
        $sequence++;

        return User::factory()->create([
            'student_id' => (string) (2300000 + $sequence),
            'role' => User::ROLE_STUDENT,
            'year_level' => $yearLevel,
            'student_status' => User::STUDENT_STATUS_ENROLLED,
            'admission_year' => 2025,
            'is_active' => true,
        ]);
    }
}
