<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrained LET readiness tree, kept whether or not it went live.
 *
 * At most one row is active. When none is, LetPredictionService falls back to
 * the tree built into its source, which is the model the system shipped with.
 */
class LetModel extends Model
{
    /** The tree predictions are made with. */
    public const STATUS_ACTIVE = 'active';

    /** Was live, then replaced by a later model. */
    public const STATUS_RETIRED = 'retired';

    /** Trained, but scored below the live model on new results, so never used. */
    public const STATUS_REJECTED = 'rejected';

    /** Was live, then taken out of use by an administrator. */
    public const STATUS_REVERTED = 'reverted';

    public const TRIGGER_AUTO = 'auto';
    public const TRIGGER_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'status',
        'tree',
        'training_count',
        'graduate_count',
        'imported_count',
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
            'tree' => 'array',
            'training_keys' => 'array',
            'metrics' => 'array',
            'training_count' => 'integer',
            'graduate_count' => 'integer',
            'imported_count' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trained_by');
    }
}
