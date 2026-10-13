<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\BoardExamOutlook;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a student is shown from the board exam model: nothing until one
 * semester has all six subject exams, then an early warning in years 1 to 3
 * and a board readiness prediction in year 4, always from the latest semester
 * that is complete.
 */
class BoardExamOutlookTest extends TestCase
{
    use RefreshDatabase;

    private const ALL_75 = [
        'reference' => 75, 'management' => 76, 'indexing' => 74,
        'selection' => 75, 'cataloging' => 77, 'it' => 73,
    ];

    private User $staff;

    private Room $room;

    /** @var array<string, Exam> */
    private array $exams = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $this->room = Room::create(['name' => 'BLIS 4A', 'code' => 'LLE4A1', 'created_by' => $this->staff->id]);
    }

    public function test_five_of_the_six_exams_keeps_it_locked(): void
    {
        $student = $this->student(2);
        $this->semester($student, 2026, 1, ['it' => null] + self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('locked', $model['status']);
        $this->assertSame('Complete all 6 subject exams this semester to unlock your result.', $model['message']);
        $this->assertSame(5, $model['progress']['completed']);
        $this->assertSame(['Information Technology'], $model['progress']['missing']);
        $this->assertArrayNotHasKey('label', $model);
        $this->assertArrayNotHasKey('outcome', $model);
    }

    public function test_a_second_year_with_selection_at_40_is_at_risk_in_early_warning_mode(): void
    {
        $student = $this->student(2);
        $this->semester($student, 2026, 1, ['selection' => 40, 'indexing' => 61] + self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('ready', $model['status']);
        $this->assertSame('early_warning', $model['mode']);
        $this->assertSame('At risk', $model['label']);
        $this->assertSame('Early warning only. Based on your current semester scores, not a board exam prediction.', $model['note']);

        // Selection is the subject the model treats as the risk indicator.
        $this->assertSame(['selection'], array_column($model['risk_flags'], 'key'));
        $this->assertSame('selection ≤ 43', $model['risk_flags'][0]['rule']);

        // The two lowest subjects, lowest first.
        $this->assertSame(['selection', 'indexing'], array_column($model['focus_areas'], 'key'));

        // Years 1 to 3 are never told passed or failed, in any spelling.
        $this->assertStringNotContainsStringIgnoringCase('pass', (string) json_encode($model));
        $this->assertStringNotContainsStringIgnoringCase('fail', (string) json_encode($model));
    }

    public function test_a_first_year_doing_well_is_on_track_not_passed(): void
    {
        $student = $this->student(1);
        $this->semester($student, 2026, 1, self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('On track', $model['label']);
        $this->assertSame('early_warning', $model['mode']);
        $this->assertSame([], $model['risk_flags']);
        $this->assertCount(2, $model['focus_areas']);
    }

    public function test_a_fourth_year_with_scores_around_75_is_likely_to_pass(): void
    {
        $student = $this->student(4);
        $this->semester($student, 2026, 1, self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('board_readiness', $model['mode']);
        $this->assertSame('Likely to pass', $model['label']);
        $this->assertSame('This prediction is based on past review-exam data and is a guide, not a guarantee.', $model['note']);
        $this->assertSame([], $model['risk_flags']);
        $this->assertSame('1st Semester, AY 2026-2027', $model['semester']['label']);
    }

    public function test_a_fourth_year_with_a_weak_it_score_is_at_risk_of_failing(): void
    {
        $student = $this->student(4);
        $this->semester($student, 2026, 1, ['it' => 30] + self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('At risk of failing', $model['label']);
        $this->assertSame(['it'], array_column($model['risk_flags'], 'key'));
        $this->assertSame('it ≤ 32', $model['risk_flags'][0]['rule']);
    }

    public function test_the_latest_complete_semester_is_used_when_an_earlier_one_has_a_subject_missing(): void
    {
        $student = $this->student(3);
        // Last school year's second semester: no Cataloging exam.
        $this->semester($student, 2025, 2, ['cataloging' => null, 'selection' => 20] + self::ALL_75);
        // This semester: all six.
        $this->semester($student, 2026, 1, ['reference' => 88] + self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('ready', $model['status']);
        $this->assertSame('1st Semester, AY 2026-2027', $model['semester']['label']);
        $this->assertSame(88.0, (float) collect($model['scores'])->firstWhere('key', 'reference')['score']);
        // The weak Selection score belongs to the incomplete semester.
        $this->assertSame('On track', $model['label']);
    }

    public function test_an_incomplete_semester_is_never_shown_while_an_earlier_complete_one_is(): void
    {
        $student = $this->student(3);
        $this->semester($student, 2025, 2, self::ALL_75);
        // The semester in progress: three exams so far, one of them poor.
        $this->semester($student, 2026, 1, ['selection' => 10, 'reference' => 70, 'it' => 70]);

        $model = $this->outlook($student);

        $this->assertSame('2nd Semester, AY 2025-2026', $model['semester']['label']);
        $this->assertSame('On track', $model['label']);
    }

    public function test_a_transferee_in_fourth_year_unlocks_with_one_complete_semester_here(): void
    {
        // Joined this school year, so everything before it was sat elsewhere
        // and is not in this system.
        $student = $this->student(4, ['admission_year' => 2026]);
        $this->semester($student, 2026, 1, self::ALL_75);

        $model = $this->outlook($student);

        $this->assertSame('ready', $model['status']);
        $this->assertSame('board_readiness', $model['mode']);
        $this->assertSame('Likely to pass', $model['label']);
    }

    public function test_a_subject_with_two_exams_in_a_semester_is_averaged_and_a_retake_counts_once(): void
    {
        $student = $this->student(4);
        $this->semester($student, 2026, 1, self::ALL_75);

        // A second Selection exam the same semester...
        $second = $this->exam('selection', 2026, 1, 'Quiz');
        $this->sit($student, $second, 35, Carbon::parse('2026-10-01'));
        // ...sat again later for a better score. Only the retake counts.
        $this->sit($student, $second, 55, Carbon::parse('2026-10-08'));

        $model = $this->outlook($student);

        $this->assertSame(65.0, (float) collect($model['scores'])->firstWhere('key', 'selection')['score']);
    }

    public function test_overall_is_weighted_and_leaves_out_a_subject_with_no_score(): void
    {
        $this->assertSame(
            round(0.20 * 80 + 0.20 * 70 + 0.15 * 60 + 0.15 * 50 + 0.20 * 90 + 0.10 * 40, 2),
            LetReadinessFeatureBuilder::overall(['reference' => 80, 'management' => 70, 'indexing' => 60, 'selection' => 50, 'cataloging' => 90, 'it' => 40]),
        );

        // Without IT the other five weights add up to 0.90, and the sum is
        // divided by that, not by 1.
        $features = LetReadinessFeatureBuilder::features(['reference' => 80, 'management' => 70, 'indexing' => 60, 'selection' => 50, 'cataloging' => 90]);

        $this->assertNull($features['it']);
        $this->assertSame(round((0.20 * 80 + 0.20 * 70 + 0.15 * 60 + 0.15 * 50 + 0.20 * 90) / 0.90, 2), $features['overall']);
    }

    public function test_staff_see_the_same_result_on_the_students_profile(): void
    {
        $student = $this->student(2);
        $this->semester($student, 2026, 1, ['selection' => 40] + self::ALL_75);

        Sanctum::actingAs($this->staff);

        $this->getJson("/api/students/{$student->id}/analytics")
            ->assertOk()
            ->assertJsonPath('predictive_model.mode', 'early_warning')
            ->assertJsonPath('predictive_model.label', 'At risk')
            ->assertJsonPath('predictive_model.risk_flags.0.key', 'selection');
    }

    public function test_an_exam_takes_its_school_year_and_semester_from_its_date_unless_they_are_given(): void
    {
        $this->assertSame(['school_year' => 2026, 'semester' => 1], Exam::termForDate(Carbon::parse('2026-08-03')));
        $this->assertSame(['school_year' => 2026, 'semester' => 1], Exam::termForDate(Carbon::parse('2026-12-18')));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], Exam::termForDate(Carbon::parse('2027-01-11')));
        $this->assertSame(['school_year' => 2026, 'semester' => 2], Exam::termForDate(Carbon::parse('2027-07-30')));

        $byDate = Exam::create($this->examAttributes('By date') + ['schedule_start_at' => '2027-03-01 08:00:00']);
        $this->assertSame([2026, 2], [$byDate->school_year, $byDate->semester]);

        // A make-up exam held in January for the first semester.
        $given = Exam::create($this->examAttributes('Make-up') + ['schedule_start_at' => '2027-01-12 08:00:00', 'school_year' => 2026, 'semester' => 1]);
        $this->assertSame([2026, 1], [$given->fresh()->school_year, $given->fresh()->semester]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outlook(User $student): array
    {
        Sanctum::actingAs($student);

        return $this->getJson('/api/student/analytics')->assertOk()->json('predictive_model');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function student(int $yearLevel, array $attributes = []): User
    {
        return User::factory()->create(['role' => User::ROLE_STUDENT, 'year_level' => $yearLevel] + $attributes);
    }

    /**
     * One exam in each subject given a score, in that school year and semester.
     *
     * @param  array<string, int|null>  $scores  keyed like the model's subjects; null or absent = not sat
     */
    private function semester(User $student, int $schoolYear, int $semester, array $scores): void
    {
        $when = $semester === 1 ? Carbon::create($schoolYear, 9, 15) : Carbon::create($schoolYear + 1, 2, 15);

        foreach (array_keys(LetReadinessFeatureBuilder::SUBJECTS) as $index => $key) {
            if (($scores[$key] ?? null) !== null) {
                $this->sit($student, $this->exam($key, $schoolYear, $semester), $scores[$key], $when->copy()->addDays($index));
            }
        }
    }

    private function exam(string $key, int $schoolYear, int $semester, string $kind = 'Exam'): Exam
    {
        return $this->exams["{$key}:{$schoolYear}:{$semester}:{$kind}"] ??= Exam::create(
            $this->examAttributes(LetReadinessFeatureBuilder::SUBJECTS[$key] . " {$kind}")
            + ['subject' => LetReadinessFeatureBuilder::SUBJECTS[$key], 'school_year' => $schoolYear, 'semester' => $semester],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function examAttributes(string $title): array
    {
        return [
            'title' => $title,
            'total_items' => 100,
            'duration_minutes' => 60,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $this->staff->id,
        ];
    }

    private function sit(User $student, Exam $exam, int $score, Carbon $when): void
    {
        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $this->room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 100,
            'duration_minutes' => 60,
            'answered_count' => 100,
            'correct_answers' => $score,
            'score_percent' => $score,
            'started_at' => $when->copy()->subHour(),
            'submitted_at' => $when,
        ]);
    }
}
