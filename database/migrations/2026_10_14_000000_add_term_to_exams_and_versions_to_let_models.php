<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the board exam prediction now needs.
 *
 * Exams are filed under a school year and semester. The prediction reads one
 * semester's six subject scores, and until now nothing said which semester an
 * exam belonged to. Exams already here are filed by their date: August to
 * December is the first semester, January to July the second.
 *
 * Each trained model is a numbered version, kept with the figures it was
 * judged on, so the model's record over time can be shown and any earlier
 * version gone back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table): void {
            // The year the school year starts in: 2026 for AY 2026-2027.
            $table->unsignedSmallInteger('school_year')->nullable();
            $table->unsignedTinyInteger('semester')->nullable();
            $table->index(['school_year', 'semester']);
        });

        DB::table('exams')
            ->select(['id', 'schedule_start_at', 'scheduled_at', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($exams): void {
                foreach ($exams as $exam) {
                    $date = Carbon::parse($exam->schedule_start_at ?? $exam->scheduled_at ?? $exam->created_at ?? now());

                    DB::table('exams')->where('id', $exam->id)->update($date->month >= 8
                        ? ['school_year' => $date->year, 'semester' => 1]
                        : ['school_year' => $date->year - 1, 'semester' => 2]);
                }
            });

        Schema::table('let_models', function (Blueprint $table): void {
            // The built-in model is version 1; these count on from 2.
            $table->unsignedInteger('version')->nullable()->index();
            $table->unsignedInteger('failed_count')->nullable();
            $table->decimal('accuracy', 6, 2)->nullable();
            $table->decimal('kappa', 7, 4)->nullable();
            $table->decimal('failed_recall', 6, 2)->nullable();
            $table->decimal('baseline', 6, 2)->nullable();
            $table->string('file_path')->nullable();
        });

        // Models trained under the old fifteen-feature rules cannot be read
        // by the new model's seven. They are kept for the record, numbered,
        // and taken out of use.
        $version = 1;
        foreach (DB::table('let_models')->orderBy('id')->pluck('id') as $id) {
            DB::table('let_models')->where('id', $id)->update(['version' => ++$version]);
        }

        DB::table('let_models')->where('status', 'active')->update(['status' => 'retired']);
    }

    public function down(): void
    {
        Schema::table('let_models', function (Blueprint $table): void {
            $table->dropIndex(['version']);
            $table->dropColumn(['version', 'failed_count', 'accuracy', 'kappa', 'failed_recall', 'baseline', 'file_path']);
        });

        Schema::table('exams', function (Blueprint $table): void {
            $table->dropIndex(['school_year', 'semester']);
            $table->dropColumn(['school_year', 'semester']);
        });
    }
};
