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
        if (!Schema::hasTable('topics')) {
            Schema::create('topics', function (Blueprint $table) {
                $table->id();
                $table->string('subject');
                $table->string('name');
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['subject', 'name'], 'topics_subject_name_unique');
            });
        }

        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'usage_type')) {
                $table->string('usage_type', 24)
                    ->default('assessment')
                    ->after('question_bank_id');
            }

            if (!Schema::hasColumn('question_bank_questions', 'subject')) {
                $table->string('subject')
                    ->nullable()
                    ->after('usage_type');
            }

            if (!Schema::hasColumn('question_bank_questions', 'topic_id')) {
                $table->foreignId('topic_id')
                    ->nullable()
                    ->after('subject')
                    ->constrained('topics')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('question_bank_questions', 'difficulty')) {
                $table->string('difficulty', 24)
                    ->nullable()
                    ->after('topic_id');
            }

            if (!Schema::hasColumn('question_bank_questions', 'source')) {
                $table->string('source', 24)
                    ->default('faculty')
                    ->after('difficulty');
            }

            if (!Schema::hasColumn('question_bank_questions', 'status')) {
                $table->string('status', 24)
                    ->default('approved')
                    ->after('source');
            }

            if (!Schema::hasColumn('question_bank_questions', 'ai_generated')) {
                $table->boolean('ai_generated')
                    ->default(false)
                    ->after('status');
            }

            if (!Schema::hasColumn('question_bank_questions', 'source_question_id')) {
                $table->foreignId('source_question_id')
                    ->nullable()
                    ->after('ai_generated')
                    ->constrained('question_bank_questions')
                    ->nullOnDelete();
            }
        });

        $subjectsByBankId = DB::table('question_banks')
            ->pluck('subject', 'id');

        DB::table('question_bank_questions')
            ->select('id', 'question_bank_id')
            ->whereNull('subject')
            ->orderBy('id')
            ->get()
            ->each(function ($question) use ($subjectsByBankId): void {
                DB::table('question_bank_questions')
                    ->where('id', $question->id)
                    ->update([
                        'subject' => $subjectsByBankId[(int) $question->question_bank_id] ?? null,
                    ]);
            });

        DB::table('question_bank_questions')
            ->whereNull('difficulty')
            ->update(['difficulty' => 'medium']);

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(['question_bank_id', 'usage_type'], 'qb_questions_bank_usage_idx');
            $table->index(['usage_type', 'status'], 'qb_questions_usage_status_idx');
            $table->index(['subject', 'usage_type', 'status'], 'qb_questions_subject_usage_status_idx');
            $table->index(['topic_id', 'usage_type', 'status'], 'qb_questions_topic_usage_status_idx');
            $table->index(['source_question_id'], 'qb_questions_source_question_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (Schema::hasColumn('question_bank_questions', 'source_question_id')) {
                $table->dropConstrainedForeignId('source_question_id');
            }

            if (Schema::hasColumn('question_bank_questions', 'topic_id')) {
                $table->dropConstrainedForeignId('topic_id');
            }

            $table->dropIndex('qb_questions_bank_usage_idx');
            $table->dropIndex('qb_questions_usage_status_idx');
            $table->dropIndex('qb_questions_subject_usage_status_idx');
            $table->dropIndex('qb_questions_topic_usage_status_idx');
            $table->dropIndex('qb_questions_source_question_idx');

            foreach (['usage_type', 'subject', 'difficulty', 'source', 'status', 'ai_generated'] as $column) {
                if (Schema::hasColumn('question_bank_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('topics');
    }
};
