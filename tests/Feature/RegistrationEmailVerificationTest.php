<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Self-registration is limited to the institution's mailboxes, and an account
 * stays unusable until the emailed link is opened. The domain check alone only
 * filters what somebody may type into the form -- anyone can type a plausible
 * address -- so the verification link is what actually proves they can read
 * that mailbox, and only LNU issues mailboxes on lnu.edu.ph.
 */
class RegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------ the institutional gate

    public function test_registration_refuses_an_outside_email_domain(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['email' => 'someone@gmail.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_refuses_the_institution_domain_as_a_subdomain(): void
    {
        // lnu.edu.ph.example.com is a domain anyone can buy and hand out
        // mailboxes on. A substring test would accept it, which is exactly the
        // mistake this rule exists to avoid.
        $this->postJson('/api/auth/register', $this->payload([
            'email' => 'attacker@lnu.edu.ph.example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_domain_check_is_case_insensitive(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload([
            'email' => 'Juan.DelaCruz@LNU.EDU.PH',
        ]))->assertStatus(201);
    }

    // ------------------------------------------------ verification lifecycle

    public function test_registering_creates_an_unverified_account_with_no_session(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', $this->payload());

        $response->assertStatus(201)
            ->assertJsonPath('email', 'juan@lnu.edu.ph')
            ->assertCookieMissing('auth_token');

        $user = User::firstWhere('email', 'juan@lnu.edu.ph');

        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at, 'a fresh signup must start unverified');
        $this->assertSame(0, $user->tokens()->count(), 'no token may exist before verification');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_self_registered_student_lands_in_the_enrolled_cohort(): void
    {
        // A null student_status reads as "--" in the staff directory and
        // matches no student_status filter, so the students who just signed up
        // would be the ones staff cannot find. admission_year is derived the
        // way the lifecycle migration backfilled it: a second year signing up
        // this year was admitted last year.
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload(['year_level' => 2]))
            ->assertStatus(201);

        $user = User::firstWhere('email', 'juan@lnu.edu.ph');

        $this->assertSame(User::STUDENT_STATUS_ENROLLED, $user->student_status);
        $this->assertNull($user->archived_at, 'an enrolled student is not archived');
        $this->assertSame(now()->year - 1, $user->admission_year);
    }

    public function test_a_self_registered_student_is_visible_under_the_enrolled_filter(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/students/directory?student_status=enrolled')
            ->assertOk()
            ->assertJsonPath('students.0.email', 'juan@lnu.edu.ph');
    }

    public function test_login_is_refused_while_the_address_is_unverified(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/auth/login', [
            'identifier' => 'juan@lnu.edu.ph',
            'password' => 'Secret12345!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('unverified', true)
            ->assertJsonPath('email', 'juan@lnu.edu.ph');
    }

    public function test_opening_the_emailed_link_verifies_and_unlocks_login(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $user = User::firstWhere('email', 'juan@lnu.edu.ph');

        $this->get($this->verificationUrl($user))->assertRedirectContains('verified=1');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/login', [
            'identifier' => 'juan@lnu.edu.ph',
            'password' => 'Secret12345!',
        ])->assertStatus(200);
    }

    public function test_an_unsigned_link_verifies_nothing(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $user = User::firstWhere('email', 'juan@lnu.edu.ph');

        $this->get(url("/api/email/verify/{$user->id}/".sha1($user->email)))
            ->assertRedirectContains('verified=expired');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_signed_link_carrying_the_wrong_hash_verifies_nothing(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $user = User::firstWhere('email', 'juan@lnu.edu.ph');

        $tampered = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1('someone.else@lnu.edu.ph'),
        ]);

        $this->get($tampered)->assertRedirectContains('verified=invalid');

        $this->assertNull($user->fresh()->email_verified_at);
    }

    // ------------------------------------------------------------- resending

    public function test_resending_never_reveals_whether_an_address_is_registered(): void
    {
        Notification::fake();
        $this->postJson('/api/auth/register', $this->payload())->assertStatus(201);

        $pending = $this->postJson('/api/email/verification-notification', [
            'email' => 'juan@lnu.edu.ph',
        ])->assertStatus(200);

        $unknown = $this->postJson('/api/email/verification-notification', [
            'email' => 'nobody@lnu.edu.ph',
        ])->assertStatus(200);

        $this->assertSame(
            $pending->json('message'),
            $unknown->json('message'),
            'a differing reply would let anyone probe which addresses exist',
        );
    }

    // --------------------------------------------- accounts that skip the gate

    public function test_an_admin_provisioned_account_can_sign_in_immediately(): void
    {
        // Staff entering a colleague's address ARE the verification step.
        // Without this, enforcing verification at login would lock out every
        // account created by hand -- including the ones used to fix it.
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
            'password' => 'Secret12345!',
        ])->assertSuccessful();

        $created = User::firstWhere('email', 'maria.santos@lnu.edu.ph');

        $this->assertNotNull(
            $created->email_verified_at,
            'an admin-created account must not be left waiting on a verification email',
        );

        $this->postJson('/api/auth/login', [
            'identifier' => 'maria.santos@lnu.edu.ph',
            'password' => 'Secret12345!',
        ])->assertStatus(200);
    }

    // --------------------------------------------------------------- helpers

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_id' => '2300001',
            'year_level' => 1,
            'email' => 'juan@lnu.edu.ph',
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
            'agreed' => true,
        ], $overrides);
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }
}
