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
    public const USAGE_REMEDIAL = 'remedial';
    public const SOURCE_FACULTY = 'faculty';
    public const SOURCE_AI = 'ai_generated';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD = 'hard';
    public const ITEM_STYLE_REVERSE_ASSOCIATION = 'reverse_association';
    public const ITEM_STYLE_DEFINITION_TO_TERM = 'definition_to_term';
    public const ITEM_STYLE_SCENARIO_BASED = 'scenario_based';
    public const ITEM_STYLE_COMPARISON = 'comparison';

    public const TYPES = [
        self::TYPE_MULTIPLE_CHOICE,
        self::TYPE_TRUE_FALSE,
        self::TYPE_OPEN_ENDED,
    ];

    public const USAGE_TYPES = [
        self::USAGE_ASSESSMENT,
        self::USAGE_REMEDIAL,
    ];

    public const SOURCES = [
        self::SOURCE_FACULTY,
        self::SOURCE_AI,
    ];

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const DIFFICULTIES = [
        self::DIFFICULTY_EASY,
        self::DIFFICULTY_MEDIUM,
        self::DIFFICULTY_HARD,
    ];

    public const ITEM_STYLES = [
        self::ITEM_STYLE_REVERSE_ASSOCIATION,
        self::ITEM_STYLE_DEFINITION_TO_TERM,
        self::ITEM_STYLE_SCENARIO_BASED,
        self::ITEM_STYLE_COMPARISON,
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
        'source',
        'status',
        'ai_generated',
        'item_style',
        'cognitive_level',
        'tested_concept',
        'difference_from_source',
        'explanation',
        'source_question_id',
        'item_number',
        'question_text',
        'question_type',
        'answer_label',
        'answer_text',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'ai_generated' => 'boolean',
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
     * Source assessment question used to generate this remedial item.
     */
    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_question_id');
    }

    /**
     * Remedial children generated from this assessment question.
     */
    public function remedialVariants(): HasMany
    {
        return $this->hasMany(self::class, 'source_question_id')
            ->where('usage_type', self::USAGE_REMEDIAL)
            ->orderBy('id');
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

    public function practiceAttemptQuestions(): HasMany
    {
        return $this->hasMany(PracticeAttemptQuestion::class, 'question_bank_question_id');
    }

    public function practiceAttemptAnswers(): HasMany
    {
        return $this->hasMany(PracticeAttemptAnswer::class, 'question_bank_question_id');
    }
}
