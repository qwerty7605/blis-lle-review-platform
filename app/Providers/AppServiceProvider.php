<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters used by the API.
     *
     * The login limiter is deliberately two-tier. Accounts here are reachable
     * by student_id as well as email (see the /auth/login route), and student
     * IDs run in predictable yearly sequences -- so an attacker never has to
     * guess *who* exists, only what their password is. A single per-account
     * limit would still let someone spray one password across a whole cohort
     * from one machine, so the second tier caps how many distinct accounts a
     * single IP may attempt per minute.
     *
     * The wider 'api' limiter keys on the authenticated user first and only
     * falls back to the IP for guests. That matters on a campus: a computer
     * lab behind one NAT address would otherwise share a single bucket, and
     * one busy student could lock out the room.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $identifier = Str::lower(trim((string) $request->input('identifier')));

            return [
                // One account, one origin: slow down password guessing.
                Limit::perMinute(5)->by($identifier.'|'.$request->ip()),
                // One origin, many accounts: slow down password spraying.
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Public self-registration is on by default (see the
        // allow_public_registration setting), creates an active student
        // account and hands back a token -- so an unmetered endpoint here is
        // an open invitation to mass-create accounts. These numbers are set
        // to survive a lab full of students onboarding together while still
        // making scripted signup impractical; tighten them if registration is
        // ever opened to the public internet rather than campus.
        RateLimiter::for('register', function (Request $request): array {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perHour(40)->by($request->ip()),
            ];
        });

        RateLimiter::for('api', function (Request $request): Limit {
            // The default guard is 'web' (session), which never resolves for
            // token traffic -- ask the sanctum guard explicitly instead.
            return Limit::perMinute(120)->by(
                $request->user('sanctum')?->id ?: $request->ip()
            );
        });
    }
}
