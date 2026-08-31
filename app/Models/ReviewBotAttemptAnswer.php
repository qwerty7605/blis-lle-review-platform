<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewBotAttemptAnswer extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'review_bot_attempt_id',
        'question_bank_question_id',
        'question_bank_option_id',
        'answer_text',
        'is_correct',
        'answered_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_correct' => 'boolean',
        'answered_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ReviewBotAttempt::class, 'review_bot_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(QuestionBankQuestion::class, 'question_bank_question_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionBankOption::class, 'question_bank_option_id');
    }
}
