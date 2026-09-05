<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop a user deletion from taking their exam history with it.
 *
 * exam_attempts.user_id and review_bot_attempts.user_id were both created with
 * cascadeOnDelete, so `DELETE FROM users WHERE id = ?` silently destroyed every
 * attempt that student ever made. Nothing in the API deletes a user today, so
 * this never fired -- but that history is the feature half of the LLE training
 * set (see LetReadinessFeatureBuilder, which derives all 15 features from
 * exam_attempts alone), and one future "delete user" button or one manual
 * cleanup would be irreversible.
 *
 * Switching to restrictOnDelete makes the database refuse instead. Removing a
 * student who has sat exams now has to be a deliberate act: archive them, or
 * delete the attempts first and mean it.
 *
 * room_user keeps its cascade on purpose -- a membership row carries no history
 * of its own, and orphaning it would leave a broken pivot.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['exam_attempts', 'review_bot_attempts'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['user_id']);
                $blueprint->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['user_id']);
                $blueprint->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }
};
