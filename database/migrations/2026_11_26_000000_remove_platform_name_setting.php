<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Platform Name" was a System Settings field that nothing read: the name the
 * app shows is fixed in the code (frontend/src/brand.js, config('app.name')).
 * The field is gone, so its saved value goes too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('key', 'platform_name')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the value was never used.
    }
};
