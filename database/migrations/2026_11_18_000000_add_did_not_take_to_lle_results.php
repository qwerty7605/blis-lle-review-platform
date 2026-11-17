<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Did not take" as a third licensure result.
 *
 * By Batch lists a whole admission year. Until now a student who did not sit
 * the LLE could only be left blank or marked Failed, and "Mark the Rest as
 * Failed" did the latter: a failure that never happened, taught to the
 * Estimated Outcome model and counted against the school's passing rate. A
 * did-not-take row says the student was accounted for that year without
 * sitting, so it is no outcome: training, the passing rate and every count
 * of examinees leave it out (LleResult::TAKEN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lle_results', function (Blueprint $table) {
            $table->enum('result', ['pass', 'fail', 'did_not_take'])->change();
        });
    }

    public function down(): void
    {
        // Before this, a student who did not sit was simply not recorded.
        DB::table('lle_results')->where('result', 'did_not_take')->delete();

        Schema::table('lle_results', function (Blueprint $table) {
            $table->enum('result', ['pass', 'fail'])->change();
        });
    }
};
