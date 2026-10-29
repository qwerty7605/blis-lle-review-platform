<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every exam has a type: a regular mock exam (the default), a battery exam,
 * or one of the two LLE review exams, a pre-test or a mock board. The
 * Estimated Outcome model is trained on, and reads, pre-tests and mock
 * boards only.
 *
 * A pre-test or mock board is six exams, one per board subject. An exam
 * series ("2026 Pre-test", "2026 Mock Board 1") says which six belong
 * together; each of its exams carries the board subject it is
 * (`series_subject`, a subject key of docs/lle_tos.json), and a series holds
 * one exam per subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_series', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            // pre_test | mock_board (App\Models\Exam::SERIES_TYPES)
            $table->string('type', 20)->index();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('exams', function (Blueprint $table): void {
            // regular | battery | pre_test | mock_board (App\Models\Exam::TYPES)
            $table->string('exam_type', 20)->default('regular')->after('subject')->index();
            $table->foreignId('exam_series_id')
                ->nullable()
                ->after('exam_type')
                ->constrained('exam_series')
                ->restrictOnDelete();
            $table->string('series_subject', 20)->nullable()->after('exam_series_id');

            $table->unique(['exam_series_id', 'series_subject'], 'exams_series_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            $table->dropUnique('exams_series_subject_unique');
            $table->dropConstrainedForeignId('exam_series_id');
            $table->dropColumn(['exam_type', 'series_subject']);
        });

        Schema::dropIfExists('exam_series');
    }
};
