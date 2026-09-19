<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One password policy, enforced everywhere a password can be set.
 *
 * Defined once in AppServiceProvider::configurePasswordPolicy() and applied
 * through PasswordRule::defaults(), so these tests are really checking that
 * every entry point asks for the shared rule rather than rolling its own.
 *
 * Note what is deliberately NOT covered: login. Complexity belongs at the
 * moment a password is chosen, never at sign-in -- accounts created before
 * this policy must keep working, which the last test pins down.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public static function weakPasswords(): array
    {
        return [
            'no uppercase' => ['qwerty123!'],
            'no lowercase' => ['QWERTY123!'],
            'no number' => ['Qwertyui!'],
            'no symbol' => ['Qwerty123'],
            'too short' => ['Qw3!'],
            'the one that slipped through before' => ['qwerty123'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_registration_refuses_a_password_missing_a_required_class(string $password): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->registration($password))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_accepts_a_password_meeting_every_requirement(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->registration('Qwerty123!'))
            ->assertStatus(201);

        $this->assertDatabaseCount('users', 1);
    }

    #[DataProvider('weakPasswords')]
    public function test_a_password_reset_refuses_the_same_weak_passwords(string $password): void
    {
        $user = User::factory()->create(['email' => 'juan@lnu.edu.ph']);

        $this->postJson('/api/auth/reset-password', [
            'token' => app('auth.password.broker')->createToken($user),
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_admin_cannot_provision_an_account_with_a_weak_password(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'email' => 'registrar@lnu.edu.ph',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]));

        $this->postJson('/api/admin/users', [
            'name' => 'Maria Santos',
            'email' => 'maria.santos@lnu.edu.ph',
            'role' => User::ROLE_STUDENT,
            'student_id' => '2301777',
            'year_level' => 2,
            'password' => 'qwerty123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_an_account_whose_password_predates_the_policy_can_still_sign_in(): void
    {
        // Set straight on the model, the way every existing row got there --
        // the policy governs what may be chosen, not what is already stored.
        // Enforcing it at login would lock out the entire institution.
        $user = User::factory()->create([
            'email' => 'longtimer@lnu.edu.ph',
            'password' => 'qwerty123',
            'is_active' => true,
        ]);

        $this->assertNotNull($user->email_verified_at);

        $this->postJson('/api/auth/login', [
            'identifier' => 'longtimer@lnu.edu.ph',
            'password' => 'qwerty123',
        ])->assertStatus(200);
    }

    private function registration(string $password): array
    {
        return [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_id' => '2300001',
            'year_level' => 1,
            'email' => 'juan@lnu.edu.ph',
            'password' => $password,
            'password_confirmation' => $password,
            'agreed' => true,
        ];
    }
}
