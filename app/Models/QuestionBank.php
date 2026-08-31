<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBank extends Model
{
    use HasFactory;

    public const MAPPING_STATUS_IDLE = 'idle';
    public const MAPPING_STATUS_QUEUED = 'queued';
    public const MAPPING_STATUS_PROCESSING = 'processing';
    public const MAPPING_STATUS_COMPLETED = 'completed';
    public const MAPPING_STATUS_FAILED = 'failed';
    public const MAPPING_STATUS_SKIPPED = 'skipped';

    public const MAPPING_STATUSES = [
        self::MAPPING_STATUS_IDLE,
        self::MAPPING_STATUS_QUEUED,
        self::MAPPING_STATUS_PROCESSING,
        self::MAPPING_STATUS_COMPLETED,
        self::MAPPING_STATUS_FAILED,
        self::MAPPING_STATUS_SKIPPED,
    ];

    public const MAPPING_MODE_AUTO = 'auto';
    public const MAPPING_MODE_REVIEW = 'review';

    public const MAPPING_MODES = [
        self::MAPPING_MODE_AUTO,
        self::MAPPING_MODE_REVIEW,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subject',
        'source_filename',
        'total_items',
        'mapping_status',
        'mapping_mode',
        'mapping_message',
        'mapping_requested_at',
        'mapping_completed_at',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'mapping_requested_at' => 'datetime',
        'mapping_completed_at' => 'datetime',
    ];

    /**
     * User who created this bank.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Questions under this bank.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)->orderBy('item_number');
    }

    /**
     * Assessment questions that can be used in official exams.
     */
    public function assessmentQuestions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->orderBy('item_number');
    }

    /**
     * Review Bot bank questions (teacher-generated, served to students
     * without a live AI call).
     */
    public function reviewBotQuestions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->orderBy('item_number');
    }

    /**
     * Exams that use this bank.
     */
    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_question_bank')
            ->withPivot(['position'])
            ->withTimestamps();
    }

    /**
     * Exams that still reference this bank via the legacy single-bank column.
     */
    public function primaryExams(): HasMany
    {
        return $this->hasMany(Exam::class, 'question_bank_id');
    }
}
