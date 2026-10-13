<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokenCookieService
{
    public const COOKIE_NAME = 'auth_token';

    private const DEFAULT_COOKIE_LIFETIME_MINUTES = 60 * 24 * 7;

    /**
     * The two kinds of sign-in, told apart by the token's name. With
     * "Remember me" ticked it lasts seven days (sanctum.expiration). Without,
     * it ends when the browser is closed or after IDLE_MINUTES with nothing
     * done, whichever comes first.
     */
    public const TOKEN_REMEMBERED = 'spa';

    public const TOKEN_SESSION = 'spa-session';

    public const IDLE_MINUTES = 60 * 8;

    public function tokenName(bool $remember): string
    {
        return $remember ? self::TOKEN_REMEMBERED : self::TOKEN_SESSION;
    }

    /**
     * Minutes this sign-in may sit unused, or null when it has no such limit.
     * Sent to the page with the user, which does its own watching: its
     * background refreshes keep the token in use, so an open tab nobody is
     * at never looks idle from here (see frontend/src/utils/idleSignOut.js).
     */
    public function idleMinutes(mixed $token): ?int
    {
        return $token instanceof PersonalAccessToken && $token->name === self::TOKEN_SESSION
            ? self::IDLE_MINUTES
            : null;
    }

    /**
     * Whether a sign-in has been left unused past its limit. This is the
     * check for a tab that was closed: nothing has used the token since.
     */
    public function hasGoneIdle(PersonalAccessToken $token): bool
    {
        $minutes = $this->idleMinutes($token);

        if ($minutes === null) {
            return false;
        }

        $lastUsed = $token->last_used_at ?? $token->created_at;

        return $lastUsed !== null && $lastUsed->lt(now()->subMinutes($minutes));
    }

    public function attachTokenCookie(JsonResponse $response, string $token, bool $remember = true): JsonResponse
    {
        $longest = (int) config('sanctum.expiration', self::DEFAULT_COOKIE_LIFETIME_MINUTES);

        // 0 makes it a session cookie: the browser drops it when it closes.
        $minutes = $remember
            ? ($longest > 0 ? $longest : self::DEFAULT_COOKIE_LIFETIME_MINUTES)
            : 0;

        return $response->withCookie(cookie(
            self::COOKIE_NAME,
            $token,
            $minutes,
            '/',
            null,
            !app()->environment('local'),
            true,
            false,
            'strict',
        ));
    }

    public function clearTokenCookie(JsonResponse $response): JsonResponse
    {
        return $response->withCookie(cookie()->forget(self::COOKIE_NAME, '/', null));
    }
}

