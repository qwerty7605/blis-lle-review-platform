<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionBankQuestion extends Model
{
    use HasFactory;

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const TYPE_TRUE_FALSE = 'true_false';
    public const TYPE_OPEN_ENDED = 'open_ended';
    public const USAGE_ASSESSMENT = 'assessment';
    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD = 'hard';
    public const TAG_STATUS_PENDING_REVIEW = 'pending_review';
    public const TAG_STATUS_CONFIRMED = 'confirmed';
    public const TAG_SOURCE_FACULTY = 'faculty';
    public const TAG_SOURCE_AI = 'ai';

    public const TAG_STATUSES = [
        self::TAG_STATUS_PENDING_REVIEW,
        self::TAG_STATUS_CONFIRMED,
    ];

    public const COGNITIVE_LEVELS = [
        'recall',
        'comprehension',
        'application',
        'analysis',
    ];

    public const TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE,
        self::TYPE_OPEN_ENDED,
    ];

    public const USAGE_TYPES = [
        self::USAGE_ASSESSMENT,
    ];

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY,
        self::DIFFICULTY_MEDIUM,
        self::DIFFICULTY_HARD,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'question_bank_id',
        'usage_type',
        'subject',
        'topic_id',
        'difficulty',
        'cognitive_level',
        'tag_status',
        'tag_source',
        'item_number',
        'question_text',
        'question_type',
        'answer_label',
        'answer_text',
    ];

    /**
     * Parent question bank.
     */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class);
    }

    /**
     * Topic tag assigned to the question.
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * Answer options for the question.
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionBankOption::class)
            ->orderBy('sort_order');
    }

    /**
     * Attempt-question slots that reference this question.
     */
    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class, 'question_bank_question_id');
    }

    /**
     * Answers submitted against this question.
     */
    public function attemptAnswers(): HasMany
    {
        return $this->hasMany(ExamAttemptAnswer::class, 'question_bank_question_id');
    }
}
