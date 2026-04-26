<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PracticeAttempt extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';

    public const TARGET_SUBJECT = 'subject';
    public const TARGET_TOPIC = 'topic';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'target_type',
        'subject',
        'topic_id',
        'total_items',
        'answered_count',
        'correct_answers',
        'score_percent',
        'started_at',
        'submitted_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score_percent' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(PracticeAttemptQuestion::class)
            ->orderBy('item_number');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(PracticeAttemptAnswer::class);
    }
}
