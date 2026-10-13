<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For the Settings page. A person's own choices (a teacher's defaults for a
 * new exam) are kept with their account, and each sign-in records
 * the browser it was made from so "where you're signed in" can name it.
 * All three columns are optional: existing accounts and sign-ins carry on
 * without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['user_agent', 'ip_address']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
