<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthTokenCookieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Remember me" on the sign-in page decides what kind of sign-in it is, for
 * every role. Ticked: seven days. Unticked: until the browser is closed (a
 * session cookie) or eight hours pass with the sign-in unused.
 */
class RememberMeSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unticked_sign_in_ends_after_eight_hours_unused(): void
    {
        $token = $this->signIn(remember: false);

        $this->travel(7)->hours();
        $this->assertSignedIn($token);

        // Seven more hours, but counted from that last use: still signed in.
        $this->travel(7)->hours();
        $this->assertSignedIn($token);

        $this->travel(9)->hours();
        $this->assertSignedOut($token);
    }

    public function test_an_unticked_sign_in_ends_when_the_browser_is_closed(): void
    {
        $user = $this->user();

        foreach ([['remember' => false], []] as $choice) {
            $response = $this->postJson('/api/auth/login', ['identifier' => $user->email, 'password' => 'Secret12345!'] + $choice)
                ->assertOk()
                ->assertJsonPath('user.session_idle_minutes', 480);

            // No expiry on the cookie: the browser drops it when it closes.
            $this->assertSame(0, $response->getCookie(AuthTokenCookieService::COOKIE_NAME, false)->getExpiresTime());
        }
    }

    public function test_a_ticked_sign_in_lasts_seven_days_used_or_not(): void
    {
        $user = $this->user();

        $response = $this->postJson('/api/auth/login', ['identifier' => $user->email, 'password' => 'Secret12345!', 'remember' => true])
            ->assertOk()
            ->assertJsonPath('user.session_idle_minutes', null);

        $cookie = $response->getCookie(AuthTokenCookieService::COOKIE_NAME, false);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 5);

        $this->travel(6)->days();
        $this->assertSignedIn($cookie->getValue());

        $this->travel(2)->days();
        $this->assertSignedOut($cookie->getValue());
    }

    public function test_the_page_is_told_its_limit_when_it_reloads(): void
    {
        foreach ([[false, 480], [true, null]] as [$remember, $limit]) {
            $token = $this->signIn($remember);

            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/auth/me')
                ->assertOk()
                ->assertJsonPath('session_idle_minutes', $limit);
        }
    }

    public function test_it_is_the_same_for_staff(): void
    {
        $token = $this->signIn(remember: false, role: User::ROLE_STAFF_MASTER_EXAMINER);

        $this->travel(9)->hours();
        $this->assertSignedOut($token);
    }

    private function user(string $role = User::ROLE_STUDENT): User
    {
        return User::factory()->create([
            'role' => $role,
            'password' => 'Secret12345!',
            'is_active' => true,
        ]);
    }

    private function signIn(bool $remember, string $role = User::ROLE_STUDENT): string
    {
        $response = $this->postJson('/api/auth/login', [
            'identifier' => $this->user($role)->email,
            'password' => 'Secret12345!',
            'remember' => $remember,
        ])->assertOk();

        return $response->getCookie(AuthTokenCookieService::COOKIE_NAME, false)->getValue();
    }

    private function assertSignedIn(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    private function assertSignedOut(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
