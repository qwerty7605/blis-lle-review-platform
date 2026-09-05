<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual Librarian Licensure Examination outcome for a student.
 *
 * LetPredictionService predicts pass/fail from a student's mock history, but
 * its docblock says the tree was trained on a single hand-labelled cohort and
 * should be retrained "as the system accumulates more mock-exam and LET outcome
 * data". Nothing in the system stored that outcome, so every batch that
 * graduated produced feature data with no label attached -- unusable for
 * supervised retraining, and unrecoverable once students leave.
 *
 * This is the label. One row per student per sitting: `exam_year` is part of
 * the key because a candidate who fails may take the exam again, and both
 * attempts are real data.
 *
 * `rating` is the PRC general average where it is known. It is nullable
 * because a pass/fail is often all that gets recorded, and a missing rating
 * should never be confused with a zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lle_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                // Deliberately NOT cascadeOnDelete. A licensure outcome is a
                // permanent academic record and the label half of the training
                // set -- deleting the account must not silently take it.
                ->restrictOnDelete();
            $table->year('exam_year');
            $table->date('taken_at')->nullable();
            $table->enum('result', ['pass', 'fail']);
            $table->decimal('rating', 5, 2)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // One recorded outcome per student per sitting.
            $table->unique(['user_id', 'exam_year']);

            // Retraining reads the whole set by outcome and year.
            $table->index(['result', 'exam_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lle_results');
    }
};
