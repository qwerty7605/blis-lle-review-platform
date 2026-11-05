<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many items each of an exam's question sets gives a student. With it,
 * every student's exam has the same mix (15 from one set, 15 from the
 * other), random within each set when the exam shuffles, and no chosen set
 * goes unused. Null on the exams saved before it, which keep dealing from all
 * their sets as one pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_question_bank', function (Blueprint $table): void {
            $table->unsignedSmallInteger('items')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('exam_question_bank', function (Blueprint $table): void {
            $table->dropColumn('items');
        });
    }
};
