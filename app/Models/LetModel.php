<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrained version of the board exam model, kept whether or not it was
 * taken up, with the figures it was judged on.
 *
 * The model the system shipped with is version 1 and lives in the code
 * (LetPredictionService); these rows count on from 2. At most one row is
 * active. When none is, version 1 is the model in use.
 */
class LetModel extends Model
{
    /** The tree predictions are made with. */
    public const STATUS_ACTIVE = 'active';

    /** Was in use, then replaced by another version. */
    public const STATUS_RETIRED = 'retired';

    /** Trained, but did not pass the checks, so never used. */
    public const STATUS_REJECTED = 'rejected';

    public const TRIGGER_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'version',
        'status',
        'tree',
        'training_count',
        'failed_count',
        'graduate_count',
        'imported_count',
        'accuracy',
        'kappa',
        'failed_recall',
        'baseline',
        'file_path',
        'training_keys',
        'metrics',
        'trigger',
        'trained_by',
        'activated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'tree' => 'array',
            'training_keys' => 'array',
            'metrics' => 'array',
            'training_count' => 'integer',
            'failed_count' => 'integer',
            'graduate_count' => 'integer',
            'imported_count' => 'integer',
            'accuracy' => 'float',
            'kappa' => 'float',
            'failed_recall' => 'float',
            'baseline' => 'float',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<LetModel>  $query
     * @return Builder<LetModel>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Trained by the present rules. Rows from before them have no accuracy
     * recorded, and their trees read attributes the model no longer has.
     *
     * @param  Builder<LetModel>  $query
     * @return Builder<LetModel>
     */
    public function scopeCurrentRules(Builder $query): Builder
    {
        return $query->whereNotNull('accuracy');
    }

    /** Whether an administrator may put this version (back) into use. */
    public function canBeActivated(): bool
    {
        return $this->status !== self::STATUS_ACTIVE
            && $this->accuracy !== null
            && $this->activated_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trained_by');
    }
}
