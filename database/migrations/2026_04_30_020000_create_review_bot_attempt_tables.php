<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted Review Bot sessions, mirroring the exam_attempts /
 * exam_attempt_questions / exam_attempt_answers pattern so session history
 * (score, correctness, improvement over time) can be tracked the same
 * well-understood way real exam attempts already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('review_bot_attempts')) {
            Schema::create('review_bot_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('subject');
                $table->foreignId('topic_id')->nullable()->constrained('topics')->nullOnDelete();
                $table->foreignId('subtopic_id')->nullable()->constrained('subtopics')->nullOnDelete();
                $table->string('difficulty', 24)->nullable();
                $table->string('source', 24)->default('manual');
                $table->string('status', 24)->default('in_progress');
                $table->unsignedInteger('total_items')->default(0);
                $table->unsignedInteger('answered_count')->default(0);
                $table->unsignedInteger('correct_answers')->default(0);
                $table->decimal('score_percent', 5, 2)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'subject', 'topic_id', 'subtopic_id'], 'review_bot_attempts_focus_idx');
                $table->index(['user_id', 'status'], 'review_bot_attempts_user_status_idx');
            });
        }

        if (!Schema::hasTable('review_bot_attempt_questions')) {
            Schema::create('review_bot_attempt_questions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_bot_attempt_id')->constrained('review_bot_attempts')->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')->constrained('question_bank_questions')->cascadeOnDelete();
                $table->unsignedInteger('item_number');
                $table->timestamps();

                $table->unique(['review_bot_attempt_id', 'question_bank_question_id'], 'review_bot_question_unique');
            });
        }

        if (!Schema::hasTable('review_bot_attempt_answers')) {
            Schema::create('review_bot_attempt_answers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('review_bot_attempt_id')->constrained('review_bot_attempts')->cascadeOnDelete();
                $table->foreignId('question_bank_question_id')->constrained('question_bank_questions')->cascadeOnDelete();
                $table->foreignId('question_bank_option_id')->nullable()->constrained('question_bank_options')->nullOnDelete();
                $table->text('answer_text')->nullable();
                $table->boolean('is_correct')->default(false);
                $table->timestamp('answered_at')->nullable();
                $table->timestamps();

                $table->unique(['review_bot_attempt_id', 'question_bank_question_id'], 'review_bot_answer_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_bot_attempt_answers');
        Schema::dropIfExists('review_bot_attempt_questions');
        Schema::dropIfExists('review_bot_attempts');
    }
};
