<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives students a lifecycle state and a cohort, which `archived_at` alone
 * could not express.
 *
 * `archived_at` is a single nullable timestamp, so graduated, dropped out,
 * transferred and on-leave all looked identical. That is not just untidy: a
 * dropout never sat the licensure exam, so feeding them to the predictor as a
 * failure teaches it something false (see LetPredictionService and the
 * lle_results migration). Only a graduate with a recorded result is a training
 * example, and until now the data could not tell you which students those were.
 *
 * `admission_year` is the cohort anchor. year_level is a mutable "where are
 * they now" field that someone has to bump for every student every year, and it
 * is lossy -- once bumped you cannot tell what year level a student was when
 * they sat an exam, so "how did the 2024 batch do?" is unanswerable. An
 * immutable admission year answers that without any annual maintenance.
 *
 * The two live alongside `archived_at` rather than replacing it. archived_at is
 * read in a dozen places -- the staff directory, Room::currentStudentMembers(),
 * the analytics queries -- and swapping it out wholesale would be a large
 * refactor with a lot of regression surface for no extra expressiveness.
 * archived_at stays the "is this student in circulation" flag; student_status
 * records why. User::applyStudentStatus() is the one place that writes both, so
 * they cannot drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'student_status')) {
                // A string rather than an enum: more statuses will be wanted
                // (deferred, dismissed) and an enum change is a table rebuild.
                $table->string('student_status', 32)
                    ->nullable()
                    ->after('year_level');
            }

            if (!Schema::hasColumn('users', 'admission_year')) {
                $table->year('admission_year')
                    ->nullable()
                    ->after('student_status');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasIndex('users', 'users_role_student_status_index')) {
                $table->index(['role', 'student_status']);
            }

            if (!Schema::hasIndex('users', 'users_role_admission_year_index')) {
                $table->index(['role', 'admission_year']);
            }
        });

        // Backfill. Deliberately does NOT guess why an archived student was
        // archived: marking them all "graduated" would inject fabricated
        // training examples, which is the exact failure this column exists to
        // prevent. They land in a holding state for a human to classify.
        DB::table('users')
            ->where('role', User::ROLE_STUDENT)
            ->whereNull('archived_at')
            ->update(['student_status' => User::STUDENT_STATUS_ENROLLED]);

        DB::table('users')
            ->where('role', User::ROLE_STUDENT)
            ->whereNotNull('archived_at')
            ->update(['student_status' => User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED]);

        // Estimated, not known: a student who is in year N now most likely
        // enrolled N-1 years before their account was created. Good enough to
        // group existing records into batches, and correct for accounts created
        // at enrolment -- which is all of them on a system this young. Anything
        // recorded from here on is entered rather than inferred.
        // Computed in PHP rather than with YEAR(), which is MySQL-only -- the
        // test suite runs this same migration on SQLite.
        DB::table('users')
            ->where('role', User::ROLE_STUDENT)
            ->whereNotNull('year_level')
            ->whereNull('admission_year')
            ->select('id', 'year_level', 'created_at')
            ->orderBy('id')
            ->chunkById(500, function ($students): void {
                foreach ($students as $student) {
                    $createdYear = (int) date('Y', strtotime((string) $student->created_at));

                    DB::table('users')
                        ->where('id', $student->id)
                        ->update(['admission_year' => $createdYear - ((int) $student->year_level - 1)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_student_status_index');
            $table->dropIndex('users_role_admission_year_index');
            $table->dropColumn(['student_status', 'admission_year']);
        });
    }
};
