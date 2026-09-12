<?php

use App\Models\QuestionBankQuestion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Review Bot's near-duplicate screening switched from OpenAI embeddings
 * (text-embedding-3-small) to a local feature-hashed vector (see
 * ReviewBotQuestionScreeningService::embed()). Both are stored in the same
 * packed-float32 blob format at the same dimensionality, but they are NOT
 * the same vector space -- comparing an old OpenAI-space blob against a new
 * local-space vector via cosine similarity would silently produce a
 * meaningless number rather than an error. Nulling out the old values makes
 * every existing review_bot row start clean under the new scheme (screening
 * already treats a null embedding as "not yet screened", not an error).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->whereNotNull('embedding')
            ->update(['embedding' => null]);
    }

    public function down(): void
    {
        // The original OpenAI-space vectors are gone -- nothing to restore.
    }
};
