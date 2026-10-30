<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamSeries;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\BoardExamOutlook;
use App\Services\MachineLearning\LogisticModel;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The Estimated Outcome card: locked until a pre-test or mock board series is
 * complete, then the chance of passing from the most recently completed one,
 * by version 1 (loaded by the migrations), the same words for every student.
 */
class EstimatedOutcomeTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent(['year_level' => 2]);
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
    }

    /** One subject exam of a series (or a plain exam of another type). */
    private function subjectExam(string $key, string $type, ?ExamSeries $series): Exam
    {
        $bank = $this->makeBank($this->teacher, 2, ['subject' => LleTaxonomy::subjectName($key)]);

        return $this->makeExam($this->teacher, $bank, [$this->room], [
            'title' => ($series?->name ?? $type) . ': ' . $key,
            'exam_type' => $type,
            'exam_series_id' => $series?->id,
            'series_subject' => $series ? $key : null,
        ]);
    }

    private function sit(Exam $exam, float $score, Carbon $at, ?User $student = null): ExamAttempt
    {
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $student ?? $this->student, [true, false], $at);
        $attempt->forceFill(['score_percent' => $score])->save();

        return $attempt;
    }

    /**
     * A series with all six subject exams; the student sits $scores (by
     * subject key, in the model's order), one a day from $from.
     *
     * @param  array<string, float>  $scores
     * @return array{series: ExamSeries, exams: array<string, Exam>}
     */
    private function series(string $name, string $type, array $scores, Carbon $from): array
    {
        $series = ExamSeries::create(['name' => $name, 'type' => $type, 'created_by' => $this->teacher->id]);
        $exams = [];

        foreach (LogisticModel::SUBJECTS as $day => $key) {
            $exams[$key] = $this->subjectExam($key, $type, $series);
            if (array_key_exists($key, $scores)) {
                $this->sit($exams[$key], $scores[$key], $from->copy()->addDays($day));
            }
        }

        return ['series' => $series, 'exams' => $exams];
    }

    /** @return array<string, float> */
    private function allSix(float $score): array
    {
        return array_fill_keys(LogisticModel::SUBJECTS, $score);
    }

    private function outlook(): array
    {
        return app(BoardExamOutlook::class)->for($this->student);
    }

    public function test_it_is_locked_until_all_six_subjects_of_a_series_are_done_and_says_what_is_missing(): void
    {
        $this->assertSame(BoardExamOutlook::STATUS_LOCKED, $this->outlook()['status']);
        $this->assertSame('Your Estimated Outcome appears after you finish all six subjects of a pre-test or mock board.', $this->outlook()['message']);

        $scores = $this->allSix(60);
        unset($scores['it'], $scores['selection']);
        $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, $scores, Carbon::create(2026, 9, 1));

        $outlook = $this->outlook();
        $this->assertSame(BoardExamOutlook::STATUS_LOCKED, $outlook['status']);
        $this->assertSame([[
            'name' => '2026 Pre-test',
            'type_label' => 'Pre-test',
            'done' => 4,
            'required' => 6,
            'missing' => ['Information Technology', 'Selection and Acquisition'],
        ]], $outlook['in_progress']);
    }

    public function test_a_completed_series_gives_the_chance_of_passing_with_its_scores(): void
    {
        $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, $this->allSix(50), Carbon::create(2026, 9, 1));

        $outlook = $this->outlook();

        // WEKA gives 0.69959951679085470 for a pre-test at 50 in every subject.
        $this->assertSame(BoardExamOutlook::STATUS_READY, $outlook['status']);
        $this->assertSame(70, $outlook['percent_passing']);
        $this->assertSame(0.6996, $outlook['probability_passing']);
        // A chance of failing of 30% is at risk.
        $this->assertTrue($outlook['at_risk']);
        $this->assertSame('At risk', $outlook['label']);
        $this->assertSame('2026 Pre-test', $outlook['series']['name']);
        $this->assertSame('Pre-test', $outlook['series']['type_label']);
        $this->assertSame(Carbon::create(2026, 9, 6)->toIso8601String(), Carbon::parse($outlook['series']['completed_at'])->toIso8601String());
        $this->assertSame(LleTaxonomy::subjectNames(), array_column($outlook['scores'], 'subject'));
        $this->assertSame([50.0, 50.0, 50.0, 50.0, 50.0, 50.0], array_column($outlook['scores'], 'score'));
        $this->assertSame(BoardExamOutlook::NOTE, $outlook['note']);
        $this->assertSame(1, $outlook['model_version']);
        $this->assertArrayNotHasKey('risk_flags', $outlook);
    }

    public function test_strong_scores_are_not_at_risk_and_the_words_do_not_depend_on_year_level(): void
    {
        $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, [...$this->allSix(100), 'management' => 30, 'indexing' => 30, 'cataloging' => 30], Carbon::create(2026, 9, 1));
        $secondYear = $this->outlook();

        $this->student->forceFill(['year_level' => 4])->save();
        $fourthYear = app(BoardExamOutlook::class)->for($this->student->fresh());

        $this->assertFalse($secondYear['at_risk']);
        $this->assertSame('Not at risk', $secondYear['label']);
        $this->assertSame($secondYear, $fourthYear);
    }

    public function test_the_most_recently_completed_series_is_read_and_a_later_one_in_progress_is_shown(): void
    {
        $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, $this->allSix(50), Carbon::create(2026, 6, 1));
        $this->series('2026 Mock Board 1', Exam::TYPE_MOCK_BOARD, $this->allSix(50), Carbon::create(2026, 8, 1));
        $this->series('2026 Mock Board 2', Exam::TYPE_MOCK_BOARD, ['management' => 80], Carbon::create(2026, 9, 1));

        $outlook = $this->outlook();

        $this->assertSame('2026 Mock Board 1', $outlook['series']['name']);
        // WEKA: a mock board at 50 in every subject, 0.16716283420976788.
        $this->assertSame(17, $outlook['percent_passing']);
        $this->assertSame(['2026 Mock Board 2'], array_column($outlook['in_progress'], 'name'));
    }

    public function test_a_retaken_subject_counts_by_its_latest_sitting(): void
    {
        $built = $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, $this->allSix(50), Carbon::create(2026, 9, 1));
        $this->sit($built['exams']['it'], 90, Carbon::create(2026, 9, 20));

        $outlook = $this->outlook();

        $this->assertSame(90.0, collect($outlook['scores'])->firstWhere('key', 'it')['score']);
        $this->assertSame(Carbon::create(2026, 9, 20)->toIso8601String(), Carbon::parse($outlook['series']['completed_at'])->toIso8601String());
    }

    public function test_battery_and_regular_exams_never_feed_it(): void
    {
        foreach (LogisticModel::SUBJECTS as $day => $key) {
            $this->sit($this->subjectExam($key, Exam::TYPE_BATTERY, null), 90, Carbon::create(2026, 9, 1 + $day));
            $this->sit($this->subjectExam($key, Exam::TYPE_REGULAR, null), 90, Carbon::create(2026, 10, 1 + $day));
        }

        $this->assertSame(BoardExamOutlook::STATUS_LOCKED, $this->outlook()['status']);
        $this->assertSame([], $this->outlook()['in_progress']);
    }

    public function test_staff_see_the_same_card_on_the_student_s_profile_and_the_student_on_their_analytics(): void
    {
        $this->series('2026 Pre-test', Exam::TYPE_PRE_TEST, $this->allSix(50), Carbon::create(2026, 9, 1));

        Sanctum::actingAs($this->teacher);
        $staffView = $this->getJson("/api/students/{$this->student->id}/analytics")->assertOk()->json('predictive_model');

        Sanctum::actingAs($this->student);
        $ownView = $this->getJson('/api/student/analytics')->assertOk()->json('predictive_model');

        $this->assertSame($ownView, $staffView);
        $this->assertSame(70, $ownView['percent_passing']);
    }

    public function test_staff_can_work_out_an_outcome_from_typed_in_scores(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->postJson('/api/predictions/let', ['exam_type' => 'pre_test', ...$this->allSix(50)])
            ->assertOk()
            ->assertJsonPath('percent_passing', 70)
            ->assertJsonPath('label', 'At risk')
            ->assertJsonPath('model_version', 1);

        // A subject left out is filled with its training mean, never read as zero.
        $this->postJson('/api/predictions/let', ['exam_type' => 'mock_board'])
            ->assertOk()
            ->assertJsonPath('percent_passing', 63);

        $this->postJson('/api/predictions/let', ['exam_type' => 'battery'])->assertStatus(422);
    }
}
