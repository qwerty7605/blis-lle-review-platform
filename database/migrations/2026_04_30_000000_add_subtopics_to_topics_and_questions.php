<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a real Subject -> Topic -> Subtopic hierarchy. `topics` already covers
 * Subject -> Topic; this adds the missing level underneath it, mirrored the
 * same way `topics` itself was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subtopics')) {
            Schema::create('subtopics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('topic_id')
                    ->constrained('topics')
                    ->cascadeOnDelete();
                $table->string('name');
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamps();

                $table->unique(['topic_id', 'name'], 'subtopics_topic_name_unique');
            });
        }

        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (!Schema::hasColumn('question_bank_questions', 'subtopic_id')) {
                $table->foreignId('subtopic_id')
                    ->nullable()
                    ->after('topic_id')
                    ->constrained('subtopics')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            if (Schema::hasColumn('question_bank_questions', 'subtopic_id')) {
                $table->dropConstrainedForeignId('subtopic_id');
            }
        });

        Schema::dropIfExists('subtopics');
    }
};
