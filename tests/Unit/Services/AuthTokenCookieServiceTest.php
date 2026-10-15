<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\AuthTokenCookieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

/**
 * AuthTokenCookieService: "Remember me" ticked gives a 7-day token and
 * cookie; unticked gives a session cookie and a token refused after 8 hours
 * unused.
 */
class AuthTokenCookieServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthTokenCookieService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AuthTokenCookieService();
    }

    public function test_the_token_name_records_whether_remember_me_was_ticked(): void
    {
        $this->assertSame('spa', $this->service->tokenName(true));
        $this->assertSame('spa-session', $this->service->tokenName(false));
    }

    public function test_only_a_session_token_has_an_idle_limit(): void
    {
        $user = User::factory()->create();
        $session = $user->createToken('spa-session')->accessToken;
        $remembered = $user->createToken('spa')->accessToken;

        $this->assertSame(480, $this->service->idleMinutes($session));
        $this->assertNull($this->service->idleMinutes($remembered));
        $this->assertNull($this->service->idleMinutes(null));
    }

    public function test_a_session_token_goes_idle_after_eight_hours_unused(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa-session')->accessToken;

        $token->forceFill(['last_used_at' => now()->subHours(7)])->save();
        $this->assertFalse($this->service->hasGoneIdle($token));

        $token->forceFill(['last_used_at' => now()->subHours(9)])->save();
        $this->assertTrue($this->service->hasGoneIdle($token));

        $remembered = $user->createToken('spa')->accessToken;
        $remembered->forceFill(['last_used_at' => now()->subDays(3)])->save();
        $this->assertFalse($this->service->hasGoneIdle($remembered));
    }

    public function test_a_never_used_session_token_counts_from_when_it_was_made(): void
    {
        $token = User::factory()->create()->createToken('spa-session')->accessToken;
        $token->forceFill(['last_used_at' => null, 'created_at' => now()->subHours(10)])->save();

        $this->assertTrue($this->service->hasGoneIdle($token));
    }

    public function test_the_cookie_lasts_seven_days_when_remembered_and_until_the_browser_closes_otherwise(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 7]);

        $remembered = $this->service->attachTokenCookie(new JsonResponse(), '1|abc', true)->headers->getCookies()[0];
        $session = $this->service->attachTokenCookie(new JsonResponse(), '1|abc', false)->headers->getCookies()[0];

        $this->assertSame('auth_token', $remembered->getName());
        $this->assertTrue($remembered->isHttpOnly());
        $this->assertSame('strict', $remembered->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $remembered->getExpiresTime(), 5);
        $this->assertSame(0, $session->getExpiresTime());
    }

    public function test_with_no_configured_expiry_the_cookie_still_lasts_seven_days(): void
    {
        config(['sanctum.expiration' => null]);

        $cookie = $this->service->attachTokenCookie(new JsonResponse(), '1|abc')->headers->getCookies()[0];

        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $cookie->getExpiresTime(), 5);
    }

    public function test_clearing_the_cookie_expires_it(): void
    {
        $cookie = $this->service->clearTokenCookie(new JsonResponse())->headers->getCookies()[0];

        $this->assertSame('auth_token', $cookie->getName());
        $this->assertLessThan(time(), $cookie->getExpiresTime());
    }
}
