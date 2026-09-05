<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the staff student directory.
 *
 * `users` carried no index on role, archived_at or year_level at all -- they
 * were added in 2026_04_03_100000 without one -- so every directory listing
 * filtered and sorted with a full scan plus a filesort. The two composites
 * below are ordered filter-then-sort so MySQL can satisfy the WHERE and the
 * ORDER BY from one index.
 *
 * The exam_attempts index serves the latest-attempt-per-session subquery, which
 * groups by (user_id, exam_id, room_id). The table already had a composite in
 * (exam_id, room_id, user_id) order, which cannot drive that grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The default listing: role + not archived, ordered by name.
            if (!Schema::hasIndex('users', 'users_role_archived_at_name_index')) {
                $table->index(['role', 'archived_at', 'name']);
            }

            // The same listing narrowed to one year level.
            if (!Schema::hasIndex('users', 'users_role_archived_at_year_level_name_index')) {
                $table->index(['role', 'archived_at', 'year_level', 'name']);
            }
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            if (!Schema::hasIndex('exam_attempts', 'exam_attempts_user_id_exam_id_room_id_index')) {
                $table->index(['user_id', 'exam_id', 'room_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_archived_at_name_index');
            $table->dropIndex('users_role_archived_at_year_level_name_index');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('exam_attempts_user_id_exam_id_room_id_index');
        });
    }
};
