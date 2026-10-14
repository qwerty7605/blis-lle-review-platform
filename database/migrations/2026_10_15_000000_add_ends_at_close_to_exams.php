<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an exam's closing time means for a student already taking it.
 *
 * false (the default, and every exam so far): the closing time stops new
 * starts, and anyone who started before it has their full duration.
 * true: every attempt ends at the closing time, however late it started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->boolean('ends_at_close')->default(false)->after('schedule_end_at');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->dropColumn('ends_at_close');
        });
    }
};
