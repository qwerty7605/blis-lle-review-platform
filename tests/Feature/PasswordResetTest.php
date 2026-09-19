<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Password recovery by emailed single-use link.
 *
 * The expiry window matters far less here than two other properties: the reply
 * must not reveal which addresses have accounts, and a completed reset must
 * revoke every existing session. People reset passwords precisely because they
 * suspect somebody else is already inside.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------ requesting a link

    public function test_requesting_a_reset_emails_a_link_to_a_known_address(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(200);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reply_is_identical_for_an_unknown_address(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'ghost@lnu.edu.ph']);

        $this->assertSame(
            $known->json('message'),
            $unknown->json('message'),
            'a differing reply would let anyone probe which addresses have accounts',
        );

        Notification::assertCount(1);
    }

    public function test_the_emailed_link_points_at_the_spa_rather_than_the_api(): void
    {
        // Regression guard. ResetPassword::toMailUsing hands the callback the
        // raw token, not a finished URL the way VerifyEmail does -- getting
        // that wrong produces a button linking to a bare token string.
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/reset-password?token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    // ---------------------------------------------------------- spending it

    public function test_a_valid_token_changes_the_password(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'Brand-New-Secret1',
            'password_confirmation' => 'Brand-New-Secret1',
        ])->assertStatus(200);

        $this->assertTrue(Hash::check('Brand-New-Secret1', $user->fresh()->password));
    }

    public function test_a_completed_reset_revokes_every_existing_session(): void
    {
        $user = $this->makeUser();
        $user->createToken('phone');
        $user->createToken('library-pc');
        $this->assertSame(2, $user->tokens()->count());

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'Brand-New-Secret1',
            'password_confirmation' => 'Brand-New-Secret1',
        ])->assertStatus(200);

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'whoever prompted the reset must not keep their session',
        );
    }

    public function test_a_token_cannot_be_spent_twice(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);

        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Brand-New-Secret1',
            'password_confirmation' => 'Brand-New-Secret1',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(200);
        $this->postJson('/api/auth/reset-password', $payload)->assertStatus(422);
    }

    public function test_a_token_issued_for_someone_else_is_refused(): void
    {
        $victim = $this->makeUser();
        $attacker = $this->makeUser('attacker@lnu.edu.ph');

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($attacker),
            'email' => $victim->email,
            'password' => 'Brand-New-Secret1',
            'password_confirmation' => 'Brand-New-Secret1',
        ])->assertStatus(422);

        $this->assertTrue(
            Hash::check('Original-Secret1', $victim->fresh()->password),
            "another account's token must never move a different password",
        );
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/reset-password', [
            'token' => $this->tokenFor($user),
            'email' => $user->email,
            'password' => 'Long-Enough-Secret1',
            'password_confirmation' => 'Different-Secret1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // --------------------------------------------------------------- helpers

    private function makeUser(string $email = 'juan@lnu.edu.ph'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => 'Original-Secret1',
            'is_active' => true,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return app('auth.password.broker')->createToken($user);
    }
}
