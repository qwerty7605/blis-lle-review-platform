<?php

namespace App\Http\Middleware;

use App\Services\AuthTokenCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A disabled account is signed out on its next request: its tokens are
 * deleted, the auth cookie is cleared, and the answer is 403 "Account is
 * disabled.", which the SPA turns into the signed-out page with the reason.
 *
 * Runs on every signed-in API route (alias `active`, after auth:sanctum in
 * routes/api.php). Each route used to make this check itself.
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly AuthTokenCookieService $cookies)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_active) {
            $user->tokens()->delete();

            return $this->cookies->clearTokenCookie(
                response()->json(['message' => 'Account is disabled.'], 403)
            );
        }

        return $next($request);
    }
}
