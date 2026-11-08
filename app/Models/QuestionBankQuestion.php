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
    public const USAGE_REVIEW_BOT = 'review_bot';
    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD = 'hard';
    public const TAG_STATUS_PENDING_REVIEW = 'pending_review';
    public const TAG_STATUS_CONFIRMED = 'confirmed';
    public const TAG_SOURCE_FACULTY = 'faculty';
    public const TAG_SOURCE_AI = 'ai';

    /** Tagged by the free local keyword/heuristic classifier (see LocalQuestionClassifier), not by a real AI call. */
    public const TAG_SOURCE_RULE_BASED = 'rule_based';
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /**
     * A generated draft a teacher has declined. Kept rather than deleted so the
     * decision is a record, and so coverage reporting can distinguish "this
     * exam item produced nothing usable" from "this item was never attempted".
     */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
    ];

    public const TAG_STATUSES = [
        self::TAG_STATUS_PENDING_REVIEW,
        self::TAG_STATUS_CONFIRMED,
    ];

    /**
     * Ordered easiest to hardest. Review Bot serves a source item's variants in
     * this order, so a student proves recall before being asked to apply.
     */
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
        self::USAGE_REVIEW_BOT,
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
        'source_question_id',
        'subject',
        'topic_id',
        'subtopic_id',
        'difficulty',
        'cognitive_level',
        'tag_status',
        'tag_source',
        'explanation',
        'status',
        'validation_flags',
        'embedding',
        'source_reference',
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
        'validation_flags' => 'array',
    ];

    /**
     * `embedding` is a packed float32 binary blob (see
     * ReviewBotQuestionScreeningService::encodeVector()) used only for the
     * server-side near-duplicate check during generation -- it is never
     * valid UTF-8, so any JSON response built from a full model/collection
     * (not a hand-picked array) fails outright with "Malformed UTF-8
     * characters" the moment a row actually carries one. Hidden here rather
     * than relying on every future query to remember to exclude the column.
     *
     * @var list<string>
     */
    protected $hidden = [
        'embedding',
    ];

    /**
     * The exam item this Review Bot question was generated from.
     */
    public function sourceQuestion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_question_id');
    }

    /**
     * Review Bot questions generated from this exam item.
     */
    public function generatedVariants(): HasMany
    {
        return $this->hasMany(self::class, 'source_question_id');
    }

    /**
     * Generated variants that still count as coverage of this exam item.
     *
     * Rejected variants deliberately do not. resolveSourceQuestions() skips
     * items that already have variants, so counting rejected ones would leave
     * an item whose whole batch was declined permanently ineligible for
     * regeneration -- stuck with nothing usable and no way to try again.
     *
     * @return HasMany<self, $this>
     */
    public function liveGeneratedVariants(): HasMany
    {
        return $this->generatedVariants()->where('status', '!=', self::STATUS_REJECTED);
    }

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
     * Subtopic tag assigned to the question.
     */
    public function subtopic(): BelongsTo
    {
        return $this->belongsTo(Subtopic::class);
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
