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
