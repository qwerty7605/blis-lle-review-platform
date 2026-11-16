<?php

namespace Tests\Unit\Support;

use App\Models\SystemSetting;
use App\Support\SchoolCalendar;
use App\Support\SystemSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The school calendar's arithmetic: which school year and semester a date
 * falls in, for LNU's months (August and January) and for other pairs an
 * administrator could pick, read on the school's clock.
 */
class SchoolCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_lnu_files_august_to_december_as_the_1st_semester_and_january_to_july_as_the_2nd(): void
    {
        foreach ([8, 9, 10, 11, 12] as $month) {
            $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2026, $month, 8, 1), "month {$month}");
        }

        // No midyear term: June and July stay in the 2nd semester.
        foreach ([1, 2, 3, 4, 5, 6, 7] as $month) {
            $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2027, $month, 8, 1), "month {$month}");
        }
    }

    public function test_a_2nd_semester_that_runs_past_december_stays_in_its_school_year(): void
    {
        // June to October, then November to May.
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2026, 6, 6, 11));
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2026, 10, 6, 11));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2026, 11, 6, 11));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2026, 12, 6, 11));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2027, 5, 6, 11));
        $this->assertSame(['school_year' => 2027, 'semester' => 1], $this->term(2027, 6, 6, 11));
    }

    public function test_a_1st_semester_that_crosses_new_year_keeps_the_year_it_started_in(): void
    {
        // November to February, then March to October.
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2026, 11, 11, 3));
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2027, 2, 11, 3));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2027, 3, 11, 3));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2027, 10, 11, 3));
    }

    public function test_a_school_year_inside_one_calendar_year_is_named_by_that_year(): void
    {
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $this->term(2026, 1, 1, 6));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $this->term(2026, 12, 1, 6));

        $this->assertSame('2nd Semester, AY 2026', SchoolCalendar::label(2026, 2, 1));
        $this->assertSame('1st Semester, AY 2026-2027', SchoolCalendar::label(2026, 1, 8));
    }

    public function test_a_date_is_read_on_the_schools_clock_not_the_servers(): void
    {
        // 16:30 UTC on July 31 is 00:30 on August 1 in Manila.
        $this->assertSame(
            ['school_year' => 2026, 'semester' => 1],
            SchoolCalendar::termIn(CarbonImmutable::parse('2026-07-31 16:30:00', 'UTC'), 8, 1),
        );
        $this->assertSame(
            ['school_year' => 2026, 'semester' => 2],
            SchoolCalendar::termIn(CarbonImmutable::parse('2026-12-31 16:30:00', 'UTC'), 8, 1),
        );
    }

    public function test_the_calendar_reads_the_months_saved_in_settings(): void
    {
        $calendar = app(SchoolCalendar::class);
        $november = CarbonImmutable::parse('2026-11-17 09:00', 'Asia/Manila');

        $this->assertSame(['first' => 8, 'second' => 1], $calendar->startMonths(), 'nothing saved yet: August and January');
        $this->assertSame(['school_year' => 2026, 'semester' => 1], $calendar->termFor($november));

        SystemSetting::create(['key' => 'first_semester_start_month', 'value' => '6']);
        SystemSetting::create(['key' => 'second_semester_start_month', 'value' => '11']);
        app(SystemSettings::class)->forget();

        $this->assertSame(['first' => 6, 'second' => 11], $calendar->startMonths());
        $this->assertSame(['school_year' => 2026, 'semester' => 2], $calendar->termFor($november));
    }

    public function test_a_saved_pair_that_is_not_a_calendar_reads_as_the_default(): void
    {
        SystemSetting::create(['key' => 'first_semester_start_month', 'value' => '3']);
        SystemSetting::create(['key' => 'second_semester_start_month', 'value' => '3']);
        app(SystemSettings::class)->forget();

        $this->assertSame(['first' => 8, 'second' => 1], app(SchoolCalendar::class)->startMonths());

        $this->assertFalse(SchoolCalendar::validMonths(0, 1));
        $this->assertFalse(SchoolCalendar::validMonths(8, 13));
        $this->assertFalse(SchoolCalendar::validMonths('8.5', 1));
        $this->assertFalse(SchoolCalendar::validMonths('August', 1));
        $this->assertTrue(SchoolCalendar::validMonths('8', '1'));
    }

    /** @return array{school_year: int, semester: int} */
    private function term(int $year, int $month, int $first, int $second): array
    {
        return SchoolCalendar::termIn(CarbonImmutable::create($year, $month, 15, 12, 0, 0, 'Asia/Manila'), $first, $second);
    }
}
