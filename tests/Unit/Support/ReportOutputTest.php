<?php

namespace Tests\Unit\Support;

use App\Support\Reports\ReportOutput;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The clock and file names every export shares. */
class ReportOutputTest extends TestCase
{
    public function test_times_are_written_on_the_school_s_clock_not_the_server_s(): void
    {
        // 12:52 PM on the server (UTC) was 8:52 PM in the Philippines.
        $this->assertSame('Sep 23, 2026, 8:52 PM', ReportOutput::time(Carbon::parse('2026-09-23 12:52:45', 'UTC')));
        $this->assertSame('Sep 24, 2026', ReportOutput::date(Carbon::parse('2026-09-23 18:30:00', 'UTC')));
        $this->assertNull(ReportOutput::time(null));
    }

    public function test_a_file_name_reads_as_words_and_drops_what_a_file_name_cannot_hold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 18:00:00', 'UTC'));

        $this->assertSame(
            'BLIS 4-A - Pre-Board Exam Selection Acquisition - Class Results - 2026-10-21.xlsx',
            ReportOutput::filename(['BLIS 4-A', 'Pre-Board Exam: Selection / Acquisition', null, 'Class Results'], 'xlsx'),
        );

        Carbon::setTestNow();
    }
}
