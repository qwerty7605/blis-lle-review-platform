<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links each generated Review Bot question back to the exam item it was
 * derived from.
 *
 * This is what connects analytics to review: a student who missed exam item
 * #47 can be served questions generated from item #47 specifically, instead of
 * a scattershot of questions from the same topic. It also prevents regenerating
 * variants for a source item that already has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'source_question_id')) {
                $table->foreignId('source_question_id')
                    ->nullable()
                    ->after('usage_type')
                    ->constrained('question_bank_questions')
                    ->nullOnDelete();
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(
                ['source_question_id', 'status', 'cognitive_level'],
                'qb_questions_lineage_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropIndex('qb_questions_lineage_idx');
        });

        if (!Schema::hasColumn('question_bank_questions', 'source_question_id')) {
            return;
        }

        // Same driver split as the 2026_04_29 teardown: MySQL holds the index
        // for the foreign key, SQLite leaves it dangling once the column goes.
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropForeign(['source_question_id']);
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropColumn('source_question_id');
        });
    }
};
