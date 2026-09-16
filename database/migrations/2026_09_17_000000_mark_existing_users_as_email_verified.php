<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Registration now sends a verification link, and login refuses accounts whose
 * email is still unverified. Every account predating that change carries
 * email_verified_at = NULL -- all 4,305 of them, including both admins -- so
 * shipping the login rule without this backfill would lock the entire
 * institution out at once, leaving nobody able to undo it.
 *
 * These accounts were created by an administrator or registered while no
 * verification step existed, so they are credited as verified at the moment
 * they were created rather than at deploy time.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Deliberately irreversible. Nothing distinguishes a row backfilled
        // here from one verified for real afterwards, so clearing the column
        // wholesale would lock out every account that has since verified.
    }
};
