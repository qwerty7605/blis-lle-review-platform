<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailWasChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What the Settings page lets a person change about their own account: name
 * and password for everyone, the sign-in email for staff and administrators.
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ name

    public function test_a_student_and_a_teacher_can_change_their_own_name(): void
    {
        foreach ([User::ROLE_STUDENT, User::ROLE_STAFF_MASTER_EXAMINER] as $role) {
            $user = $this->user($role, ['email' => "{$role}@lnu.edu.ph"]);

            $this->as($user)->patchJson('/api/account/profile', [
                'first_name' => '  Maria ',
                'middle_name' => 'Santos',
                'last_name' => 'Reyes',
            ])->assertOk()->assertJsonPath('user.name', 'Maria Santos Reyes');

            $user->refresh();
            $this->assertSame(['Maria', 'Santos', 'Reyes'], [$user->first_name, $user->middle_name, $user->last_name]);
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'account.update-name']);
        }
    }

    public function test_changing_a_name_cannot_change_anything_else(): void
    {
        $student = $this->user(User::ROLE_STUDENT, ['student_id' => '2300001', 'year_level' => 2]);

        $this->as($student)->patchJson('/api/account/profile', [
            'first_name' => 'Maria',
            'last_name' => 'Reyes',
            'role' => User::ROLE_ADMIN,
            'student_id' => '9999999',
            'year_level' => 4,
            'email' => 'other@lnu.edu.ph',
        ])->assertOk();

        $student->refresh();
        $this->assertSame(User::ROLE_STUDENT, $student->role);
        $this->assertSame('2300001', $student->student_id);
        $this->assertSame(2, (int) $student->year_level);
        $this->assertSame('me@lnu.edu.ph', $student->email);
    }

    public function test_a_name_needs_a_first_and_a_last_name(): void
    {
        $this->as($this->user(User::ROLE_STUDENT))
            ->patchJson('/api/account/profile', ['first_name' => '', 'last_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name', 'last_name']);
    }

    // -------------------------------------------------------------- password

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $user = $this->user(User::ROLE_STUDENT);

        $this->as($user)->putJson('/api/account/password', [
            'current_password' => 'not-it',
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('Secret12345!', $user->fresh()->password));
    }

    public function test_a_new_password_has_to_meet_the_policy_and_differ_from_the_old_one(): void
    {
        $user = $this->user(User::ROLE_STUDENT);

        foreach (['short', 'Secret12345!'] as $password) {
            $this->as($user)->putJson('/api/account/password', [
                'current_password' => 'Secret12345!',
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }
    }

    public function test_changing_the_password_signs_out_other_devices_but_not_this_one(): void
    {
        $user = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        $other = $user->createToken('spa');
        $mine = $user->createToken('spa');

        $this->withToken($mine->plainTextToken)->putJson('/api/account/password', [
            'current_password' => 'Secret12345!',
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecret123!', $user->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $mine->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    // ----------------------------------------------------------------- email

    public function test_a_teacher_s_email_changes_only_once_the_link_is_opened(): void
    {
        Notification::fake();
        $teacher = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);

        $this->as($teacher)->postJson('/api/account/email', [
            'email' => 'new@lnu.edu.ph',
            'current_password' => 'Secret12345!',
        ])->assertOk();

        $this->assertSame('me@lnu.edu.ph', $teacher->fresh()->email, 'asking changes nothing');

        Notification::assertSentOnDemand(
            ConfirmEmailChange::class,
            fn ($notification, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'new@lnu.edu.ph',
        );

        $this->get($this->confirmUrl($teacher, 'new@lnu.edu.ph'))->assertRedirectContains('email_changed=1');

        $teacher->refresh();
        $this->assertSame('new@lnu.edu.ph', $teacher->email);
        $this->assertNotNull($teacher->email_verified_at);
        $this->assertSame(0, $teacher->tokens()->count(), 'the account is signed out everywhere');

        Notification::assertSentOnDemand(
            EmailWasChanged::class,
            fn ($notification, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'me@lnu.edu.ph',
        );
    }

    public function test_an_email_change_link_works_once(): void
    {
        Notification::fake();
        $teacher = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        $url = $this->confirmUrl($teacher, 'new@lnu.edu.ph');

        $this->get($url)->assertRedirectContains('email_changed=1');
        $teacher->forceFill(['email' => 'later@lnu.edu.ph'])->save();

        $this->get($url)->assertRedirectContains('email_changed=invalid');
        $this->assertSame('later@lnu.edu.ph', $teacher->fresh()->email);
    }

    public function test_an_email_change_link_that_was_altered_or_has_expired_changes_nothing(): void
    {
        Notification::fake();
        $teacher = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        $url = $this->confirmUrl($teacher, 'new@lnu.edu.ph');

        $this->get(str_replace('new%40lnu.edu.ph', 'thief%40lnu.edu.ph', $url))->assertRedirectContains('email_changed=expired');

        $this->travel(2)->hours();
        $this->get($url)->assertRedirectContains('email_changed=expired');

        $this->assertSame('me@lnu.edu.ph', $teacher->fresh()->email);
    }

    public function test_an_email_change_needs_the_password_and_a_free_address(): void
    {
        Notification::fake();
        $teacher = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        User::factory()->create(['email' => 'taken@lnu.edu.ph']);

        $this->as($teacher)->postJson('/api/account/email', ['email' => 'new@lnu.edu.ph', 'current_password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->as($teacher)->postJson('/api/account/email', ['email' => 'taken@lnu.edu.ph', 'current_password' => 'Secret12345!'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        Notification::assertNothingSent();
    }

    public function test_a_student_cannot_change_their_own_email(): void
    {
        Notification::fake();

        $this->as($this->user(User::ROLE_STUDENT))
            ->postJson('/api/account/email', ['email' => 'new@lnu.edu.ph', 'current_password' => 'Secret12345!'])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    // ----------------------------------------------------------- preferences

    public function test_a_teacher_s_exam_defaults_are_kept_with_their_account(): void
    {
        $teacher = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        $defaults = [
            'total_items' => 100,
            'duration_minutes' => 120,
            'one_take_only' => true,
            'shuffle_questions' => true,
            'results_visibility_mode' => 'visible',
        ];

        $this->as($teacher)->putJson('/api/account/preferences', ['exam_defaults' => $defaults])
            ->assertOk()
            ->assertJsonPath('user.preferences.exam_defaults.duration_minutes', 120);

        // A save that sends nothing leaves them alone.
        $this->as($teacher)->putJson('/api/account/preferences', [])->assertOk();

        $this->assertSame(['exam_defaults' => $defaults], $teacher->fresh()->preferences);
    }

    public function test_anyone_can_keep_a_theme_with_their_account(): void
    {
        $student = $this->user(User::ROLE_STUDENT);

        $this->as($student)->putJson('/api/account/preferences', ['theme' => 'dark'])
            ->assertOk()
            ->assertJsonPath('user.preferences.theme', 'dark');

        $this->as($student)->putJson('/api/account/preferences', ['theme' => 'neon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['theme']);

        $this->assertSame(['theme' => 'dark'], $student->fresh()->preferences);
    }

    public function test_the_guides_someone_has_seen_are_kept_with_their_account(): void
    {
        $student = $this->user(User::ROLE_STUDENT);

        $this->as($student)->putJson('/api/account/preferences', ['theme' => 'dark'])->assertOk();

        $this->as($student)->putJson('/api/account/preferences', ['guides_seen' => ['welcome', 'student:dashboard-home', 'welcome']])
            ->assertOk()
            ->assertJsonPath('user.preferences.guides_seen', ['welcome', 'student:dashboard-home']);

        // Saving the list leaves the other preferences alone.
        $this->assertSame('dark', $student->fresh()->preferences['theme']);

        // An empty list is how "Show the guides again" starts them over.
        $this->as($student)->putJson('/api/account/preferences', ['guides_seen' => []])
            ->assertOk()
            ->assertJsonPath('user.preferences.guides_seen', []);

        $this->as($student)->putJson('/api/account/preferences', ['guides_seen' => ['<script>']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guides_seen.0']);

        $this->as($student)->putJson('/api/account/preferences', ['guides_seen' => array_map(fn ($i) => "g{$i}", range(1, 101))])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guides_seen']);
    }

    public function test_exam_defaults_keep_to_the_limits_an_exam_is_saved_under(): void
    {
        $this->as($this->user(User::ROLE_STAFF_MASTER_EXAMINER))->putJson('/api/account/preferences', ['exam_defaults' => [
            'total_items' => 0,
            'duration_minutes' => 9999,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'results_visibility_mode' => 'sometimes',
        ]])->assertStatus(422)->assertJsonValidationErrors([
            'exam_defaults.total_items',
            'exam_defaults.duration_minutes',
            'exam_defaults.results_visibility_mode',
        ]);
    }

    public function test_a_student_has_no_exam_defaults(): void
    {
        $student = $this->user(User::ROLE_STUDENT);

        $this->as($student)->putJson('/api/account/preferences', ['exam_defaults' => ['total_items' => 10]])
            ->assertStatus(422)->assertJsonValidationErrors('exam_defaults');

        $this->assertNull($student->fresh()->preferences);
    }

    // ------------------------------------------------- where you're signed in

    public function test_signing_in_records_the_browser_and_the_list_names_it(): void
    {
        $user = $this->user(User::ROLE_STUDENT);

        $token = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36')
            ->postJson('/api/auth/login', ['identifier' => $user->email, 'password' => 'Secret12345!'])
            ->assertOk()
            ->getCookie(\App\Services\AuthTokenCookieService::COOKIE_NAME, false)->getValue();

        $phone = $user->createToken('spa');
        $phone->accessToken->forceFill(['user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'])->save();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/account/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.current', true)
            ->assertJsonPath('sessions.0.device', 'Chrome on Windows')
            ->assertJsonPath('sessions.0.remembered', false)
            ->assertJsonPath('sessions.1.device', 'Safari on iPhone')
            ->assertJsonPath('sessions.1.current', false);
    }

    public function test_a_sign_in_that_has_already_ended_is_not_listed(): void
    {
        $user = $this->user(User::ROLE_STUDENT);

        $idle = $user->createToken(\App\Services\AuthTokenCookieService::TOKEN_SESSION);
        $idle->accessToken->forceFill(['last_used_at' => now()->subHours(9)])->save();

        $expired = $user->createToken('spa');
        $expired->accessToken->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->as($user)->getJson('/api/account/sessions')->assertOk()->assertJsonCount(1, 'sessions');
    }

    public function test_one_other_device_can_be_signed_out_but_not_someone_else_s(): void
    {
        $user = $this->user(User::ROLE_STUDENT);
        $other = $user->createToken('spa');
        $stranger = User::factory()->create()->createToken('spa');
        $mine = $user->createToken('spa');

        $this->withToken($mine->plainTextToken)->deleteJson('/api/account/sessions/'.$stranger->accessToken->id)->assertNotFound();
        $this->deleteJson('/api/account/sessions/'.$mine->accessToken->id)->assertStatus(422);
        $this->deleteJson('/api/account/sessions/'.$other->accessToken->id)->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $stranger->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $mine->accessToken->id]);
    }

    public function test_signing_out_everywhere_else_keeps_this_device(): void
    {
        $user = $this->user(User::ROLE_STAFF_MASTER_EXAMINER);
        $user->createToken('spa');
        $user->createToken('spa');
        $mine = $user->createToken('spa');

        $this->withToken($mine->plainTextToken)->postJson('/api/account/sessions/sign-out-others')
            ->assertOk()
            ->assertJsonPath('signed_out', 2);

        $this->assertSame([$mine->accessToken->id], $user->tokens()->pluck('id')->all());
    }

    public function test_none_of_it_is_open_to_someone_signed_out(): void
    {
        $this->patchJson('/api/account/profile', [])->assertUnauthorized();
        $this->putJson('/api/account/password', [])->assertUnauthorized();
        $this->postJson('/api/account/email', [])->assertUnauthorized();
        $this->putJson('/api/account/preferences', [])->assertUnauthorized();
        $this->getJson('/api/account/sessions')->assertUnauthorized();
        $this->postJson('/api/account/sessions/sign-out-others')->assertUnauthorized();
    }

    private function user(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'email' => 'me@lnu.edu.ph',
            'password' => 'Secret12345!',
            'is_active' => true,
        ], $attributes));
    }

    /** A real token, as the page has: the routes read the current one. */
    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('spa')->plainTextToken);
    }

    private function confirmUrl(User $user, string $email): string
    {
        return URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
            'email' => $email,
        ]);
    }
}
