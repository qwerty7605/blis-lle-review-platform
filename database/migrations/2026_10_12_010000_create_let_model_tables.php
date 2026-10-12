<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the LET readiness predictor learn from recorded licensure results.
 *
 * The tree in LetPredictionService was trained once, outside the app, and
 * typed into the code. lle_results has been collecting the real outcomes it
 * was meant to be retrained on, but nothing could act on them: there was
 * nowhere to keep a tree other than the source file.
 *
 * `let_models` is that place. Every retraining leaves a row -- the tree, what
 * it was trained on, and how it scored against the model that was live -- and
 * at most one row is `active`. With none active the built-in tree is used, so
 * an empty table changes nothing.
 *
 * `let_training_records` holds training examples that did not come from this
 * system: the original cohort the built-in tree was trained on, if its file is
 * ever imported, so that old and new records can be learned from together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('let_models', function (Blueprint $table) {
            $table->id();
            // active | retired | rejected | reverted (see App\Models\LetModel)
            $table->string('status', 20)->index();
            $table->json('tree');
            $table->unsignedInteger('training_count');
            $table->unsignedInteger('graduate_count');
            $table->unsignedInteger('imported_count');
            // Which examples the tree has seen, so the next run can tell what
            // is new and judge a model only on records it has never met.
            $table->json('training_keys');
            $table->json('metrics');
            $table->string('trigger', 20);
            $table->foreignId('trained_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('let_training_records', function (Blueprint $table) {
            $table->id();
            $table->json('features');
            $table->string('result', 4);
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('let_training_records');
        Schema::dropIfExists('let_models');
    }
};
