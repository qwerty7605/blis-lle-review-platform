<?php

namespace App\Support;

use App\Models\Exam;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The school's calendar: the month each semester starts in, which an
 * administrator sets in System Settings (LNU: the 1st semester in August, the
 * 2nd in January). A semester runs until the other one starts, so with no
 * midyear term June and July belong to the 2nd semester.
 *
 * A school year is named by the calendar year its 1st semester starts in:
 * "AY 2026-2027" began in August 2026, so January 2027 is the 2nd semester
 * of school year 2026. Dates are read on the school's clock
 * (app.school_timezone), not the server's, so an exam opening at 00:30 on
 * August 1 in Manila is filed under the new school year.
 *
 * Read when an exam is filed under a term (Exam::termForDate()), when a
 * student signs up (their admission year), and by System Settings, which
 * shows the current term.
 */
final class SchoolCalendar
{
    public const DEFAULT_FIRST_SEMESTER_MONTH = 8;

    public const DEFAULT_SECOND_SEMESTER_MONTH = 1;

    public function __construct(
        private readonly SystemSettings $settings,
    ) {
    }

    /**
     * @return array{first: int, second: int} the month (1-12) each semester starts in
     */
    public function startMonths(): array
    {
        return $this->settings->semesterStartMonths();
    }

    /**
     * The school year and semester a date falls in.
     *
     * @return array{school_year: int, semester: int}
     */
    public function termFor(CarbonInterface $date): array
    {
        $months = $this->startMonths();

        return self::termIn($date, $months['first'], $months['second']);
    }

    /** The school year a date falls in, named by the year it started. */
    public function schoolYearFor(CarbonInterface $date): int
    {
        return $this->termFor($date)['school_year'];
    }

    /**
     * Today's term, with its name ("1st Semester, AY 2026-2027").
     *
     * @return array{school_year: int, semester: int, label: string}
     */
    public function current(): array
    {
        $term = $this->termFor(now());

        return $term + ['label' => self::label($term['school_year'], $term['semester'], $this->startMonths()['first'])];
    }

    /**
     * The arithmetic, for any pair of start months. Before the 1st
     * semester's month the date is still in the school year that started the
     * year before; within a school year, the 1st semester lasts from its month
     * up to the month the 2nd starts (counted round the end of December).
     *
     * @return array{school_year: int, semester: int}
     */
    public static function termIn(CarbonInterface $date, int $firstMonth, int $secondMonth): array
    {
        $local = CarbonImmutable::instance($date)->setTimezone((string) config('app.school_timezone', 'Asia/Manila'));
        $month = $local->month;

        $monthsIntoYear = ($month - $firstMonth + 12) % 12;
        $firstSemesterLength = ($secondMonth - $firstMonth + 12) % 12;

        return [
            'school_year' => $month >= $firstMonth ? $local->year : $local->year - 1,
            'semester' => $monthsIntoYear < $firstSemesterLength ? 1 : 2,
        ];
    }

    /**
     * "1st Semester, AY 2026-2027"; a school year that starts in January
     * lies inside one calendar year and is named by it alone.
     */
    public static function label(int $schoolYear, int $semester, int $firstMonth = self::DEFAULT_FIRST_SEMESTER_MONTH): string
    {
        if ($firstMonth === 1) {
            return sprintf('%s Semester, AY %d', $semester === 1 ? '1st' : '2nd', $schoolYear);
        }

        return Exam::termLabel($schoolYear, $semester);
    }

    /** Whether two start months make a calendar: each 1-12, and not the same. */
    public static function validMonths(mixed $first, mixed $second): bool
    {
        $isMonth = static fn (mixed $value): bool => is_numeric($value)
            && (int) $value == $value
            && (int) $value >= 1
            && (int) $value <= 12;

        return $isMonth($first) && $isMonth($second) && (int) $first !== (int) $second;
    }
}
