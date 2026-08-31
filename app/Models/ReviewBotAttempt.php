<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewBotAttempt extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
    ];

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTO_WEAK_AREA = 'auto_weak_area';

    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_AUTO_WEAK_AREA,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'subject',
        'topic_id',
        'subtopic_id',
        'difficulty',
        'source',
        'status',
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

    public function subtopic(): BelongsTo
    {
        return $this->belongsTo(Subtopic::class);
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ReviewBotAttemptQuestion::class)->orderBy('item_number');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ReviewBotAttemptAnswer::class);
    }
}
