<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The browser behind each entry (as sign-ins already keep it), and the
 * indexes the Audit page's filters read by: time, who, and what it was done to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->index('created_at');
            $table->index(['actor_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // MySQL dropped the foreign key's own index once the (actor_id,
            // created_at) one could serve it, so it needs one back first.
            $table->index('actor_id');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropIndex(['actor_id', 'created_at']);
            $table->dropIndex(['created_at']);
            $table->dropColumn('user_agent');
        });
    }
};
