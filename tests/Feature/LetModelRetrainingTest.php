<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamSeries;
use App\Models\LetModel;
use App\Models\LleResult;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetTrainingSetBuilder;
use App\Services\MachineLearning\LogisticModel;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Retraining the Estimated Outcome model: only on an administrator's click,
 * only with ten confirmed LLE results the last training did not have, on the
 * base dataset plus each graduate's pre-tests and mock boards completed
 * before their LLE date, and taken up only when it meets both targets, beats
 * ZeroR on Kappa and holds the live version's ROC area.
 *
 * The base dataset here is the first 64 rows of the real one
 * (tests/Fixtures/lle_small.arff), to keep each training to a few seconds.
 */
class LetModelRetrainingTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private Room $room;

    /** @var array<string, Exam> */
    private array $preTest = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(LetBaseDataset::class, new LetBaseDataset(base_path('tests/Fixtures/lle_small.arff')));

        $this->admin = $this->makeAdmin();
        $this->teacher = $this->makeTeacher();
        $this->room = $this->makeRoom($this->teacher);
        $this->preTest = $this->seriesExams('2025 Pre-test', Exam::TYPE_PRE_TEST);
    }

    /** @return array<string, Exam> */
    private function seriesExams(string $name, string $type): array
    {
        $series = ExamSeries::create(['name' => $name, 'type' => $type, 'created_by' => $this->teacher->id]);
        $exams = [];

        foreach (LogisticModel::SUBJECTS as $key) {
            $bank = $this->makeBank($this->teacher, 2, ['subject' => LleTaxonomy::subjectName($key)]);
            $exams[$key] = $this->makeExam($this->teacher, $bank, [$this->room], [
                'exam_type' => $type,
                'exam_series_id' => $type === Exam::TYPE_PRE_TEST || $type === Exam::TYPE_MOCK_BOARD ? $series->id : null,
                'series_subject' => $key,
            ]);
        }

        return $exams;
    }

    /**
     * A graduate who sat every exam of $exams at $score on $sat and got
     * $result in the LLE on $lleDate.
     *
     * @param  array<string, Exam>  $exams
     */
    private function graduate(array $exams, float $score, string $result, Carbon $sat, Carbon $lleDate, array $attributes = []): User
    {
        $student = $this->makeStudent(['student_status' => User::STUDENT_STATUS_GRADUATED, ...$attributes]);
        $this->room->members()->syncWithoutDetaching([$student->id]);

        foreach ($exams as $exam) {
            $this->makeSubmittedAttempt($exam, $this->room, $student, [true, false], $sat)->forceFill(['score_percent' => $score])->save();
        }

        LleResult::create(['user_id' => $student->id, 'exam_year' => $lleDate->year, 'taken_at' => $lleDate->toDateString(), 'result' => $result]);

        return $student;
    }

    /** Ten graduates whose pre-test scores match their results. */
    private function tenGraduates(): void
    {
        foreach (range(1, 10) as $index) {
            $passed = $index % 3 !== 0;
            $this->graduate($this->preTest, $passed ? 70.0 + $index : 30.0 + $index, $passed ? LleResult::RESULT_PASS : LleResult::RESULT_FAIL, Carbon::create(2025, 9, 1), Carbon::create(2026, 3, 15));
        }
    }

    public function test_version_one_from_weka_is_in_use_after_the_migrations(): void
    {
        $live = (new LetPredictionService())->liveModel();

        $this->assertSame(1, $live['version']);
        $this->assertSame('weka', $live['source']);
        $this->assertSame(0.25, $live['cutoff']);
        $this->assertSame(0.5, $live['model']->ridge);

        $versionOne = LetModel::query()->where('version', 1)->sole();
        $this->assertSame(128, $versionOne->training_count);
        $this->assertTrue($versionOne->meets_targets);
        $this->assertEqualsWithDelta(0.8631, $versionOne->roc_area, 1e-4);
        $this->assertCount(20, $versionOne->selection['candidates']);
    }

    public function test_retraining_is_blocked_with_fewer_than_ten_new_results(): void
    {
        foreach (range(1, 9) as $index) {
            $this->graduate($this->preTest, 70, LleResult::RESULT_PASS, Carbon::create(2025, 9, 1), Carbon::create(2026, 3, 15));
        }

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/predictions/let/model/retrain')
            ->assertStatus(422)
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_NOT_ENOUGH_NEW_RESULTS)
            ->assertJsonPath('message', 'Only 9 new confirmed LLE result(s) since the last training. At least 10 are needed before retraining.');

        $this->assertSame(1, LetModel::count());
    }

    public function test_ten_new_results_retrain_and_a_version_that_holds_up_is_taken_up(): void
    {
        $this->tenGraduates();
        // The live version's ROC area comes from its own rows; lower it so the
        // new one only has to meet the targets and beat ZeroR.
        LetModel::query()->where('version', 1)->update(['roc_area' => 0.5]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/predictions/let/model/retrain')->assertStatus(202)->assertJsonPath('outcome', LetModelTrainer::OUTCOME_QUEUED);

        $version = LetModel::query()->where('version', 2)->sole();
        $this->assertSame(LetModel::STATUS_ACTIVE, $version->status, json_encode($version->checks));
        $this->assertSame(74, $version->training_count);
        $this->assertSame(64, $version->imported_count);
        $this->assertSame(10, $version->graduate_count);
        $this->assertSame(LetModel::SOURCE_RETRAINED, $version->source);
        $this->assertSame(0.25, $version->at_risk_cutoff);
        $this->assertCount(10, $version->evaluation['per_repeat']);
        $this->assertTrue(collect($version->checks)->every(fn (array $check): bool => $check['passed']));
        Storage::disk('local')->assertExists($version->file_path);

        $this->assertSame(LetModel::STATUS_RETIRED, LetModel::query()->where('version', 1)->value('status'));
        $this->assertSame(2, (new LetPredictionService())->liveModel()['version']);
        $this->assertFalse(Cache::has(LetModelTrainer::RUNNING_KEY));
        $this->assertTrue(AuditLog::where('action', 'prediction.let.retrain')->where('metadata->outcome', LetModelTrainer::OUTCOME_PROMOTED)->exists());

        // Nothing new since: the button is off again.
        $this->getJson('/api/predictions/let/model')->assertOk()->assertJsonPath('can_retrain', false)->assertJsonPath('data.new_since_last_training', 0);
    }

    public function test_a_version_whose_roc_area_falls_below_the_live_one_is_kept_out_with_the_reason(): void
    {
        $this->tenGraduates();
        LetModel::query()->where('version', 1)->update(['roc_area' => 0.99]);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/predictions/let/model/retrain')->assertStatus(202);

        $version = LetModel::query()->where('version', 2)->sole();
        $this->assertSame(LetModel::STATUS_REJECTED, $version->status);
        $this->assertFalse($version->checks['roc_area_vs_live']['passed']);
        $this->assertStringContainsString("against version 1's 0.990", $version->checks['roc_area_vs_live']['text']);
        $this->assertSame(1, (new LetPredictionService())->liveModel()['version']);

        $status = $this->getJson('/api/predictions/let/model')->assertOk()->json();
        $listed = collect($status['versions'])->keyBy('version');
        // Side by side, each with its own figures.
        $this->assertSame([2, 1], array_column($status['versions'], 'version'));
        $this->assertSame(['Not taken up', true], [$listed[2]['status'] === 'rejected' ? 'Not taken up' : '', $listed[1]['active']]);
        $this->assertNotEmpty($listed[2]['failed_checks']);

        // It meets both targets, so the administrator may still choose it.
        $this->assertTrue($listed[2]['can_activate']);
        $this->postJson('/api/predictions/let/model/activate', ['version' => 2])->assertOk();
        $this->assertSame(2, (new LetPredictionService())->liveModel()['version']);
        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])->assertOk();
        $this->assertSame(1, (new LetPredictionService())->liveModel()['version']);
    }

    public function test_a_version_that_misses_a_target_cannot_be_put_into_use(): void
    {
        $missing = LetModel::query()->where('version', 1)->first()->replicate();
        $missing->forceFill(['version' => 2, 'status' => LetModel::STATUS_REJECTED, 'failed_recall' => 0.4, 'meets_targets' => false, 'activated_at' => null])->save();

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/predictions/let/model/activate', ['version' => 2])->assertStatus(422);
        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])->assertStatus(422); // already in use
        $this->postJson('/api/predictions/let/model/activate', ['version' => 9])->assertStatus(422);
    }

    public function test_only_an_administrator_retrains_or_changes_the_version_and_one_run_at_a_time(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/predictions/let/model')->assertOk();
        $this->postJson('/api/predictions/let/model/retrain')->assertForbidden();
        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])->assertForbidden();

        $this->tenGraduates();
        Cache::put(LetModelTrainer::RUNNING_KEY, now()->toIso8601String(), 60);
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/predictions/let/model/retrain')
            ->assertStatus(422)
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_ALREADY_RUNNING)
            ->assertJsonPath('training', true);
        $this->assertSame(1, LetModel::count());
    }

    public function test_recording_results_never_retrains_by_itself(): void
    {
        $this->tenGraduates();

        $this->assertSame(1, LetModel::count());
        $this->assertTrue(app(LetModelTrainer::class)->status()['can_retrain']);
    }

    public function test_training_rows_are_each_graduate_s_series_completed_before_the_lle(): void
    {
        $mockBoard = $this->seriesExams('2025 Mock Board 1', Exam::TYPE_MOCK_BOARD);
        $battery = $this->seriesExams('Battery', Exam::TYPE_BATTERY);

        // Two series before the LLE: two rows, each with its exam type.
        $two = $this->graduate($this->preTest, 60, LleResult::RESULT_PASS, Carbon::create(2025, 9, 1), Carbon::create(2026, 3, 15));
        foreach ($mockBoard as $exam) {
            $this->makeSubmittedAttempt($exam, $this->room, $two, [true, true], Carbon::create(2026, 2, 1))->forceFill(['score_percent' => 80])->save();
        }
        // A retake after the LLE is not what the result was earned on.
        $this->makeSubmittedAttempt($this->preTest['it'], $this->room, $two, [true, true], Carbon::create(2026, 5, 1))->forceFill(['score_percent' => 99])->save();
        // Battery exams give rows only from the 3rd year on, which needs an
        // admission year; this graduate has none on file (battery rows have
        // their own tests, BatteryTrainingRowsTest).
        foreach ($battery as $exam) {
            $this->makeSubmittedAttempt($exam, $this->room, $two, [true, true], Carbon::create(2025, 12, 1));
        }

        // A series finished only after the LLE gives no row.
        $late = $this->graduate($this->preTest, 40, LleResult::RESULT_FAIL, Carbon::create(2026, 4, 1), Carbon::create(2026, 3, 15));
        // Demo accounts carry invented results.
        $this->graduate($this->preTest, 50, LleResult::RESULT_FAIL, Carbon::create(2025, 9, 1), Carbon::create(2026, 3, 15), ['is_demo' => true]);

        $set = app(LetTrainingSetBuilder::class)->build();
        $rows = collect($set['graduates'])->keyBy('key');

        $this->assertCount(64, $set['base']);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $set['skipped']);
        $this->assertSame([['key' => 'student:' . $two->id, 'label' => LetPredictionService::RESULT_PASS, 'rows' => 2, 'battery_rows' => 0]], $set['results']);
        $this->assertSame(['rows' => 0, 'graduates' => 0, 'failed_graduates' => 0], $set['battery']);

        $preTestRow = $rows->first(fn (array $row): bool => $row['features']['exam_type'] === 'pre_test');
        $mockRow = $rows->first(fn (array $row): bool => $row['features']['exam_type'] === 'mock_board');
        $this->assertSame(60.0, $preTestRow['features']['it']);
        $this->assertSame(80.0, $mockRow['features']['management']);
        $this->assertNotContains('student:' . $late->id, array_column($set['results'], 'key'));
    }

    public function test_did_not_take_is_never_a_training_label(): void
    {
        // Did not sit in 2026, then failed in 2027: the 2027 fail is their
        // first sitting, and their series before it is the row.
        $retaker = $this->graduate($this->preTest, 45, LleResult::RESULT_FAIL, Carbon::create(2025, 9, 1), Carbon::create(2027, 3, 14));
        LleResult::create(['user_id' => $retaker->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_DID_NOT_TAKE]);

        // Never sat at all: no row, and not counted as left out either.
        $absent = $this->graduate($this->preTest, 55, LleResult::RESULT_PASS, Carbon::create(2025, 9, 1), Carbon::create(2026, 3, 15));
        LleResult::query()->where('user_id', $absent->id)->update(['result' => LleResult::RESULT_DID_NOT_TAKE, 'taken_at' => null]);

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertSame([['key' => 'student:' . $retaker->id, 'label' => LetPredictionService::RESULT_FAIL, 'rows' => 1, 'battery_rows' => 0]], $set['results']);
        $this->assertSame(0, $set['skipped']);
        $this->assertCount(1, $set['graduates']);
    }
}
