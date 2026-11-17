<?php

namespace App\Services;

use App\Models\LleResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a recorded Librarian Licensure Examination result may say.
 *
 * Refused here, because it cannot happen:
 *
 *  - A pass with a general average under the passing mark of its year. R.A.
 *    9246 (the Philippine Librarianship Act), Sec. 17: a weighted general
 *    average of 75% with no subject under 50%. BLIS keeps the general average
 *    only, so the rule goes one way: a fail may have 75 or more (a subject
 *    under 50%), and the result is never worked out from the average.
 *  - Any result in a year after the student passed: nobody sits the LLE again
 *    once licensed. Checked both ways, so a pass cannot be put in a year
 *    before another of their results either.
 *  - An exam year after this one, or a date taken after today or outside the
 *    exam year (the date decides which pre-tests and mock boards count as
 *    taken before the LLE when the model is retrained).
 *  - A general average or a date taken for a student who did not take the
 *    exam. "Did not take" is a record like any other for the rules above:
 *    nothing is recorded after a pass, so neither is it.
 *
 * What can happen but is usually a slip (a fail at 75 or more, a student not
 * marked graduated, replacing a result on record) is accepted: the Licensure
 * page asks before sending it.
 */
class LleResultRules
{
    /**
     * The lowest general average that passes, from the first exam year it
     * applies to. If the law changes, add the new mark from that year, so an
     * older sitting is still judged by its own rule.
     *
     * @var array<int, float>
     */
    public const PASSING_AVERAGES = [
        // R.A. 9246, approved 19 February 2004, Sec. 17.
        2004 => 75.0,
    ];

    public const NOT_TAKEN_AVERAGE_MESSAGE = 'A student who did not take the LLE has no general average. Leave it empty.';

    public const NOT_TAKEN_DATE_MESSAGE = 'A student who did not take the LLE has no date taken. Leave it empty.';

    public static function passingAverageFor(int $examYear): ?float
    {
        $mark = null;

        foreach (self::PASSING_AVERAGES as $fromYear => $average) {
            if ($examYear >= $fromYear) {
                $mark = $average;
            }
        }

        return $mark;
    }

    /**
     * Today at the school. The app's clock is UTC, eight hours behind
     * Manila: a board exam taken there this morning would still be
     * "tomorrow" by it.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.school_timezone', 'Asia/Manila'))->startOfDay();
    }

    /**
     * What is wrong with the sitting itself, as field => reason
     * ("exam_year", "taken_at"). The page says the same (fieldChecks.js
     * examDateProblem() and lleResultChecks.js examYearProblem()).
     *
     * @return array<string, string>
     */
    public function sittingProblems(int $examYear, ?string $takenAt): array
    {
        $problems = [];
        $today = self::today();

        if ($examYear > $today->year) {
            $problems['exam_year'] = sprintf('The exam year cannot be later than this year (%d).', $today->year);
        }

        if (filled($takenAt)) {
            $date = CarbonImmutable::parse($takenAt, $today->timezone)->startOfDay();

            if ($date->greaterThan($today)) {
                $problems['taken_at'] = 'Date taken cannot be later than today.';
            } elseif ($date->year !== $examYear) {
                $problems['taken_at'] = sprintf('This date is in %d, but the exam year is %d.', $date->year, $examYear);
            }
        }

        return $problems;
    }

    /**
     * Each student's results for every exam year but this one, oldest first,
     * by user id: what a new result for this year must agree with.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, LleResult>>
     */
    public function otherSittings(array $userIds, int $examYear): Collection
    {
        return LleResult::query()
            ->whereIn('user_id', $userIds)
            ->where('exam_year', '!=', $examYear)
            ->orderBy('exam_year')
            ->get(['id', 'user_id', 'exam_year', 'result'])
            ->groupBy('user_id');
    }

    /**
     * What the law and the student's other sittings rule out for one result,
     * as field => reason ("result", "rating"). The page says the same
     * (lleResultChecks.js).
     *
     * @param  Collection<int, LleResult>  $otherSittings  their results for other exam years (otherSittings())
     * @return array<string, string>
     */
    public function resultProblems(string $name, int $examYear, string $result, float|int|string|null $rating, Collection $otherSittings): array
    {
        $problems = [];

        $passedBefore = $otherSittings->first(
            fn (LleResult $sitting): bool => $sitting->isPass() && (int) $sitting->exam_year < $examYear,
        );
        $later = $otherSittings->first(fn (LleResult $sitting): bool => (int) $sitting->exam_year > $examYear);

        if ($passedBefore !== null) {
            $problems['result'] = sprintf(
                '%s passed the LLE in %d, so no result can be recorded for a later year.',
                $name,
                (int) $passedBefore->exam_year,
            );
        } elseif ($result === LleResult::RESULT_PASS && $later !== null) {
            $problems['result'] = $later->result === LleResult::RESULT_DID_NOT_TAKE
                ? sprintf(
                    '%s is recorded as not taking the LLE in %d, and nothing can come after a pass. Remove that record first, or check the year.',
                    $name,
                    (int) $later->exam_year,
                )
                : sprintf(
                    '%s already has a result for %d, and nothing can come after a pass. Record %d as Failed, or check the year.',
                    $name,
                    (int) $later->exam_year,
                    $examYear,
                );
        }

        if ($result === LleResult::RESULT_DID_NOT_TAKE && $rating !== null && $rating !== '') {
            $problems['rating'] = self::NOT_TAKEN_AVERAGE_MESSAGE;
        }

        $mark = self::passingAverageFor($examYear);

        if ($result === LleResult::RESULT_PASS && $mark !== null && $rating !== null && $rating !== '' && round((float) $rating, 2) < $mark) {
            $problems['rating'] = self::belowPassingMessage($mark);
        }

        return $problems;
    }

    /**
     * One Student's date taken. By Batch's date is the whole list's, and is
     * kept only for the students who sat (LleResultController::storeBatch).
     *
     * @return array<string, string>
     */
    public function notTakenDateProblems(string $result, ?string $takenAt): array
    {
        return $result === LleResult::RESULT_DID_NOT_TAKE && filled($takenAt)
            ? ['taken_at' => self::NOT_TAKEN_DATE_MESSAGE]
            : [];
    }

    public static function belowPassingMessage(float $mark): string
    {
        $shown = rtrim(rtrim(number_format($mark, 2, '.', ''), '0'), '.');

        return "A general average under {$shown} is a failing grade (R.A. 9246, Sec. 17). Check the result or the average.";
    }
}
