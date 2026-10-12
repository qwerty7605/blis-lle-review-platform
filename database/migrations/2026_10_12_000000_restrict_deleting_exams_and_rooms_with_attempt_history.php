<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stop an exam or room deletion from taking students' attempts with it.
 *
 * exam_attempts.exam_id and exam_attempts.room_id were both created with
 * cascadeOnDelete, so deleting an exam or a room silently destroyed every
 * attempt made in it, answers and scores included. Unlike the user_id cascade
 * (see 2026_09_06_020000), this one did fire: both pages have a Delete button,
 * and neither route looked for attempts first.
 *
 * The routes now refuse with a message a teacher can act on. Switching to
 * restrictOnDelete makes the database refuse as well, so a manual cleanup, a
 * later code path, or an attempt started in the moment between the route's
 * check and its delete cannot do what the button no longer does.
 *
 * exam_room and room_user keep their cascades on purpose -- an assignment or a
 * membership row carries no history of its own.
 */
return new class extends Migration
{
    /**
     * @var array<string, string> column => the table it points at
     */
    private array $parents = [
        'exam_id' => 'exams',
        'room_id' => 'rooms',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('exam_attempts')) {
            return;
        }

        foreach ($this->parents as $column => $parent) {
            Schema::table('exam_attempts', function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)
                    ->references('id')
                    ->on($parent)
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('exam_attempts')) {
            return;
        }

        foreach ($this->parents as $column => $parent) {
            Schema::table('exam_attempts', function (Blueprint $blueprint) use ($column, $parent) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)
                    ->references('id')
                    ->on($parent)
                    ->cascadeOnDelete();
            });
        }
    }
};
