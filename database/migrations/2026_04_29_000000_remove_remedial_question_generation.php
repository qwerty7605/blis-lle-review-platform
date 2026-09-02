<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tears down the AI remedial-question-generation feature (and the student
 * "Practice Mode" it solely fed) end to end. Leaves the item-mapping
 * feature's shared columns (topic_id, subject, difficulty, cognitive_level,
 * tag_status, tag_source) and the topics table untouched — those are still
 * in active use.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('practice_attempt_answers');
        Schema::dropIfExists('practice_attempt_questions');
        Schema::dropIfExists('practice_attempts');

        Schema::table('question_banks', function (Blueprint $table) {
            if (Schema::hasColumn('question_banks', 'remedial_generation_status')) {
                $table->dropIndex('question_banks_remedial_generation_status_idx');
            }

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

        // Each concern below is its own Schema::table() call (rather than one
        // shared closure) so the statements run in this exact order. That
        // matters on MySQL: topic_id carries a foreign key to `topics`, so a
        // replacement single-column index has to exist and commit *before*
        // the old composite index (which also covered the doomed `status`
        // column) can be dropped, or the FK is left without index coverage.
        //
        // Every step is guarded so the migration can be re-run after a partial
        // failure: MySQL DDL is not transactional, so a mid-migration error
        // leaves the earlier statements committed but the migration unrecorded.
        if (!$this->hasIndex('question_bank_questions', 'qb_questions_topic_id_idx')) {
            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->index('topic_id', 'qb_questions_topic_id_idx');
            });
        }

        foreach ([
            'qb_questions_usage_status_idx',
            'qb_questions_subject_usage_status_idx',
            'qb_questions_topic_usage_status_idx',
        ] as $index) {
            if (!$this->hasIndex('question_bank_questions', $index)) {
                continue;
            }

            Schema::table('question_bank_questions', function (Blueprint $table) use ($index) {
                $table->dropIndex($index);
            });
        }

        // qb_questions_source_question_idx is the only index on
        // source_question_id, and the two drivers disagree about it: MySQL uses
        // it to satisfy the foreign key and refuses to drop it while the
        // constraint stands, while SQLite keeps it alive after the column goes
        // and then errors on the dangling reference. Dropping the constraint,
        // then the index, then the column satisfies both.
        if (Schema::hasColumn('question_bank_questions', 'source_question_id')) {
            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->dropForeign(['source_question_id']);
            });

            if ($this->hasIndex('question_bank_questions', 'qb_questions_source_question_idx')) {
                Schema::table('question_bank_questions', function (Blueprint $table) {
                    $table->dropIndex('qb_questions_source_question_idx');
                });
            }

            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->dropColumn('source_question_id');
            });
        }

        $droppableColumns = array_values(array_filter([
            'status',
            'source',
            'ai_generated',
            'item_style',
            'tested_concept',
            'difference_from_source',
            'explanation',
        ], fn (string $column) => Schema::hasColumn('question_bank_questions', $column)));

        if ($droppableColumns !== []) {
            Schema::table('question_bank_questions', function (Blueprint $table) use ($droppableColumns) {
                $table->dropColumn($droppableColumns);
            });
        }
    }

    /**
     * Driver-agnostic on purpose: the app runs on MySQL but the test suite runs
     * on in-memory SQLite, so this cannot reach for information_schema.
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'source')) {
                $table->string('source', 24)->default('faculty')->after('difficulty');
            }

            if (!Schema::hasColumn('question_bank_questions', 'status')) {
                $table->string('status', 24)->default('approved')->after('source');
            }

            if (!Schema::hasColumn('question_bank_questions', 'ai_generated')) {
                $table->boolean('ai_generated')->default(false)->after('status');
            }

            if (!Schema::hasColumn('question_bank_questions', 'item_style')) {
                $table->string('item_style', 48)->nullable()->after('ai_generated');
            }

            if (!Schema::hasColumn('question_bank_questions', 'tested_concept')) {
                $table->string('tested_concept')->nullable()->after('cognitive_level');
            }

            if (!Schema::hasColumn('question_bank_questions', 'difference_from_source')) {
                $table->text('difference_from_source')->nullable()->after('tested_concept');
            }

            if (!Schema::hasColumn('question_bank_questions', 'explanation')) {
                $table->text('explanation')->nullable()->after('difference_from_source');
            }

            if (!Schema::hasColumn('question_bank_questions', 'source_question_id')) {
                $table->foreignId('source_question_id')
                    ->nullable()
                    ->after('ai_generated')
                    ->constrained('question_bank_questions')
                    ->nullOnDelete();
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(['source_question_id'], 'qb_questions_source_question_idx');
            $table->index(['usage_type', 'status'], 'qb_questions_usage_status_idx');
            $table->index(['subject', 'usage_type', 'status'], 'qb_questions_subject_usage_status_idx');
            $table->index(['topic_id', 'usage_type', 'status'], 'qb_questions_topic_usage_status_idx');
            $table->dropIndex('qb_questions_topic_id_idx');
        });

        Schema::table('question_banks', function (Blueprint $table) {
            if (!Schema::hasColumn('question_banks', 'remedial_generation_status')) {
                $table->string('remedial_generation_status', 24)->default('idle')->after('total_items');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_message')) {
                $table->text('remedial_generation_message')->nullable()->after('remedial_generation_status');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_requested_at')) {
                $table->timestamp('remedial_generation_requested_at')->nullable()->after('remedial_generation_message');
            }

            if (!Schema::hasColumn('question_banks', 'remedial_generation_completed_at')) {
                $table->timestamp('remedial_generation_completed_at')->nullable()->after('remedial_generation_requested_at');
            }

            $table->index('remedial_generation_status', 'question_banks_remedial_generation_status_idx');
        });

        if (!Schema::hasTable('practice_attempts')) {
            Schema::create('practice_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 24)->default('in_progress');
                $table->string('target_type', 24)->default('subject');
                $table->string('subject')->nullable();
                $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('answered_count')->default(0);
                $table->unsignedInteger('correct_answers')->default(0);
                $table->decimal('score_percent', 5, 2)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('practice_attempt_questions')) {
            Schema::create('practice_attempt_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('practice_attempt_id')->constrained('practice_attempts')->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')->constrained('question_bank_questions')->cascadeOnDelete();
                $table->unsignedInteger('item_number');
                $table->timestamps();

                $table->unique(['practice_attempt_id', 'question_bank_question_id'], 'practice_question_unique');
            });
        }

        if (!Schema::hasTable('practice_attempt_answers')) {
            Schema::create('practice_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('practice_attempt_id')->constrained('practice_attempts')->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')->constrained('question_bank_questions')->cascadeOnDelete();
                $table->foreignId('question_bank_option_id')->nullable()->constrained('question_bank_options')->nullOnDelete();
                $table->text('answer_text')->nullable();
                $table->boolean('is_correct')->default(false);
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->unique(['practice_attempt_id', 'question_bank_question_id'], 'practice_answer_unique');
            });
        }
    }
};
