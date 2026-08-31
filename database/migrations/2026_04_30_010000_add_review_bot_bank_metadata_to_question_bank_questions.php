<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns needed to store teacher-generated Review Bot questions
 * directly in question_bank_questions (usage_type = 'review_bot'), alongside
 * the existing 'assessment' rows. No change to `usage_type` itself is
 * needed -- it's already a plain string(24) column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'explanation')) {
                $table->text('explanation')
                    ->nullable()
                    ->after('cognitive_level');
            }

            if (!Schema::hasColumn('question_bank_questions', 'status')) {
                $table->string('status', 24)
                    ->default('published')
                    ->after('explanation');
            }

            if (!Schema::hasColumn('question_bank_questions', 'source_reference')) {
                $table->string('source_reference')
                    ->nullable()
                    ->after('status');
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(
                ['subject', 'topic_id', 'subtopic_id', 'usage_type', 'status'],
                'qb_questions_review_bot_filter_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropIndex('qb_questions_review_bot_filter_idx');
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            foreach (['source_reference', 'status', 'explanation'] as $column) {
                if (Schema::hasColumn('question_bank_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
