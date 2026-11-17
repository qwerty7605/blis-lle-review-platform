<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamSeries;
use App\Models\LetModel;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\BatteryEarlyEstimate;
use App\Services\MachineLearning\LogisticModel;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The early estimate from battery exams on the staff Student Profile. It is
 * off until the version of the model in use is ready for battery exams, and
 * follows whichever version is in use; then it reads a 3rd year's latest
 * complete semester of battery exams, until a finished pre-test or mock
 * board hands over to the Estimated Outcome.
 */
class BatteryEarlyEstimateTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->room = $this->makeRoom($this->teacher);
    }

    /** A student in their 3rd year in AY 2026-2027. */
    private function thirdYear(): User
    {
        $student = $this->makeStudent(['year_level' => 3, 'admission_year' => 2024]);
        $this->room->members()->syncWithoutDetaching([$student->id]);

        return $student;
    }

    /** @return array<string, mixed> */
    private function estimate(User $student): array
    {
        Sanctum::actingAs($this->teacher);

        return $this->getJson("/api/students/{$student->id}/analytics")->assertOk()->json('early_estimate');
    }

    /**
     * Version 2, learnt from battery exams (the three-type model WEKA trained
     * on tests/Fixtures/lle_battery_n60.arff), put into use.
     */
    private function batteryVersion(bool $ready): LetModel
    {
        $first = LetModel::query()->where('version', 1)->firstOrFail();
        $model = LogisticModel::fromWekaExport(json_decode((string) file_get_contents(base_path('tests/Fixtures/lle_battery_n60.weka.json')), true));

        $second = $first->replicate();
        $second->forceFill([
            'version' => 2,
            'status' => LetModel::STATUS_ACTIVE,
            'source' => 'retrained',
            'parameters' => $model->toArray(),
            'battery_row_count' => 24,
            'battery_graduate_count' => 24,
            'battery_failed_graduate_count' => $ready ? 10 : 4,
            'battery_ready' => $ready,
            'activated_at' => now(),
        ])->save();
        $first->forceFill(['status' => LetModel::STATUS_RETIRED])->save();

        return $second;
    }

    public function test_it_follows_the_model_in_use_and_is_staff_only(): void
    {
        $student = $this->thirdYear();
        $exam = $this->makeBatteryExam($this->teacher, $this->room, '2026-09-15');
        $this->makeBatterySitting($exam, $this->room, $student, ['management' => 3, 'reference' => 2, 'indexing' => 4, 'selection' => 1, 'cataloging' => 2, 'it' => 3], '2026-09-15 10:00');

        // Version 1 never learnt battery exams.
        $this->assertSame(['status' => BatteryEarlyEstimate::STATUS_OFF], $this->estimate($student));

        // One that did, but whose battery rows did not pass their checks.
        $second = $this->batteryVersion(false);
        $this->assertSame(BatteryEarlyEstimate::STATUS_OFF, $this->estimate($student)['status']);

        $second->forceFill(['battery_ready' => true])->save();
        $estimate = $this->estimate($student);

        $scores = ['management' => 75.0, 'reference' => 50.0, 'indexing' => 100.0, 'selection' => 25.0, 'cataloging' => 50.0, 'it' => 75.0];
        $expected = (int) round($second->model()->probabilityOfPassing(['exam_type' => Exam::TYPE_BATTERY] + $scores) * 100);

        $this->assertSame(BatteryEarlyEstimate::STATUS_READY, $estimate['status']);
        $this->assertSame($expected, $estimate['percent_passing']);
        $this->assertSame(2, $estimate['model_version']);
        $this->assertSame('1st Semester, AY 2026-2027', $estimate['semester']['label']);
        $this->assertSame(3, $estimate['semester']['year_level']);
        $this->assertSame(LleTaxonomy::keys(), array_column($estimate['scores'], 'key'));
        $this->assertEquals(25, collect($estimate['scores'])->firstWhere('key', 'selection')['score']);
        $this->assertSame(BatteryEarlyEstimate::NOTE, $estimate['note']);
        $this->assertNull($estimate['pending']);

        // The student's own analytics never carry it.
        Sanctum::actingAs($student);
        $this->assertArrayNotHasKey('early_estimate', $this->getJson('/api/student/analytics')->assertOk()->json());

        // Back to version 1: off again.
        $second->forceFill(['status' => LetModel::STATUS_RETIRED])->save();
        LetModel::query()->where('version', 1)->update(['status' => LetModel::STATUS_ACTIVE]);
        $this->assertSame(BatteryEarlyEstimate::STATUS_OFF, $this->estimate($student)['status']);
    }

    public function test_a_finished_pre_test_or_mock_board_hands_over_to_the_estimated_outcome(): void
    {
        $this->batteryVersion(true);
        $student = $this->thirdYear();
        $this->makeBatterySitting($this->makeBatteryExam($this->teacher, $this->room, '2026-09-15'), $this->room, $student, array_fill_keys(LogisticModel::SUBJECTS, 3), '2026-09-15 10:00');

        $series = ExamSeries::create(['name' => '2026 Pre-test', 'type' => Exam::TYPE_PRE_TEST, 'created_by' => $this->teacher->id]);
        foreach (LogisticModel::SUBJECTS as $key) {
            $bank = $this->makeBank($this->teacher, 2, ['subject' => LleTaxonomy::subjectName($key)]);
            $exam = $this->makeExam($this->teacher, $bank, [$this->room], ['exam_type' => Exam::TYPE_PRE_TEST, 'exam_series_id' => $series->id, 'series_subject' => $key]);
            $this->makeSubmittedAttempt($exam, $this->room, $student, [true, false], Carbon::create(2026, 10, 1));
        }

        $this->assertSame(['status' => BatteryEarlyEstimate::STATUS_SUPERSEDED], $this->estimate($student));
    }

    public function test_a_semester_short_of_a_subject_says_what_it_needs(): void
    {
        $this->batteryVersion(true);
        $student = $this->thirdYear();
        $short = ['management', 'reference', 'indexing', 'selection', 'cataloging'];

        // Only an incomplete semester: nothing to read yet.
        $this->makeBatterySitting($this->makeBatteryExam($this->teacher, $this->room, '2026-09-15', $short), $this->room, $student, array_fill_keys($short, 2), '2026-09-15 10:00');

        $estimate = $this->estimate($student);
        $this->assertSame(BatteryEarlyEstimate::STATUS_LOCKED, $estimate['status']);
        $this->assertSame(['label' => '1st Semester, AY 2026-2027', 'year_level' => 3, 'missing' => ['Information Technology']], $estimate['pending']);

        // A complete semester from their 2nd year (January 2026 is the 2nd
        // semester of AY 2025-2026) is not read: the model learnt only from
        // the 3rd year on.
        $this->makeBatterySitting($this->makeBatteryExam($this->teacher, $this->room, '2026-01-20'), $this->room, $student, array_fill_keys(LogisticModel::SUBJECTS, 2), '2026-01-20 10:00');

        $this->assertSame(BatteryEarlyEstimate::STATUS_LOCKED, $this->estimate($student)['status']);
    }

    public function test_a_complete_semester_is_read_with_a_later_short_one_shown_beside_it(): void
    {
        $this->batteryVersion(true);
        $student = $this->thirdYear();
        $short = ['management', 'reference', 'indexing', 'selection', 'cataloging'];

        $this->makeBatterySitting($this->makeBatteryExam($this->teacher, $this->room, '2026-09-15'), $this->room, $student, array_fill_keys(LogisticModel::SUBJECTS, 2), '2026-09-15 10:00');
        $this->makeBatterySitting($this->makeBatteryExam($this->teacher, $this->room, '2027-01-20', $short), $this->room, $student, array_fill_keys($short, 4), '2027-01-20 10:00');

        $estimate = $this->estimate($student);

        $this->assertSame(BatteryEarlyEstimate::STATUS_READY, $estimate['status']);
        $this->assertSame('1st Semester, AY 2026-2027', $estimate['semester']['label']);
        $this->assertSame('2nd Semester, AY 2026-2027', $estimate['pending']['label']);
        $this->assertSame(['Information Technology'], $estimate['pending']['missing']);
    }

    public function test_battery_exams_before_the_3rd_year_or_without_an_admission_year_give_none(): void
    {
        $this->batteryVersion(true);
        $exam = $this->makeBatteryExam($this->teacher, $this->room, '2026-09-15');

        $firstYear = $this->makeStudent(['year_level' => 1, 'admission_year' => 2026]);
        $noYear = $this->makeStudent(['year_level' => 3, 'admission_year' => null]);

        foreach ([$firstYear, $noYear] as $student) {
            $this->makeBatterySitting($exam, $this->room, $student, array_fill_keys(LogisticModel::SUBJECTS, 4), '2026-09-15 10:00');
            $this->assertSame(['status' => BatteryEarlyEstimate::STATUS_NONE], $this->estimate($student));
        }
    }
}
