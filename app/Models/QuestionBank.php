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

    public const REMEDIAL_GENERATION_STATUS_IDLE = 'idle';
    public const REMEDIAL_GENERATION_STATUS_QUEUED = 'queued';
    public const REMEDIAL_GENERATION_STATUS_PROCESSING = 'processing';
    public const REMEDIAL_GENERATION_STATUS_COMPLETED = 'completed';
    public const REMEDIAL_GENERATION_STATUS_FAILED = 'failed';
    public const REMEDIAL_GENERATION_STATUS_SKIPPED = 'skipped';

    public const REMEDIAL_GENERATION_STATUSES = [
        self::REMEDIAL_GENERATION_STATUS_IDLE,
        self::REMEDIAL_GENERATION_STATUS_QUEUED,
        self::REMEDIAL_GENERATION_STATUS_PROCESSING,
        self::REMEDIAL_GENERATION_STATUS_COMPLETED,
        self::REMEDIAL_GENERATION_STATUS_FAILED,
        self::REMEDIAL_GENERATION_STATUS_SKIPPED,
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
        'remedial_generation_status',
        'remedial_generation_message',
        'remedial_generation_requested_at',
        'remedial_generation_completed_at',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'remedial_generation_requested_at' => 'datetime',
        'remedial_generation_completed_at' => 'datetime',
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
     * Generated remedial questions linked to this bank.
     */
    public function remedialQuestions(): HasMany
    {
        return $this->hasMany(QuestionBankQuestion::class)
            ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
            ->orderBy('item_number')
            ->orderBy('id');
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
