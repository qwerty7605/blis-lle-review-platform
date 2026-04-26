<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('practice_attempts')) {
            Schema::create('practice_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('status', 24)->default('in_progress');
                $table->string('target_type', 24)->default('subject');
                $table->string('subject')->nullable();
                $table->foreignId('topic_id')
                    ->nullable()
                    ->constrained('topics')
                    ->nullOnDelete();
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
                $table->foreignId('practice_attempt_id')
                    ->constrained('practice_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')
                    ->constrained('question_bank_questions')
                    ->cascadeOnDelete();
                $table->unsignedInteger('item_number');
                $table->timestamps();

                $table->unique(['practice_attempt_id', 'question_bank_question_id'], 'practice_question_unique');
            });
        }

        if (!Schema::hasTable('practice_attempt_answers')) {
            Schema::create('practice_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('practice_attempt_id')
                    ->constrained('practice_attempts')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')
                    ->constrained('question_bank_questions')
                    ->cascadeOnDelete();
                $table->foreignId('question_bank_option_id')
                    ->nullable()
                    ->constrained('question_bank_options')
                    ->nullOnDelete();
                $table->text('answer_text')->nullable();
                $table->boolean('is_correct')->default(false);
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->unique(['practice_attempt_id', 'question_bank_question_id'], 'practice_answer_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_attempt_answers');
        Schema::dropIfExists('practice_attempt_questions');
        Schema::dropIfExists('practice_attempts');
    }
};
