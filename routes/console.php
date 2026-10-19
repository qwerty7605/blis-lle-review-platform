<?php

use Illuminate\Support\Facades\Schedule;

// Expired tokens stop working the moment sanctum.expiration passes, but the
// rows stick around until something deletes them. This keeps
// personal_access_tokens from growing without bound as students log in each
// term.
//
// Everything here is run by the `scheduler` service in
// docker-compose.prod.yml (`php artisan schedule:work`).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Sign-ups whose verification link expired unopened; see the command.
Schedule::command('registrations:prune-unverified')->hourly();

// An attempt whose time ran out is submitted even if its student never comes
// back to it, so its score reaches the teacher's reports.
Schedule::command('exams:submit-expired')->everyMinute()->withoutOverlapping();
