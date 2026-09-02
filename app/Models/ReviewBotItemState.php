<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (student, question) the student has actually been served.
 * consecutive_correct drives which bucket the item falls into when the
 * adaptive queue builds the next session.
 */
class ReviewBotItemState extends Model
{
    use HasFactory;

    /** Answered wrong (or skipped) last time -- highest priority to re-serve. */
    public const BUCKET_WEAK = 'weak';

    /** Never served to this student. */
    public const BUCKET_UNSEEN = 'unseen';

    /** Answered correctly once -- worth one more confirmation. */
    public const BUCKET_LEARNING = 'learning';

    /** Answered correctly twice in a row -- drops out of rotation. */
    public const BUCKET_MASTERED = 'mastered';

    /** consecutive_correct at or above this counts as mastered. */
    public const MASTERY_THRESHOLD = 2;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'question_bank_question_id',
        'times_seen',
        'times_correct',
        'consecutive_correct',
        'last_seen_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'times_seen' => 'integer',
        'times_correct' => 'integer',
        'consecutive_correct' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'question_bank_question_id');
    }
}
