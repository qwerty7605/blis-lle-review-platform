<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated screening metadata for AI-generated Review Bot questions.
 *
 * verification_status records whether an independent model, shown the question
 * without its answer key, picked the same option the generator claimed was
 * correct -- a disagreement is the strongest cheap signal of a miskeyed item.
 * validation_flags holds mechanical defects found without any API call.
 * embedding stores a packed float32 vector used for near-duplicate detection.
 *
 * None of this replaces teacher approval; it sorts the risky items to the top
 * of the review queue so bulk approval is not blind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'verification_status')) {
                $table->string('verification_status', 24)
                    ->default('unchecked')
                    ->after('status');
            }

            if (!Schema::hasColumn('question_bank_questions', 'verifier_answer_label')) {
                $table->string('verifier_answer_label', 8)
                    ->nullable()
                    ->after('verification_status');
            }

            if (!Schema::hasColumn('question_bank_questions', 'validation_flags')) {
                $table->json('validation_flags')
                    ->nullable()
                    ->after('verifier_answer_label');
            }

            if (!Schema::hasColumn('question_bank_questions', 'embedding')) {
                // float32 per dimension; 256 dimensions => 1 KB per question.
                $table->binary('embedding')
                    ->nullable()
                    ->after('validation_flags');
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(
                ['usage_type', 'status', 'verification_status'],
                'qb_questions_screening_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropIndex('qb_questions_screening_idx');
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            foreach (['embedding', 'validation_flags', 'verifier_answer_label', 'verification_status'] as $column) {
                if (Schema::hasColumn('question_bank_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
