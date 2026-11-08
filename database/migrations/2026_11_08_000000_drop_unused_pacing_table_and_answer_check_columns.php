<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes two leftovers that nothing in the app reads or writes any more.
 *
 * exam_room_pacing_states held the live state of the teacher-paced exam mode
 * (2026_03_01_130000). That mode was taken out on 2026-03-13, so every exam is
 * open navigation, and only the demo seeder still wrote a row here.
 *
 * question_bank_questions.verification_status and verifier_answer_label held
 * the result of the AI answer check on generated Review Bot questions
 * (2026_05_01_000100). The check went away with the switch to local templates
 * on 2026-09-12; since then every row reads 'unchecked' and null. The other
 * two screening columns, validation_flags and embedding, are still used by the
 * local defect and near-duplicate checks and stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('exam_room_pacing_states');

        $drop = array_values(array_filter(
            ['verification_status', 'verifier_answer_label'],
            fn (string $column) => Schema::hasColumn('question_bank_questions', $column),
        ));

        if ($drop === []) {
            return;
        }

        if (Schema::hasIndex('question_bank_questions', 'qb_questions_screening_idx')) {
            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->dropIndex('qb_questions_screening_idx');
            });
        }

        Schema::table('question_bank_questions', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });

        // The same lookup as before, without the dropped column.
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(['usage_type', 'status'], 'qb_questions_screening_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('question_bank_questions', 'verification_status')) {
            if (Schema::hasIndex('question_bank_questions', 'qb_questions_screening_idx')) {
                Schema::table('question_bank_questions', function (Blueprint $table) {
                    $table->dropIndex('qb_questions_screening_idx');
                });
            }

            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->string('verification_status', 24)->default('unchecked')->after('status');
                $table->string('verifier_answer_label', 8)->nullable()->after('verification_status');
            });

            Schema::table('question_bank_questions', function (Blueprint $table) {
                $table->index(['usage_type', 'status', 'verification_status'], 'qb_questions_screening_idx');
            });
        }

        if (!Schema::hasTable('exam_room_pacing_states')) {
            Schema::create('exam_room_pacing_states', function (Blueprint $table) {
                $table->id();
                $table->foreignId('exam_id')
                    ->constrained('exams')
                    ->cascadeOnDelete();
                $table->foreignId('room_id')
                    ->constrained('rooms')
                    ->cascadeOnDelete();
                $table->boolean('is_active')->default(false);
                $table->unsignedInteger('current_item_number')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['exam_id', 'room_id'], 'exam_room_pacing_unique');
                $table->index(['exam_id', 'room_id', 'is_active'], 'exam_room_pacing_active_idx');
            });
        }
    }
};
