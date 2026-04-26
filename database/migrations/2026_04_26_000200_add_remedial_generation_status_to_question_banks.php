<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            if (!Schema::hasColumn('question_banks', 'remedial_generation_status')) {
                $table->string('remedial_generation_status', 24)
                    ->default('idle')
                    ->after('total_items');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_message')) {
                $table->text('remedial_generation_message')
                    ->nullable()
                    ->after('remedial_generation_status');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_requested_at')) {
                $table->timestamp('remedial_generation_requested_at')
                    ->nullable()
                    ->after('remedial_generation_message');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_completed_at')) {
                $table->timestamp('remedial_generation_completed_at')
                    ->nullable()
                    ->after('remedial_generation_requested_at');
            }
        });

        DB::table('question_banks')
            ->whereIn('id', function ($query) {
                $query->select('question_bank_id')
                    ->from('question_bank_questions')
                    ->where('usage_type', 'remedial')
                    ->distinct();
            })
            ->update([
                'remedial_generation_status' => 'completed',
                'remedial_generation_message' => 'Stored remedial questions are available for faculty review.',
                'remedial_generation_completed_at' => now(),
            ]);

        Schema::table('question_banks', function (Blueprint $table) {
            $table->index('remedial_generation_status', 'question_banks_remedial_generation_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_banks', function (Blueprint $table) {
            $table->dropIndex('question_banks_remedial_generation_status_idx');

            foreach ([
                'remedial_generation_completed_at',
                'remedial_generation_requested_at',
                'remedial_generation_message',
                'remedial_generation_status',
            ] as $column) {
                if (Schema::hasColumn('question_banks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
