<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a version of the Estimated Outcome model learnt from battery exams and
 * how well it reads them: its battery rows (one per complete semester of a
 * graduate's 3rd-year-on battery exams), the graduates who gave them and how
 * many of those failed the LLE, the cross-validated figures over the battery
 * rows alone, and whether early estimates from battery exams may be shown
 * from it (`battery_ready`), with the checks that decided it
 * (`battery_checks`).
 *
 * Every version before this has no battery rows: 0, null and not ready, which
 * is what they get.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('let_models', function (Blueprint $table): void {
            $table->unsignedInteger('battery_row_count')->default(0)->after('graduate_count');
            $table->unsignedInteger('battery_graduate_count')->default(0)->after('battery_row_count');
            $table->unsignedInteger('battery_failed_graduate_count')->default(0)->after('battery_graduate_count');
            $table->double('battery_roc_area')->nullable()->after('zero_r_kappa');
            $table->double('battery_failed_recall')->nullable()->after('battery_roc_area');
            $table->double('battery_kappa')->nullable()->after('battery_failed_recall');
            $table->double('battery_zero_r_kappa')->nullable()->after('battery_kappa');
            $table->boolean('battery_ready')->default(false)->after('meets_targets');
            $table->json('battery_checks')->nullable()->after('checks');
        });
    }

    public function down(): void
    {
        Schema::table('let_models', function (Blueprint $table): void {
            $table->dropColumn([
                'battery_row_count',
                'battery_graduate_count',
                'battery_failed_graduate_count',
                'battery_roc_area',
                'battery_failed_recall',
                'battery_kappa',
                'battery_zero_r_kappa',
                'battery_ready',
                'battery_checks',
            ]);
        });
    }
};
