<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room can stop taking new students while keeping the class it has: set
 * when its teacher turns "Accepting new students" off, null while it is on
 * (every room so far).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->timestamp('joins_closed_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->dropColumn('joins_closed_at');
        });
    }
};
