<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Administrator routes (routes/api/admin.php) left out by the other admin
 * tests: the archive filter on the user list, the rules an edit must keep,
 * account recovery by student ID, the room list, and that only an active
 * administrator reaches any of it.
 */
class AdminRoutesGapsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
        Sanctum::actingAs($this->admin);
    }

    public function test_the_user_list_filters_current_and_archived_students(): void
    {
        $current = $this->makeStudent(['name' => 'Current One']);
        $archived = $this->makeStudent(['name' => 'Archived One', 'archived_at' => now(), 'student_status' => User::STUDENT_STATUS_GRADUATED]);

        $archivedIds = collect($this->getJson('/api/admin/users?archive_state=archived&role=student')->assertOk()->json('users'))->pluck('id');
        $activeIds = collect($this->getJson('/api/admin/users?archive_state=current&role=student')->assertOk()->json('users'))->pluck('id');

        $this->assertTrue($archivedIds->contains($archived->id));
        $this->assertFalse($archivedIds->contains($current->id));
        $this->assertTrue($activeIds->contains($current->id));
        $this->assertFalse($activeIds->contains($archived->id));
    }

    public function test_a_student_account_cannot_lose_its_student_id(): void
    {
        $student = $this->makeStudent();

        $this->patchJson("/api/admin/users/{$student->id}", ['student_id' => null])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Student ID is required for student accounts.');
    }

    public function test_an_administrator_cannot_disable_or_demote_themselves(): void
    {
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot disable your own account.');
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['role' => User::ROLE_STAFF_MASTER_EXAMINER])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot remove your own admin role.');
    }

    public function test_making_a_student_into_staff_clears_their_student_record(): void
    {
        $student = $this->makeStudent(['archived_at' => now(), 'student_status' => User::STUDENT_STATUS_GRADUATED]);

        $this->patchJson("/api/admin/users/{$student->id}", ['role' => User::ROLE_STAFF_MASTER_EXAMINER])->assertOk();

        $student->refresh();
        $this->assertNull($student->student_id);
        $this->assertNull($student->student_status);
        $this->assertNull($student->archived_at);
    }

    public function test_archiving_keeps_a_known_reason_and_a_new_password_is_set(): void
    {
        $student = $this->makeStudent(['archived_at' => now(), 'student_status' => User::STUDENT_STATUS_GRADUATED]);

        $this->patchJson("/api/admin/users/{$student->id}", ['archived' => true, 'password' => 'N3w!Password'])->assertOk();

        $student->refresh();
        $this->assertSame(User::STUDENT_STATUS_GRADUATED, $student->student_status);
        $this->assertTrue(Hash::check('N3w!Password', $student->password));
    }

    public function test_recovery_by_student_id_moves_the_account_to_a_new_address(): void
    {
        $student = $this->makeStudent(['student_id' => '2301555', 'email' => 'lost@lnu.edu.ph']);

        $this->postJson("/api/admin/users/{$student->id}/recover-account", [
            'student_id' => '2301555',
            'email' => 'found@lnu.edu.ph',
            'password' => 'R3covered!pass',
        ])->assertOk();

        $this->assertSame('found@lnu.edu.ph', $student->fresh()->email);
    }

    public function test_recovery_is_refused_when_the_student_id_cannot_be_checked(): void
    {
        $body = ['student_id' => '2301555', 'email' => 'found@lnu.edu.ph', 'password' => 'R3covered!pass'];

        $teacher = $this->makeTeacher();
        $this->postJson("/api/admin/users/{$teacher->id}/recover-account", $body)
            ->assertStatus(422)->assertJsonPath('message', 'Account recovery via student ID is only available for student accounts.');

        $noId = $this->makeStudent(['student_id' => null]);
        $this->postJson("/api/admin/users/{$noId->id}/recover-account", $body)
            ->assertStatus(422)->assertJsonPath('message', 'This student account has no student ID on file.');

        $wrong = $this->makeStudent(['student_id' => '2301999']);
        $this->postJson("/api/admin/users/{$wrong->id}/recover-account", $body)
            ->assertStatus(422)->assertJsonPath('message', 'Student ID verification failed.');
    }

    public function test_the_admin_room_list_shows_every_teacher_s_rooms(): void
    {
        $this->makeRoom($this->makeTeacher());
        $this->makeRoom($this->makeTeacher());

        $this->getJson('/api/admin/rooms')->assertOk()->assertJsonCount(2, 'rooms');
    }

    public function test_only_an_administrator_reaches_the_admin_routes(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($this->makeTeacher());

        $this->getJson('/api/admin/users')->assertForbidden();
        $this->postJson('/api/admin/users', [])->assertForbidden();
        $this->patchJson("/api/admin/users/{$student->id}", [])->assertForbidden();
        $this->postJson("/api/admin/users/{$student->id}/recover-account", [])->assertForbidden();
        $this->getJson('/api/admin/rooms')->assertForbidden();
        $this->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/admin/year-end')->assertForbidden();
        $this->postJson('/api/admin/year-end', [])->assertForbidden();
    }

    public function test_a_disabled_administrator_is_signed_out_of_the_admin_routes(): void
    {
        $student = $this->makeStudent();
        $this->admin->forceFill(['is_active' => false])->save();

        foreach ([
            ['GET', '/api/admin/users'],
            ['POST', '/api/admin/users'],
            ['PATCH', "/api/admin/users/{$student->id}"],
            ['POST', "/api/admin/users/{$student->id}/recover-account"],
            ['GET', '/api/admin/rooms'],
            ['GET', '/api/admin/audit-logs'],
            ['GET', '/api/admin/year-end'],
            ['POST', '/api/admin/year-end'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
