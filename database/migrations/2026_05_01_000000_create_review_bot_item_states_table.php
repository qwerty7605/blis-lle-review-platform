<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-student, per-question recall state. Drives the adaptive review queue:
 * items a student keeps missing come back, items answered correctly twice in
 * a row drop out of rotation so a modest question pool stays useful instead
 * of re-serving the same items at random.
 *
 * Rows only exist for questions a student has actually seen, so this stays
 * sparse relative to the full question bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('review_bot_item_states')) {
            return;
        }

        Schema::create('review_bot_item_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('question_bank_question_id')
                ->constrained('question_bank_questions')
                ->cascadeOnDelete();
            $table->unsignedInteger('times_seen')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->unsignedInteger('consecutive_correct')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'question_bank_question_id'],
                'review_bot_item_state_unique'
            );

            // The bucket queries always filter by user first, then by how many
            // times in a row the student has answered the item correctly.
            $table->index(
                ['user_id', 'consecutive_correct'],
                'review_bot_item_state_bucket_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_bot_item_states');
    }
};
