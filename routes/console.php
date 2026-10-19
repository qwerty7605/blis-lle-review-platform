<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired tokens stop working the moment sanctum.expiration passes, but the
// rows stick around until something deletes them. This keeps
// personal_access_tokens from growing without bound as students log in each
// term. NOTE: nothing runs this yet -- it needs a cron entry on the server
// invoking `php artisan schedule:run` every minute.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Sign-ups whose verification link expired unopened; see the command.
Schedule::command('registrations:prune-unverified')->hourly();

// An attempt whose time ran out is submitted even if its student never comes
// back to it, so its score reaches the teacher's reports.
Schedule::command('exams:submit-expired')->everyMinute()->withoutOverlapping();
