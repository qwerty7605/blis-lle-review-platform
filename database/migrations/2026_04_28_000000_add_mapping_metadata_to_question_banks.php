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
        Schema::table('question_banks', function (Blueprint $table) {
            if (!Schema::hasColumn('question_banks', 'mapping_status')) {
                $table->string('mapping_status', 24)
                    ->default('idle')
                    ->after('remedial_generation_completed_at');
            }

            if (!Schema::hasColumn('question_banks', 'mapping_mode')) {
                $table->string('mapping_mode', 16)
                    ->nullable()
                    ->after('mapping_status');
            }

            if (!Schema::hasColumn('question_banks', 'mapping_message')) {
                $table->text('mapping_message')
                    ->nullable()
                    ->after('mapping_mode');
            }

            if (!Schema::hasColumn('question_banks', 'mapping_requested_at')) {
                $table->timestamp('mapping_requested_at')
                    ->nullable()
                    ->after('mapping_message');
            }

            if (!Schema::hasColumn('question_banks', 'mapping_completed_at')) {
                $table->timestamp('mapping_completed_at')
                    ->nullable()
                    ->after('mapping_requested_at');
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'tag_status')) {
                $table->string('tag_status', 24)
                    ->nullable()
                    ->after('cognitive_level');
            }

            if (!Schema::hasColumn('question_bank_questions', 'tag_source')) {
                $table->string('tag_source', 16)
                    ->nullable()
                    ->after('tag_status');
            }
        });

        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->index(['question_bank_id', 'tag_status'], 'qb_questions_bank_tag_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->dropIndex('qb_questions_bank_tag_status_idx');

            foreach (['tag_status', 'tag_source'] as $column) {
                if (Schema::hasColumn('question_bank_questions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('question_banks', function (Blueprint $table) {
            foreach ([
                'mapping_completed_at',
                'mapping_requested_at',
                'mapping_message',
                'mapping_mode',
                'mapping_status',
            ] as $column) {
                if (Schema::hasColumn('question_banks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
