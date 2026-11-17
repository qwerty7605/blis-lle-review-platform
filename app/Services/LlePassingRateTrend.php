<?php

namespace App\Services;

use App\Models\LlePassingRate;
use App\Models\LleResult;
use Illuminate\Support\Collection;

/**
 * LNU's licensure passing rate year by year, beside the national rate, for
 * the Licensure page's trend.
 *
 * LNU's rate for an exam year is passers over takers among the results
 * recorded for that year: every sitting, first takers and repeaters alike,
 * which is how the PRC counts the national rate. "Did not take" is no
 * sitting and is left out. LNU's figures for a year before BLIS kept its
 * results can be typed in (scope `school`), and figures typed in for a year
 * are used in place of its recorded results, since a year BLIS holds only
 * some results for is the likeliest reason to type them; the recorded count
 * still comes back beside them. The national figures are always typed in
 * (scope `national`). A year with neither has no rate, never 0%.
 */
class LlePassingRateTrend
{
    public const BOTH_COUNTS_PASSERS = 'Enter how many passed too, or leave both numbers empty.';

    public const BOTH_COUNTS_TAKERS = 'Enter how many took it too, or leave both numbers empty.';

    public const MORE_PASSERS_THAN_TAKERS = 'More passed than took it. Check both numbers.';

    public const RATE_NEEDED = 'Enter the passing rate, or how many passed and how many took it.';

    /**
     * Every exam year with a rate on either side, oldest first.
     *
     * @return list<array{exam_year: int, lnu: ?array<string, mixed>, recorded: ?array<string, mixed>, national: ?array<string, mixed>, difference: ?float}>
     *   `lnu` is the figure the trend shows for LNU (`from`: `entered` or `recorded`), `recorded` what BLIS's own results give for that year whatever is shown, `difference` LNU's rate minus the national one in percentage points
     */
    public function years(): array
    {
        $recorded = LleResult::query()
            ->taken()
            ->selectRaw('exam_year, COUNT(*) AS takers, SUM(CASE WHEN result = ? THEN 1 ELSE 0 END) AS passers', [LleResult::RESULT_PASS])
            ->groupBy('exam_year')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->exam_year => $this->counted((int) $row->passers, (int) $row->takers)]);

        $entered = LlePassingRate::query()->with('recorder:id,name')->get();
        $national = $this->byYear($entered, LlePassingRate::SCOPE_NATIONAL);
        $school = $this->byYear($entered, LlePassingRate::SCOPE_SCHOOL);

        $years = $recorded->keys()
            ->merge($national->keys())
            ->merge($school->keys())
            ->map(fn ($year): int => (int) $year)
            ->unique()
            ->sort()
            ->values();

        return $years->map(function (int $year) use ($recorded, $national, $school): array {
            $fromRecords = $recorded->get($year);
            $lnu = $school->has($year)
                ? $this->entered($school->get($year))
                : ($fromRecords !== null ? $fromRecords + ['from' => 'recorded'] : null);
            $nationalFigure = $national->has($year) ? $this->entered($national->get($year)) : null;

            return [
                'exam_year' => $year,
                'lnu' => $lnu,
                'recorded' => $fromRecords,
                'national' => $nationalFigure,
                'difference' => $lnu !== null && $nationalFigure !== null
                    ? round($lnu['passing_rate'] - $nationalFigure['passing_rate'], 2)
                    : null,
            ];
        })->all();
    }

    /**
     * What is wrong with figures typed in, as field => reason ("passers",
     * "takers", "passing_rate", "exam_year"). The page says the same
     * (lleResultChecks.js passingRateProblems()).
     *
     * @return array<string, string>
     */
    public function figureProblems(int $examYear, ?int $passers, ?int $takers, float|int|string|null $rate): array
    {
        $problems = [];
        $thisYear = LleResultRules::today()->year;

        if ($examYear > $thisYear) {
            $problems['exam_year'] = sprintf('The exam year cannot be later than this year (%d).', $thisYear);
        }

        if ($passers !== null && $takers === null) {
            $problems['takers'] = self::BOTH_COUNTS_TAKERS;
        } elseif ($takers !== null && $passers === null) {
            $problems['passers'] = self::BOTH_COUNTS_PASSERS;
        } elseif ($passers !== null && $passers > $takers) {
            $problems['passers'] = self::MORE_PASSERS_THAN_TAKERS;
        } elseif ($passers === null && ($rate === null || $rate === '')) {
            $problems['passing_rate'] = self::RATE_NEEDED;
        }

        return $problems;
    }

    /**
     * @param  Collection<int, LlePassingRate>  $entered
     * @return Collection<int, LlePassingRate>
     */
    private function byYear(Collection $entered, string $scope): Collection
    {
        return $entered
            ->where('scope', $scope)
            ->mapWithKeys(fn (LlePassingRate $figure): array => [(int) $figure->exam_year => $figure]);
    }

    /** @return array{passers: int, takers: int, passing_rate: float} */
    private function counted(int $passers, int $takers): array
    {
        return ['passers' => $passers, 'takers' => $takers, 'passing_rate' => LlePassingRate::rateOf($passers, $takers)];
    }

    /** @return array<string, mixed> */
    private function entered(LlePassingRate $figure): array
    {
        return [
            'from' => 'entered',
            'id' => (int) $figure->id,
            'passers' => $figure->passers,
            'takers' => $figure->takers,
            'passing_rate' => (float) $figure->passing_rate,
            'source' => $figure->source,
            'recorded_by' => $figure->recorder?->name,
            'updated_at' => $figure->updated_at?->toIso8601String(),
        ];
    }
}
