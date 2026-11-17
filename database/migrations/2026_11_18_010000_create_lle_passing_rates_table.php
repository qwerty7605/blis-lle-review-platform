<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licensure passing rates typed in by hand, for the Licensure page's trend
 * of LNU against the whole country (App\Services\LlePassingRateTrend).
 *
 * One row per scope per exam year:
 *  - `national`: the PRC's figures for everyone who sat that year's LLE,
 *    entered by an administrator.
 *  - `school`: LNU's own figures for a year before BLIS kept its results,
 *    entered by a teacher or an administrator. A year from BLIS's own records
 *    needs none: its rate is counted from lle_results.
 *
 * `passers` and `takers` are what the PRC publishes ("527 of 1,169 passed");
 * when both are known, `passing_rate` is worked out from them, so the three
 * can never disagree. Either can be missing when only a percentage is known,
 * which is why the rate is a column of its own.
 *
 * `exam_year` is a small integer, not MySQL's YEAR type, which holds nothing
 * before 1901.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lle_passing_rates', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);
            $table->unsignedSmallInteger('exam_year');
            $table->unsignedInteger('passers')->nullable();
            $table->unsignedInteger('takers')->nullable();
            $table->decimal('passing_rate', 5, 2);
            $table->string('source', 255)->nullable();
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope', 'exam_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lle_passing_rates');
    }
};
