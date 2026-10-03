<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SystemSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the platform offline for everyone but administrators while the
 * maintenance_mode setting is on.
 *
 * Without this the setting was a dead switch: an admin could turn it on, the
 * value was stored and read back by the settings screen, and the platform
 * carried on serving every request as though nothing had happened.
 *
 * Admins are exempt, and a short list of auth endpoints stays open for
 * everyone, because the switch has to be reversible from the UI: if signing in
 * were blocked too, turning maintenance on while signed out would lock the
 * last administrator out of the only screen that can turn it off.
 */
class EnforceMaintenanceMode
{
    /**
     * Reachable even during maintenance. /auth/me is here so the SPA can still
     * resolve who is signed in and decide what to show.
     */
    private const ALWAYS_OPEN = [
        'api/auth/login',
        'api/auth/logout',
        'api/auth/me',
        'api/settings/public/*',
    ];

    public function __construct(private readonly SystemSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->maintenanceMode()) {
            return $next($request);
        }

        if ($request->is(...self::ALWAYS_OPEN)) {
            return $next($request);
        }

        // Asked of the sanctum guard directly: this runs as group middleware,
        // before the route's auth:sanctum has resolved anyone, so the default
        // guard would report nobody and lock admins out too.
        if ($request->user('sanctum')?->role === User::ROLE_ADMIN) {
            return $next($request);
        }

        return response()->json([
            'message' => 'The platform is under maintenance. Please try again shortly.',
            'maintenance_mode' => true,
        ], 503);
    }
}
