<?php

namespace App\Models;

use App\Services\MachineLearning\LogisticModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of the Estimated Outcome model (ridge-penalized logistic
 * regression), kept whether or not it was taken up, with the figures it was
 * judged on. Version 1 is the model trained in WEKA, loaded by a migration
 * from ml/models/logistic-v1.json; retrained versions count on from 2. At
 * most one row is active, and its model is what predictions use.
 */
class LetModel extends Model
{
    /** The model predictions are made with. */
    public const STATUS_ACTIVE = 'active';

    /** Was in use, then replaced by another version. */
    public const STATUS_RETIRED = 'retired';

    /** Trained, but did not pass the checks, so not taken up. */
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_WEKA = 'weka';

    public const SOURCE_RETRAINED = 'retrained';

    public const TRIGGER_MANUAL = 'manual';

    /** Objective 3 of the paper: what a version must reach to be usable. */
    public const TARGET_ROC_AREA = 0.70;

    public const TARGET_FAILED_RECALL = 0.60;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'version',
        'status',
        'source',
        'algorithm',
        'parameters',
        'ridge',
        'at_risk_cutoff',
        'training_count',
        'failed_count',
        'imported_count',
        'graduate_count',
        'roc_area',
        'failed_recall',
        'false_alarm_rate',
        'failed_precision',
        'failed_f_measure',
        'accuracy',
        'kappa',
        'zero_r_accuracy',
        'zero_r_kappa',
        'meets_targets',
        'evaluation',
        'selection',
        'checks',
        'training_keys',
        'file_path',
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
            'parameters' => 'array',
            'evaluation' => 'array',
            'selection' => 'array',
            'checks' => 'array',
            'training_keys' => 'array',
            'ridge' => 'float',
            'at_risk_cutoff' => 'float',
            'training_count' => 'integer',
            'failed_count' => 'integer',
            'imported_count' => 'integer',
            'graduate_count' => 'integer',
            'roc_area' => 'float',
            'failed_recall' => 'float',
            'false_alarm_rate' => 'float',
            'failed_precision' => 'float',
            'failed_f_measure' => 'float',
            'accuracy' => 'float',
            'kappa' => 'float',
            'zero_r_accuracy' => 'float',
            'zero_r_kappa' => 'float',
            'meets_targets' => 'boolean',
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

    public function model(): LogisticModel
    {
        return LogisticModel::fromArray($this->parameters);
    }

    public static function meetsTargets(float $rocArea, float $failedRecall): bool
    {
        return $rocArea >= self::TARGET_ROC_AREA && $failedRecall >= self::TARGET_FAILED_RECALL;
    }

    /**
     * Whether an administrator may put this version into use: any saved
     * version that meets both targets and is not already in use.
     */
    public function canBeActivated(): bool
    {
        return $this->status !== self::STATUS_ACTIVE && $this->meets_targets;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trained_by');
    }
}
