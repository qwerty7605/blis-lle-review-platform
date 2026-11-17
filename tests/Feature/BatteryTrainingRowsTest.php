<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamSeries;
use App\Models\LetModel;
use App\Models\LleResult;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\BatterySemesterFeatureBuilder;
use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetTrainingSetBuilder;
use App\Services\MachineLearning\LogisticModel;
use App\Services\MachineLearning\LogisticRegressionTrainer;
use App\Services\MachineLearning\RidgeSelector;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Battery exams in the Estimated Outcome's training set: one row per
 * complete semester of a graduate's battery exams from their 3rd year on,
 * sat before their LLE, and how a version trained with them is judged (on
 * its review rows) and when its battery rows are trusted for early
 * estimates (on their own, with enough graduates who failed).
 */
class BatteryTrainingRowsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(LetBaseDataset::class, new LetBaseDataset(base_path('tests/Fixtures/lle_small.arff')));

        $this->teacher = $this->makeTeacher();
        $this->room = $this->makeRoom($this->teacher);
    }

    public function test_each_complete_semester_of_battery_exams_from_the_3rd_year_on_is_a_row(): void
    {
        // Admitted in 2022: AY 2024-2025 is the 3rd year, AY 2025-2026 the 4th.
        $graduate = $this->graduate(2022, LleResult::RESULT_FAIL, '2026-09-20');

        // 2nd year: most subjects not taught yet, so not a row.
        $this->sit($this->batteryExam('2023-09-05'), $graduate, $this->right(4), '2023-09-05 10:00');

        // 3rd year, 1st semester: 4 items a subject, 4/3/2/1/0/4 right.
        $this->sit($this->batteryExam('2024-09-05'), $graduate, [
            'management' => 4, 'reference' => 3, 'indexing' => 2, 'selection' => 1, 'cataloging' => 0, 'it' => 4,
        ], '2024-09-05 10:00');

        // 3rd year, 2nd semester: 2 right and 1 blank of 4 each; a blank is wrong.
        $this->sit($this->batteryExam('2025-02-05'), $graduate, $this->right(2), '2025-02-05 10:00', blank: 1);

        // 4th year, 1st semester: no Information Technology set, so incomplete.
        $this->sit($this->batteryExam('2025-09-05', array_slice(LogisticModel::SUBJECTS, 0, 5)), $graduate, $this->right(4), '2025-09-05 10:00');

        // 4th year, 2nd semester, split over two exams: one row. The first
        // exam was sat twice, and its latest sitting is the one that counts.
        $firstHalf = $this->batteryExam('2026-01-20', array_slice(LogisticModel::SUBJECTS, 0, 3));
        $this->sit($firstHalf, $graduate, $this->right(0), '2026-01-20 10:00');
        $this->sit($firstHalf, $graduate, $this->right(4), '2026-01-27 10:00');
        $this->sit($this->batteryExam('2026-02-10', array_slice(LogisticModel::SUBJECTS, 3)), $graduate, $this->right(2), '2026-02-10 10:00');

        // After the LLE: not what the result was earned on.
        $this->sit($this->batteryExam('2026-10-05'), $graduate, $this->right(4), '2026-10-06 10:00');

        // A regular exam covering every subject is not a battery exam.
        $this->sit($this->batteryExam('2025-03-01', type: Exam::TYPE_REGULAR), $graduate, $this->right(4), '2025-03-01 10:00');

        $set = app(LetTrainingSetBuilder::class)->build();
        $rows = collect($set['graduates'])->keyBy('key');

        $this->assertSame([
            "student:{$graduate->id}:battery:2024-1",
            "student:{$graduate->id}:battery:2024-2",
            "student:{$graduate->id}:battery:2025-2",
        ], $rows->keys()->all());

        $this->assertSame(
            ['exam_type' => 'battery', 'management' => 100.0, 'reference' => 75.0, 'indexing' => 50.0, 'selection' => 25.0, 'cataloging' => 0.0, 'it' => 100.0],
            $rows["student:{$graduate->id}:battery:2024-1"]['features'],
        );
        $this->assertSame(array_fill_keys(LogisticModel::SUBJECTS, 50.0), array_diff_key($rows["student:{$graduate->id}:battery:2024-2"]['features'], ['exam_type' => 1]));
        $this->assertSame(
            ['exam_type' => 'battery', 'management' => 100.0, 'reference' => 100.0, 'indexing' => 100.0, 'selection' => 50.0, 'cataloging' => 50.0, 'it' => 50.0],
            $rows["student:{$graduate->id}:battery:2025-2"]['features'],
        );
        $this->assertSame(LetPredictionService::RESULT_FAIL, $rows->first()['label']);

        $this->assertSame([['key' => "student:{$graduate->id}", 'label' => LetPredictionService::RESULT_FAIL, 'rows' => 3, 'battery_rows' => 3]], $set['results']);
        $this->assertSame(['rows' => 3, 'graduates' => 1, 'failed_graduates' => 1], $set['battery']);
        $this->assertSame(0, $set['skipped']);
    }

    public function test_a_semester_missing_a_subject_says_which_and_is_not_a_row(): void
    {
        $student = $this->makeStudent(['admission_year' => 2022]);
        $this->sit($this->batteryExam('2025-09-05', array_slice(LogisticModel::SUBJECTS, 0, 5)), $student, $this->right(3), '2025-09-05 10:00');

        $semesters = app(BatterySemesterFeatureBuilder::class)->semestersFor($student);

        $this->assertCount(1, $semesters);
        $this->assertSame(2025, $semesters[0]['school_year']);
        $this->assertSame(1, $semesters[0]['semester']);
        $this->assertSame(4, $semesters[0]['year_level']);
        $this->assertSame('1st Semester, AY 2025-2026', $semesters[0]['label']);
        $this->assertFalse($semesters[0]['complete']);
        $this->assertSame(['Information Technology'], $semesters[0]['missing']);
        $this->assertSame(75.0, $semesters[0]['scores']['management']);
        $this->assertNull($semesters[0]['scores']['it']);
    }

    public function test_no_battery_rows_without_an_admission_year_from_a_demo_account_or_after_the_first_sitting(): void
    {
        $exam = $this->batteryExam('2025-09-05');

        // No admission year: the year level then cannot be told.
        $unknown = $this->graduate(null, LleResult::RESULT_PASS, '2026-09-20');
        $this->sit($exam, $unknown, $this->right(3), '2025-09-05 10:00');

        // A demo account's result is invented.
        $demo = $this->graduate(2022, LleResult::RESULT_FAIL, '2026-09-20', ['is_demo' => true]);
        $this->sit($exam, $demo, $this->right(3), '2025-09-05 10:00');

        // A retake's battery exams count up to the first sitting only.
        $repeater = $this->graduate(2021, LleResult::RESULT_FAIL, '2025-09-21');
        LleResult::create(['user_id' => $repeater->id, 'exam_year' => 2026, 'taken_at' => '2026-09-20', 'result' => LleResult::RESULT_PASS]);
        $this->sit($exam, $repeater, $this->right(3), '2025-09-05 10:00');
        $this->sit($this->batteryExam('2026-02-05'), $repeater, $this->right(4), '2026-02-05 10:00');

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertSame(["student:{$repeater->id}:battery:2025-1"], array_column($set['graduates'], 'key'));
        $this->assertSame(LetPredictionService::RESULT_FAIL, $set['graduates'][0]['label']);
        $this->assertSame(1, $set['skipped'], 'the graduate with no admission year gave nothing');
        $this->assertSame(['rows' => 1, 'graduates' => 1, 'failed_graduates' => 1], $set['battery']);
    }

    public function test_the_licensure_page_counts_what_battery_exams_will_add(): void
    {
        $exam = $this->batteryExam('2025-09-05');
        foreach ([LleResult::RESULT_FAIL, LleResult::RESULT_FAIL, LleResult::RESULT_PASS] as $result) {
            $this->sit($exam, $this->graduate(2022, $result, '2026-09-20'), $this->right(2), '2025-09-05 10:00');
        }

        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/predictions/let/model')
            ->assertOk()
            ->assertJsonPath('data.battery_rows', 3)
            ->assertJsonPath('data.battery_graduates', 3)
            ->assertJsonPath('data.battery_failed_graduates', 2)
            ->assertJsonPath('data.skipped_graduates', 0)
            ->assertJsonPath('targets.battery_min_failed_graduates', LetModel::BATTERY_MIN_FAILED_GRADUATES)
            ->assertJsonPath('live.battery_ready', false)
            ->assertJsonPath('versions.0.battery', null);
    }

    public function test_a_version_trained_with_battery_rows_is_judged_on_its_review_rows_and_keeps_battery_estimates_off_until_ten_failed(): void
    {
        // Ten graduates with a pre-test series, three of them also with a
        // semester of battery exams.
        $preTest = $this->preTestSeries();
        $battery = $this->batteryExam('2025-09-05');

        foreach (range(1, 10) as $index) {
            $passed = $index % 3 !== 0;
            $graduate = $this->graduate(2022, $passed ? LleResult::RESULT_PASS : LleResult::RESULT_FAIL, '2026-09-20');

            foreach ($preTest as $exam) {
                $this->makeSubmittedAttempt($exam, $this->room, $graduate, [true, false], Carbon::parse('2026-03-01'))
                    ->forceFill(['score_percent' => $passed ? 70.0 + $index : 30.0 + $index])->save();
            }

            if ($index <= 3) {
                $this->sit($battery, $graduate, $this->right($passed ? 3 : 1), '2025-09-05 10:00');
            }
        }

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson('/api/predictions/let/model/retrain')->assertStatus(202);

        $version = LetModel::query()->where('version', 2)->sole();
        $this->assertSame(3, $version->battery_row_count);
        $this->assertSame(3, $version->battery_graduate_count);
        $this->assertSame(1, $version->battery_failed_graduate_count);
        $this->assertFalse($version->battery_ready);
        $this->assertNotNull($version->battery_roc_area);
        $this->assertFalse($version->battery_checks['battery_failed_graduates']['passed']);
        $this->assertStringContainsString('1 graduate(s) who sat battery exams failed the LLE, against the 10 needed', $version->battery_checks['battery_failed_graduates']['text']);

        // The model has an input per exam type, and its figures are the
        // review rows' (base rows and the pre-tests), not every row's.
        $this->assertSame(LogisticModel::ALL_TYPES, $version->model()->examTypes());
        $this->assertSame(LogisticModel::ALL_TYPES, $version->evaluation['exam_types']);
        $this->assertSame($version->training_count - 3, $version->evaluation['by_type']['review']['rows']);
        $this->assertSame(3, $version->evaluation['by_type']['battery']['rows']);
        $this->assertEqualsWithDelta($version->evaluation['by_type']['review']['model']['roc_area'], $version->roc_area, 1e-12);

        // The run's message is longer than an audit description can hold
        // (255 on MySQL): the entry keeps it whole in its metadata.
        $entry = AuditLog::query()->where('action', 'prediction.let.retrain')->latest('id')->sole();
        $this->assertLessThanOrEqual(255, mb_strlen((string) $entry->description));
        $this->assertGreaterThan(255, mb_strlen($entry->metadata['message']));
        $this->assertStringContainsString('not yet good enough for early estimates', $entry->metadata['message']);

        $listed = $this->getJson('/api/predictions/let/model')->assertOk()->json('versions.0');
        $this->assertSame(2, $listed['version']);
        $this->assertSame(3, $listed['battery']['rows']);
        $this->assertFalse($listed['battery']['ready']);
        $this->assertNotEmpty($listed['battery']['failed_checks']);
    }

    public function test_battery_estimates_need_every_battery_check_and_never_decide_whether_a_version_is_used(): void
    {
        // Twelve graduates sat one battery exam, ten of whom failed the LLE.
        $battery = $this->batteryExam('2025-09-05');
        foreach (range(1, 12) as $index) {
            $failed = $index <= 10;
            $this->sit($battery, $this->graduate(2022, $failed ? LleResult::RESULT_FAIL : LleResult::RESULT_PASS, '2026-09-20'), $this->right($failed ? 1 : 4), '2025-09-05 10:00');
        }

        // Each version's review ROC has to be at least the one in use
        // (version 1: 0.863), so each run is a little higher.
        $good = ['roc_area' => 0.90, 'failed_recall' => 0.71, 'kappa' => 0.35];
        $this->judgeWith(review: $good, battery: $good);
        $ready = $this->retrain();
        $this->assertSame(LetModel::STATUS_ACTIVE, $ready->status);
        $this->assertTrue($ready->battery_ready);
        $this->assertSame(10, $ready->battery_failed_graduate_count);
        $this->assertEqualsWithDelta(0.90, $ready->roc_area, 1e-12);
        $this->assertEqualsWithDelta(0.90, $ready->battery_roc_area, 1e-12);

        // Battery rows that miss a target keep battery estimates off, and the
        // version is still taken up on its review rows.
        $this->judgeWith(review: ['roc_area' => 0.91] + $good, battery: ['failed_recall' => 0.5] + $good);
        $lowRecall = $this->retrain(newResults: true);
        $this->assertSame(LetModel::STATUS_ACTIVE, $lowRecall->status);
        $this->assertFalse($lowRecall->battery_ready);
        $this->assertFalse($lowRecall->battery_checks['battery_failed_recall_target']['passed']);
        $this->assertTrue($lowRecall->battery_checks['battery_roc_area_target']['passed']);

        // Review rows that miss a target keep the version out of use, however
        // good its battery rows are.
        $this->judgeWith(review: ['roc_area' => 0.92, 'failed_recall' => 0.4] + $good, battery: $good);
        $badReview = $this->retrain(newResults: true);
        $this->assertSame(LetModel::STATUS_REJECTED, $badReview->status);
        $this->assertFalse($badReview->checks['failed_recall_target']['passed']);
        $this->assertSame(LetModel::STATUS_ACTIVE, LetModel::query()->where('version', $lowRecall->version)->value('status'));
    }

    /**
     * Stands in for the cross-validation, which is checked against WEKA on
     * its own: these figures for the review rows and the battery rows.
     *
     * @param  array<string, float>  $review
     * @param  array<string, float>  $battery
     */
    private function judgeWith(array $review, array $battery): void
    {
        $figures = static fn (array $values): array => $values + [
            'false_alarm_rate' => 0.2,
            'failed_precision' => 0.5,
            'failed_f_measure' => 0.6,
            'accuracy' => 0.8,
            'confusion' => ['failed_at_risk' => 7.0, 'failed_not_at_risk' => 3.0, 'passed_at_risk' => 4.0, 'passed_not_at_risk' => 16.0],
        ];
        $zeroR = $figures(['roc_area' => 0.5, 'failed_recall' => 0.0, 'kappa' => 0.0]);

        $this->app->instance(LetModelEvaluator::class, new class(app(RidgeSelector::class), app(LogisticRegressionTrainer::class), $figures($review), $figures($battery), $zeroR) extends LetModelEvaluator
        {
            public function __construct(RidgeSelector $selector, LogisticRegressionTrainer $trainer, private array $review, private array $battery, private array $zeroR)
            {
                parent::__construct($selector, $trainer);
            }

            public function evaluate(array $rows, ?array $examTypes = null): array
            {
                return [
                    'rows' => count($rows),
                    'failed_rows' => count(array_filter($rows, static fn (array $row): bool => $row['label'] === LetPredictionService::RESULT_FAIL)),
                    'cutoff_p_failed' => LogisticModel::AT_RISK_CUTOFF,
                    'folds' => 10,
                    'repeats' => 10,
                    'exam_types' => $examTypes,
                    'model' => $this->review,
                    'zero_r' => $this->zeroR,
                    'per_repeat' => [],
                    'by_type' => [
                        'review' => ['rows' => 1, 'failed_rows' => 1, 'model' => $this->review, 'zero_r' => $this->zeroR],
                        'battery' => ['rows' => 1, 'failed_rows' => 1, 'model' => $this->battery, 'zero_r' => $this->zeroR],
                    ],
                ];
            }
        });
        $this->app->forgetInstance(LetModelTrainer::class);
    }

    /** A retraining run by an administrator; with $newResults, ten more graduates first, so it is allowed again. */
    private function retrain(bool $newResults = false): LetModel
    {
        if ($newResults) {
            $battery = $this->batteryExam('2025-09-06');
            foreach (range(1, 10) as $index) {
                $this->sit($battery, $this->graduate(2022, $index % 2 ? LleResult::RESULT_FAIL : LleResult::RESULT_PASS, '2026-09-20'), $this->right(2), '2025-09-06 10:00');
            }
        }

        $outcome = app(LetModelTrainer::class)->run($this->makeAdmin());
        $this->assertNotNull($outcome['model'], $outcome['message']);

        return $outcome['model']->refresh();
    }

    /** A graduate with their first LLE sitting on $lleDate. */
    private function graduate(?int $admissionYear, string $result, string $lleDate, array $attributes = []): User
    {
        $student = $this->makeStudent(['student_status' => User::STUDENT_STATUS_GRADUATED, 'admission_year' => $admissionYear, ...$attributes]);
        $this->room->members()->syncWithoutDetaching([$student->id]);
        LleResult::create(['user_id' => $student->id, 'exam_year' => (int) substr($lleDate, 0, 4), 'taken_at' => $lleDate, 'result' => $result]);

        return $student;
    }

    /** @param  list<string>  $subjects */
    private function batteryExam(string $start, array $subjects = LogisticModel::SUBJECTS, int $items = 4, string $type = Exam::TYPE_BATTERY): Exam
    {
        return $this->makeBatteryExam($this->teacher, $this->room, $start, $subjects, $items, $type);
    }

    /** @param  array<string, int>  $right */
    private function sit(Exam $exam, User $student, array $right, string $at, int $blank = 0): ExamAttempt
    {
        return $this->makeBatterySitting($exam, $this->room, $student, $right, $at, $blank);
    }

    /** @return array<string, int> the same number right in every subject */
    private function right(int $count): array
    {
        return array_fill_keys(LogisticModel::SUBJECTS, $count);
    }

    /** @return array<string, Exam> a pre-test series, one exam per subject */
    private function preTestSeries(): array
    {
        $series = ExamSeries::create(['name' => '2026 Pre-test', 'type' => Exam::TYPE_PRE_TEST, 'created_by' => $this->teacher->id]);
        $exams = [];

        foreach (LogisticModel::SUBJECTS as $key) {
            $bank = $this->makeBank($this->teacher, 2, ['subject' => LleTaxonomy::subjectName($key)]);
            $exams[$key] = $this->makeExam($this->teacher, $bank, [$this->room], [
                'exam_type' => Exam::TYPE_PRE_TEST,
                'exam_series_id' => $series->id,
                'series_subject' => $key,
            ]);
        }

        return $exams;
    }
}
