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
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'item_style')) {
                $table->string('item_style', 48)
                    ->nullable()
                    ->after('ai_generated');
            }

            if (!Schema::hasColumn('question_bank_questions', 'cognitive_level')) {
                $table->string('cognitive_level', 48)
                    ->nullable()
                    ->after('item_style');
            }

            if (!Schema::hasColumn('question_bank_questions', 'tested_concept')) {
                $table->string('tested_concept')
                    ->nullable()
                    ->after('cognitive_level');
            }

            if (!Schema::hasColumn('question_bank_questions', 'difference_from_source')) {
                $table->text('difference_from_source')
                    ->nullable()
                    ->after('tested_concept');
            }

            if (!Schema::hasColumn('question_bank_questions', 'explanation')) {
                $table->text('explanation')
                    ->nullable()
                    ->after('difference_from_source');
            }
        });

        DB::table('question_bank_questions')
            ->where('source', 'ai')
            ->update(['source' => 'ai_generated']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('question_bank_questions')
            ->where('source', 'ai_generated')
            ->update(['source' => 'ai']);

        Schema::table('question_bank_questions', function (Blueprint $table) {
            foreach ([
                'item_style',
                'cognitive_level',
                'tested_concept',
                'difference_from_source',
                'explanation',
            ] as $column) {
                if (Schema::hasColumn('question_bank_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
