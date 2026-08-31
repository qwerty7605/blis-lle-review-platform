<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewBotAttemptQuestion extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'review_bot_attempt_id',
        'question_bank_question_id',
        'item_number',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ReviewBotAttempt::class, 'review_bot_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'question_bank_question_id');
    }
}
