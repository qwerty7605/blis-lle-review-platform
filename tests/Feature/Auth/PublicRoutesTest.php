<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The signed-out routes in routes/api.php that other tests leave out: public
 * registration switched off, a middle name on sign-up, a disabled account
 * signing in, an email-change link that no longer fits, and the public
 * terms and privacy text.
 */
class PublicRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_id' => '2300001',
            'year_level' => 1,
            'email' => 'juan@lnu.edu.ph',
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
            'agreed' => true,
            ...$overrides,
        ];
    }

    public function test_registration_is_refused_while_an_administrator_has_it_switched_off(): void
    {
        SystemSetting::create(['key' => 'allow_public_registration', 'value' => 'false']);

        $this->postJson('/api/auth/register', $this->registration())
            ->assertForbidden()
            ->assertJsonPath('message', 'Public registration is currently disabled.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_middle_name_is_part_of_the_registered_name(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['middle_name' => '  Santos  ']))->assertSuccessful();

        $this->assertSame('Juan Santos Dela Cruz', User::firstOrFail()->name);
    }

    public function test_a_disabled_account_cannot_sign_in_even_with_the_right_password(): void
    {
        $this->makeStudent(['email' => 'off@lnu.edu.ph', 'password' => 'Secret12345!', 'is_active' => false]);

        $this->postJson('/api/auth/login', ['identifier' => 'off@lnu.edu.ph', 'password' => 'Secret12345!'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Account is disabled. Contact an administrator.');
    }

    public function test_an_email_change_link_for_a_missing_account_or_with_no_address_is_turned_away(): void
    {
        $teacher = $this->makeTeacher(['email' => 'old@lnu.edu.ph']);
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $missing = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
            'id' => 999999, 'hash' => sha1('old@lnu.edu.ph'), 'email' => 'new@lnu.edu.ph',
        ]);
        $this->get($missing)->assertRedirect($frontend . '/login?email_changed=invalid');

        $noAddress = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
            'id' => $teacher->id, 'hash' => sha1('old@lnu.edu.ph'),
        ]);
        $this->get($noAddress)->assertRedirect($frontend . '/login?email_changed=invalid');

        $this->assertSame('old@lnu.edu.ph', $teacher->fresh()->email);
    }

    public function test_the_legal_text_comes_from_settings_or_falls_back_to_the_defaults(): void
    {
        $defaults = $this->getJson('/api/settings/public/legal')->assertOk();
        $this->assertNotSame('', $defaults->json('settings.terms_of_use_text'));
        $this->assertNotSame('', $defaults->json('settings.privacy_policy_text'));
        $this->assertNull($defaults->json('last_updated_at'));

        SystemSetting::create(['key' => 'terms_of_use_text', 'value' => 'Use it kindly.']);
        SystemSetting::create(['key' => 'privacy_policy_text', 'value' => '   ']);

        $custom = $this->getJson('/api/settings/public/legal')->assertOk();
        $this->assertSame('Use it kindly.', $custom->json('settings.terms_of_use_text'));
        // A blank saved value still shows the default.
        $this->assertSame($defaults->json('settings.privacy_policy_text'), $custom->json('settings.privacy_policy_text'));
        $this->assertNotNull($custom->json('last_updated_at'));
    }
}
