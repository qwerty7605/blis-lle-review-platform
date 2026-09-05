<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's actual Librarian Licensure Examination outcome.
 *
 * This is the training label LetPredictionService needs to ever be retrained:
 * the mock-exam history in exam_attempts supplies the 15 features, and this
 * supplies the answer they are supposed to predict. Only a graduate with a row
 * here is a usable training example -- a student who left without sitting the
 * exam is censored data, not a failure, and must not be fed in as one.
 */
class LleResult extends Model
{
    use HasFactory;

    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';

    /**
     * @var list<string>
     */
    public const RESULTS = [
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
}
