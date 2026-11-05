<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAttempt extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'exam_id',
        'room_id',
        'user_id',
        'status',
        'total_items',
        'duration_minutes',
        'answered_count',
        'correct_answers',
        'score_percent',
        'started_at',
        'expires_at',
        'extra_minutes',
        'submitted_at',
    ];

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score_percent' => 'decimal:2',
        'extra_minutes' => 'integer',
    ];

    /**
     * The exam tied to this attempt.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * The room where this attempt happened.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Student who took this attempt.
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Questions selected for this attempt.
     */
    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class)
            ->orderBy('item_number');
    }

    /**
     * Answers submitted by the student for this attempt.
     */
    public function answers(): HasMany
    {
        return $this->hasMany(ExamAttemptAnswer::class);
    }

    /**
     * Each exam's latest submitted sitting for one student, oldest first.
     *
     * An exam sat twice counts by its latest sitting, as it does everywhere
     * else; ordered by when that sitting was submitted, which is the order a
     * student's exams are read in (mastery, the Estimated Outcome series).
     * $until leaves out anything submitted after it.
     *
     * @return Collection<int, ExamAttempt>
     */
    public static function latestSubmittedPerExam(int $userId, ?CarbonInterface $until = null): Collection
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('status', self::STATUS_SUBMITTED)
            ->whereNotNull('submitted_at')
            ->when($until !== null, fn ($query) => $query->where('submitted_at', '<=', $until))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get(['id', 'exam_id', 'room_id', 'user_id', 'score_percent', 'total_items', 'submitted_at'])
            // Newest first, so the first sitting met for an exam is its latest.
            ->unique('exam_id')
            ->sortBy(fn (ExamAttempt $attempt): string => $attempt->submitted_at->format('Y-m-d H:i:s.u') . sprintf('%012d', $attempt->id))
            ->values();
    }
}
