<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signing in needs a verified email, and the only way to verify one was the
 * link sent to it. Someone who registered with an address that cannot receive
 * that link -- no mailbox on the school's domain yet -- was stuck as
 * "Pending verification" with nothing an administrator could do about it.
 *
 * An administrator can now vouch for the address instead, and take that back.
 */
class AdminEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_can_verify_an_account_that_is_waiting_on_its_email_link(): void
    {
        $admin = $this->admin();
        $pending = $this->pendingStudent();

        $this->signIn($pending)->assertStatus(403)->assertJsonPath('unverified', true);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$pending->id}", ['email_verified' => true])
            ->assertOk()
            ->assertJsonPath('message', 'User updated');

        $this->assertNotNull($pending->fresh()->email_verified_at);
        $this->signIn($pending)->assertOk();

        $entry = AuditLog::query()->where('action', 'admin.user.update')->latest('id')->first();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertTrue($entry->metadata['email_verified']);
    }

    public function test_verifying_an_account_that_is_already_verified_keeps_its_date(): void
    {
        $verifiedAt = now()->subMonths(3)->startOfSecond();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'email_verified_at' => $verifiedAt]);

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/admin/users/{$student->id}", ['email_verified' => true])->assertOk();

        $this->assertTrue($verifiedAt->equalTo($student->fresh()->email_verified_at));
    }

    public function test_taking_verification_back_signs_the_account_out_and_blocks_sign_in(): void
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'password' => 'Qwerty123!',
            'email_verified_at' => now(),
        ]);
        $student->createToken('spa');

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/admin/users/{$student->id}", ['email_verified' => false])->assertOk();

        $this->assertNull($student->fresh()->email_verified_at);
        $this->assertSame(0, $student->tokens()->count());
        $this->signIn($student)->assertStatus(403)->assertJsonPath('unverified', true);
    }

    public function test_editing_other_details_leaves_verification_alone(): void
    {
        $pending = $this->pendingStudent();

        Sanctum::actingAs($this->admin());
        $this->patchJson("/api/admin/users/{$pending->id}", ['name' => 'Renamed Student'])->assertOk();

        $this->assertNull($pending->fresh()->email_verified_at);
    }

    public function test_an_administrator_cannot_unverify_their_own_account(): void
    {
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$admin->id}", ['email_verified' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot mark your own email as unverified.');

        $this->assertNotNull($admin->fresh()->email_verified_at);
    }

    public function test_only_an_administrator_can_verify_an_account(): void
    {
        $pending = $this->pendingStudent();

        Sanctum::actingAs(User::factory()->create([
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'email_verified_at' => now(),
        ]));

        $this->patchJson("/api/admin/users/{$pending->id}", ['email_verified' => true])->assertStatus(403);
        $this->assertNull($pending->fresh()->email_verified_at);
    }

    private function signIn(User $user): \Illuminate\Testing\TestResponse
    {
        // A sign-in made as nobody, whoever an earlier step was acting as.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/auth/login', ['identifier' => $user->email, 'password' => 'Qwerty123!']);
    }

    private function pendingStudent(): User
    {
        return User::factory()->create([
            'email' => 'pending.student@gmail.com',
            'role' => User::ROLE_STUDENT,
            'student_id' => '2400777',
            'year_level' => 1,
            'password' => 'Qwerty123!',
            'is_active' => true,
            'email_verified_at' => null,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
