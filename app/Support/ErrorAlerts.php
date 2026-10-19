<?php

namespace App\Support;

use App\Mail\ServerErrorAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Emails the developers when something fails with a server error, so a fault
 * is known about before a tester has to report it.
 *
 * Only errors Laravel reports reach this (not validation, sign-in, 404 or
 * rate-limit answers). One email per distinct error per QUIET_MINUTES: a fault
 * hit by a whole class at once is one email, not two hundred. The address is
 * ERROR_ALERT_EMAIL, else the feedback address (FEEDBACK_DEVELOPER_EMAIL);
 * with neither set nothing is sent. The email goes out after the response, so
 * the person who hit the error is not kept waiting for the mail server.
 */
final class ErrorAlerts
{
    public const QUIET_MINUTES = 15;

    public static function report(Throwable $e): void
    {
        try {
            $to = trim((string) (config('services.error_alerts.email') ?: config('services.feedback.developer_email')));

            if ($to === '' || app()->environment('local', 'testing') && !config('services.error_alerts.in_local')) {
                return;
            }

            // The file store, not the default one: the default is the
            // database, and a database fault is exactly when this must work.
            $signature = sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
            if (!Cache::store('file')->add('error-alert:'.$signature, true, now()->addMinutes(self::QUIET_MINUTES))) {
                return;
            }

            $details = self::details($e);

            defer(static function () use ($to, $details): void {
                try {
                    Mail::to($to)->send(new ServerErrorAlert($details));
                } catch (Throwable) {
                    // A failed alert must not become a second error.
                }
            }, always: true); // deferred work is otherwise skipped after a failed response
        } catch (Throwable) {
            // Same: never let alerting break error handling.
        }
    }

    /** @return array<string, mixed> */
    public static function details(Throwable $e): array
    {
        // A console command or queued job has no route, so no request.
        $request = app()->bound('request') && request()->route() !== null ? request() : null;
        $user = $request?->user();
        $base = base_path().DIRECTORY_SEPARATOR;

        return [
            'class' => get_class($e),
            'message' => Str::limit($e->getMessage(), 500),
            'where' => str_replace($base, '', $e->getFile()).':'.$e->getLine(),
            'time' => now()->toIso8601String(),
            'request' => $request ? $request->method().' '.$request->path() : null,
            'user' => $user ? sprintf('#%d %s (%s)', $user->id, $user->email, $user->role) : null,
            'environment' => config('app.env').' '.config('app.url'),
            'quiet_minutes' => self::QUIET_MINUTES,
            'trace' => collect(explode("\n", str_replace($base, '', $e->getTraceAsString())))->take(25)->implode("\n"),
        ];
    }
}
