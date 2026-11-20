<?php

namespace Tests\Unit\Support;

use App\Models\SystemSetting;
use App\Support\PassingMark;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The students' passing mark and the grade teachers see: a score converted
 * so the mark lands on 75, 0 stays 0 and 100 stays 100. The expected grades
 * are the table shown to the panel's question, worked out by hand.
 */
class PassingMarkTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{int, float, float}> mark, score, grade */
    public static function conversions(): array
    {
        $table = [
            78 => [100 => 100.0, 95 => 94.32, 90 => 88.64, 85 => 82.95, 80 => 77.27, 78 => 75.0, 70 => 67.31, 50 => 48.08],
            80 => [100 => 100.0, 95 => 93.75, 90 => 87.5, 85 => 81.25, 80 => 75.0, 78 => 73.13, 70 => 65.63, 50 => 46.88],
            85 => [100 => 100.0, 95 => 91.67, 90 => 83.33, 85 => 75.0, 84 => 74.12, 80 => 70.59, 78 => 68.82, 70 => 61.76, 50 => 44.12],
            90 => [100 => 100.0, 95 => 87.5, 90 => 75.0, 89 => 74.17, 85 => 70.83, 80 => 66.67, 78 => 65.0, 70 => 58.33, 50 => 41.67],
        ];

        $cases = [];
        foreach ($table as $mark => $grades) {
            foreach ($grades as $score => $grade) {
                $cases["mark {$mark}, score {$score}"] = [$mark, (float) $score, $grade];
            }
        }

        return $cases;
    }

    #[DataProvider('conversions')]
    public function test_a_score_converts_to_the_grade_in_the_table(int $mark, float $score, float $grade): void
    {
        $this->assertSame($grade, (new PassingMark($mark))->grade($score));
    }

    public function test_at_75_nothing_converts(): void
    {
        $mark = new PassingMark(75);

        $this->assertFalse($mark->converts());
        foreach ([0.0, 42.5, 74.99, 75.0, 88.0, 100.0] as $score) {
            $this->assertSame($score, $mark->grade($score));
        }
        foreach ([40.0, 50.0, 60.0, 75.0, 85.0, 90.0] as $cutoff) {
            $this->assertSame($cutoff, $mark->scoreFor($cutoff));
        }
    }

    public function test_every_mark_lands_on_75_and_keeps_the_ends(): void
    {
        for ($value = PassingMark::MIN; $value <= PassingMark::MAX; $value++) {
            $mark = new PassingMark($value);

            $this->assertSame(75.0, $mark->grade((float) $value), "mark {$value}");
            $this->assertSame(0.0, $mark->grade(0.0));
            $this->assertSame(100.0, $mark->grade(100.0));
            $this->assertSame((float) $value, $mark->scoreFor(75));
        }
    }

    public function test_a_cut_off_on_the_teachers_scale_moves_with_the_mark(): void
    {
        $mark = new PassingMark(85);

        // The examples given: follow up under 68%, Strong from 91%, Outstanding from 94%.
        $this->assertSame(68.0, $mark->scoreFor(60));
        $this->assertSame(91.0, $mark->scoreFor(85));
        $this->assertSame(94.0, $mark->scoreFor(90));

        foreach ([78, 80, 85, 90] as $value) {
            $mark = new PassingMark($value);
            foreach ([40, 50, 60, 75, 80, 85, 90] as $cutoff) {
                $this->assertEqualsWithDelta($cutoff, $mark->grade($mark->scoreFor($cutoff)), 0.01, "mark {$value}, cut-off {$cutoff}");
            }
        }
    }

    public function test_a_grade_reaches_75_exactly_when_the_score_reaches_the_mark(): void
    {
        $mark = new PassingMark(85);

        $this->assertFalse($mark->passes(84.99));
        $this->assertLessThan(75, $mark->grade(84.99));
        $this->assertTrue($mark->passes(85.0));
        $this->assertSame(75.0, $mark->grade(85.0));
        $this->assertFalse($mark->passes(null));
        $this->assertNull($mark->grade(null));
    }

    public function test_only_whole_numbers_from_75_to_95_are_a_mark(): void
    {
        $this->assertTrue(PassingMark::valid(75));
        $this->assertTrue(PassingMark::valid('85'));
        $this->assertTrue(PassingMark::valid(95));
        $this->assertFalse(PassingMark::valid(74));
        $this->assertFalse(PassingMark::valid(96));
        $this->assertFalse(PassingMark::valid(100));
        $this->assertFalse(PassingMark::valid('82.5'));
        $this->assertFalse(PassingMark::valid('eighty'));

        // Anything else reads as 75: no conversion.
        $this->assertSame(75, (new PassingMark(96))->mark);
        $this->assertSame(75, (new PassingMark(60))->mark);
    }

    public function test_the_mark_in_use_comes_from_the_settings(): void
    {
        $this->assertSame(75, PassingMark::current()->mark);

        SystemSetting::create(['key' => 'students_passing_mark', 'value' => '85']);
        app(SystemSettings::class)->forget();
        $this->assertSame(85, PassingMark::current()->mark);
        $this->assertSame(['mark' => 85, 'staff_mark' => 75, 'converts' => true], PassingMark::current()->toArray());

        // A bad row can only ever mean "no conversion".
        SystemSetting::where('key', 'students_passing_mark')->update(['value' => '120']);
        app(SystemSettings::class)->forget();
        $this->assertSame(75, PassingMark::current()->mark);
    }
}
