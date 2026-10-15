<?php

namespace Tests\Feature\Auth;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Signed-in account and settings routes left out elsewhere: reading the
 * system settings, signing out, asking to change to the address one already
 * has, and a disabled account on each of them.
 */
class AccountAndSettingsRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_staff_read_the_system_settings_and_only_an_administrator_may_edit_them(): void
    {
        SystemSetting::create(['key' => 'allow_public_registration', 'value' => '0']);
        SystemSetting::create(['key' => 'platform_name', 'value' => 'BLIS']);

        Sanctum::actingAs($this->makeTeacher());
        $this->getJson('/api/settings/system')
            ->assertOk()
            ->assertJsonPath('settings.allow_public_registration', false)
            ->assertJsonPath('settings.platform_name', 'BLIS')
            ->assertJsonPath('can_edit', false);
        $this->putJson('/api/settings/system', ['platform_name' => 'X'])->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson('/api/settings/system')->assertOk()->assertJsonPath('can_edit', true);

        Sanctum::actingAs($this->makeStudent());
        $this->getJson('/api/settings/system')->assertForbidden();
    }

    public function test_signing_out_ends_the_session_and_clears_the_cookie(): void
    {
        $user = $this->makeStudent();
        $token = $user->createToken('spa')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out');

        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull($response->getCookie('auth_token', false));
    }

    public function test_asking_to_change_to_the_address_one_already_has_says_so(): void
    {
        $teacher = $this->makeTeacher(['email' => 'reyes@lnu.edu.ph', 'password' => 'Secret12345!']);
        Sanctum::actingAs($teacher);

        $this->postJson('/api/account/email', ['email' => 'reyes@lnu.edu.ph', 'current_password' => 'Secret12345!'])
            ->assertJsonValidationErrors(['email' => 'That is already your email address.']);
    }

    public function test_a_disabled_account_is_signed_out_of_its_account_and_settings_routes(): void
    {
        $user = $this->makeTeacher(['is_active' => false]);
        Sanctum::actingAs($user);

        foreach ([
            ['GET', '/api/auth/me'],
            ['POST', '/api/auth/logout'],
            ['PATCH', '/api/account/profile'],
            ['PUT', '/api/account/password'],
            ['POST', '/api/account/email'],
            ['PUT', '/api/account/preferences'],
            ['GET', '/api/account/sessions'],
            ['DELETE', '/api/account/sessions/1'],
            ['POST', '/api/account/sessions/sign-out-others'],
            ['GET', '/api/settings/system'],
            ['PUT', '/api/settings/system'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
