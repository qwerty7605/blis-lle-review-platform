<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licensure passing rate typed in by hand for one exam year: the PRC's
 * national figures, or LNU's own for a year before BLIS kept its results
 * (see the lle_passing_rates migration). LNU's rate for a year BLIS has
 * results for is counted from lle_results instead (LlePassingRateTrend).
 */
class LlePassingRate extends Model
{
    public const SCOPE_NATIONAL = 'national';

    public const SCOPE_SCHOOL = 'school';

    /**
     * @var list<string>
     */
    public const SCOPES = [
        self::SCOPE_NATIONAL,
        self::SCOPE_SCHOOL,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'exam_year',
        'passers',
        'takers',
        'passing_rate',
        'source',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exam_year' => 'integer',
            'passers' => 'integer',
            'takers' => 'integer',
            'passing_rate' => 'float',
        ];
    }

    /**
     * Passers over takers, as a percentage to two places: how the PRC
     * reports it.
     */
    public static function rateOf(int $passers, int $takers): float
    {
        return $takers > 0 ? round($passers / $takers * 100, 2) : 0.0;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
