<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks accounts the demo seeders created.
 *
 * Demo students live on a reserved domain, but the demo's admin and teachers
 * sign in with easy-to-remember addresses on the real institutional one, so
 * an email alone can no longer tell demo from real. `demo:purge` removes
 * exactly these accounts and what they own; nothing a real person registers
 * or an admin creates ever sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'is_demo')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('is_active')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'is_demo')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
