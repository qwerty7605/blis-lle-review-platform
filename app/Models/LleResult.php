<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's actual Librarian Licensure Examination outcome.
 *
 * This is the training label LetPredictionService needs to ever be retrained:
 * the mock-exam history in exam_attempts supplies the 15 features, and this
 * supplies the answer they are supposed to predict. Only a graduate with a pass
 * or a fail here is a usable training example -- a student who did not sit the
 * exam is censored data, not a failure, and must not be fed in as one. That is
 * what "did not take" records: the student was accounted for in that exam
 * year without sitting it, so training, the passing rate and every count of
 * examinees leave it out (TAKEN, scopeTaken()).
 */
class LleResult extends Model
{
    use HasFactory;

    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';

    public const RESULT_DID_NOT_TAKE = 'did_not_take';

    /**
     * Every value `result` may hold.
     *
     * @var list<string>
     */
    public const RESULTS = [
        self::RESULT_PASS,
        self::RESULT_FAIL,
        self::RESULT_DID_NOT_TAKE,
    ];

    /**
     * The results of an actual sitting.
     *
     * @var list<string>
     */
    public const TAKEN = [
        self::RESULT_PASS,
        self::RESULT_FAIL,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'exam_year',
        'taken_at',
        'result',
        'rating',
        'remarks',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_year' => 'integer',
            'taken_at' => 'date',
            'rating' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isPass(): bool
    {
        return $this->result === self::RESULT_PASS;
    }

    /** A pass or a fail: the student sat the LLE that year. */
    public function wasTaken(): bool
    {
        return in_array($this->result, self::TAKEN, true);
    }

    /**
     * Sittings only, without "did not take".
     *
     * @param  Builder<LleResult>  $query
     * @return Builder<LleResult>
     */
    public function scopeTaken(Builder $query): Builder
    {
        return $query->whereIn('result', self::TAKEN);
    }
}
