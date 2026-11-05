<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two settings that came with the rules for editing an exam students are
 * taking:
 *
 * exams.answers_shown_after: with results Visible, whether a student sees the
 * correct answers right after each attempt (each_attempt, how every exam
 * worked until now, so existing exams keep it) or only once they cannot sit
 * the exam again (last_attempt: no attempt left, its end time passed, or it
 * left their room). Review Bot practice from an exam opens at the same moment.
 *
 * exam_attempts.extra_minutes: time a teacher gave one student on the Live
 * Board, on top of the exam's duration and past its closing time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->string('answers_shown_after', 20)->default('each_attempt')->after('results_visibility_mode');
        });

        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('extra_minutes')->default(0)->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('exam_attempts', function (Blueprint $table): void {
            $table->dropColumn('extra_minutes');
        });

        Schema::table('exams', function (Blueprint $table): void {
            $table->dropColumn('answers_shown_after');
        });
    }
};
