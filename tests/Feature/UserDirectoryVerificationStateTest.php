<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Staff must be able to tell a working account from a dormant signup.
 *
 * Two independent flags govern whether an account can be used: is_active is
 * the switch staff control, and email_verified_at is the holder proving they
 * can read the mailbox they signed up with. Login requires both.
 *
 * The directory originally returned only is_active, so a registration made
 * with a plausible but non-existent institutional address showed a green
 * "Active" badge in the student list while being unable to log in at all --
 * and anyone counting enrolled students was counting phantoms.
 *
 * This pins the field to the payload. A refactor that trims the select back
 * for tidiness would otherwise reintroduce the same blind spot silently,
 * because the frontend degrades quietly rather than erroring.
 */
class UserDirectoryVerificationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_directory_reports_verification_state_for_each_account(): void
    {
        Sanctum::actingAs($this->admin());

        $verified = User::factory()->create([
            'email' => 'verified@lnu.edu.ph',
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // The shape a self-registration leaves behind before anyone clicks the
        // emailed link: active, but unusable.
        $pending = User::factory()->create([
            'email' => 'pending@lnu.edu.ph',
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $rows = collect($this->getJson('/api/admin/users')->assertStatus(200)->json('users'))
            ->keyBy('email');

        $this->assertArrayHasKey(
            'email_verified_at',
            $rows['verified@lnu.edu.ph'],
            'the directory must expose verification state, or the UI can only show is_active',
        );

        $this->assertNotNull($rows['verified@lnu.edu.ph']['email_verified_at']);
        $this->assertNull($rows['pending@lnu.edu.ph']['email_verified_at']);

        // Both are is_active, which is exactly why is_active alone cannot
        // distinguish them.
        $this->assertTrue((bool) $rows['verified@lnu.edu.ph']['is_active']);
        $this->assertTrue((bool) $rows['pending@lnu.edu.ph']['is_active']);
    }

    public function test_an_unverified_account_really_cannot_sign_in(): void
    {
        User::factory()->create([
            'email' => 'pending@lnu.edu.ph',
            'password' => 'Qwerty123!',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'pending@lnu.edu.ph',
            'password' => 'Qwerty123!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('unverified', true);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'registrar@lnu.edu.ph',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
