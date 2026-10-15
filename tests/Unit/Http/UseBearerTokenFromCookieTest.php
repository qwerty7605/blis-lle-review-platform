<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\UseBearerTokenFromCookie;
use App\Services\AuthTokenCookieService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The sign-in token lives in an HttpOnly cookie; this middleware hands it to
 * Sanctum as a bearer token, unless the request already carries one.
 */
class UseBearerTokenFromCookieTest extends TestCase
{
    private function handle(Request $request): ?string
    {
        (new UseBearerTokenFromCookie())->handle($request, fn () => new Response());

        return $request->headers->get('Authorization');
    }

    public function test_the_cookie_becomes_the_bearer_token(): void
    {
        $request = Request::create('/api/auth/me', 'GET', [], [AuthTokenCookieService::COOKIE_NAME => '12|secret']);

        $this->assertSame('Bearer 12|secret', $this->handle($request));
    }

    public function test_an_existing_bearer_token_is_left_alone(): void
    {
        $request = Request::create('/api/auth/me', 'GET', [], [AuthTokenCookieService::COOKIE_NAME => '12|secret']);
        $request->headers->set('Authorization', 'Bearer 99|other');

        $this->assertSame('Bearer 99|other', $this->handle($request));
    }

    public function test_no_cookie_means_no_header(): void
    {
        $this->assertNull($this->handle(Request::create('/api/auth/me')));
    }
}
