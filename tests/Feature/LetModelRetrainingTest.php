<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LetModel;
use App\Models\LleResult;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use App\Services\MachineLearning\LetTrainingSetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The board exam model learns from real results, on request. An
 * administrator retrains it once ten new confirmed results are in; the new
 * tree is trained on the base dataset plus those graduates, judged by 10-fold
 * cross-validation, and taken up only if it is no worse than always answering
 * PASSED and no worse than the model in use. Every version is kept.
 */
class LetModelRetrainingTest extends TestCase
{
    use RefreshDatabase;

    private const SCORES = [
        'reference' => 75, 'management' => 76, 'indexing' => 74,
        'selection' => 75, 'cataloging' => 77, 'it' => 73,
    ];

    private User $admin;

    private User $staff;

    private Room $room;

    private string $basePath;

    /** @var array<string, Exam> */
    private array $exams = [];

    private int $graduatesMade = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $this->room = Room::create(['name' => 'BLIS 4A', 'code' => 'LLE4A1', 'created_by' => $this->staff->id]);

        $this->useBaseDataset($this->baseDataset());
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath);

        parent::tearDown();
    }

    public function test_retraining_is_blocked_with_fewer_than_ten_new_results(): void
    {
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(4, ['selection' => 30], LleResult::RESULT_FAIL);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/predictions/let/model')
            ->assertOk()
            ->assertJsonPath('can_retrain', false)
            ->assertJsonPath('data.new_since_last_training', 9)
            ->assertJsonPath('data.minimum_new_results', 10);

        $this->postJson('/api/predictions/let/model/retrain')
            ->assertStatus(422)
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_NOT_ENOUGH_NEW_RESULTS)
            ->assertJsonPath('message', 'Only 9 new confirmed board result(s) since the last training. At least 10 are needed before retraining.');

        $this->assertDatabaseCount('let_models', 0);
        $this->assertSame('built_in', (new LetPredictionService())->liveModel()['source']);
    }

    public function test_ten_new_results_retrain_the_model_and_one_that_holds_up_is_taken_up(): void
    {
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(5, ['selection' => 30], LleResult::RESULT_FAIL);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/predictions/let/model/retrain')
            ->assertOk()
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_PROMOTED)
            ->assertJsonPath('live.source', 'retrained')
            ->assertJsonPath('live.version', 2);

        // The version table: newest first, then the original.
        $version = $response->json('versions.0');
        $this->assertSame(2, $version['version']);
        $this->assertTrue($version['active']);
        $this->assertSame(50, $version['rows']);          // 40 base rows + 10 graduates
        $this->assertSame(25, $version['failed']);
        $this->assertEquals(100.0, $version['accuracy']);
        $this->assertEquals(1.0, $version['kappa']);
        $this->assertEquals(100.0, $version['failed_recall']);
        $this->assertEquals(50.0, $version['baseline']);
        $this->assertSame('let-models/model-v2.json', $version['file_path']);

        $this->assertSame(1, $response->json('versions.1.version'));
        $this->assertFalse($response->json('versions.1.active'));
        $this->assertSame(LetModelTrainer::ORIGINAL_MODEL_FILE, $response->json('versions.1.file_path'));

        // The model file is kept, and predictions now come from the new tree.
        Storage::disk('local')->assertExists('let-models/model-v2.json');
        $this->assertSame('selection', (new LetPredictionService())->liveModel()['tree']['attribute']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.let.retrain', 'actor_id' => $this->admin->id]);

        // Nothing new since: another ten are needed before the next.
        $this->postJson('/api/predictions/let/model/retrain')
            ->assertStatus(422)
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_NOT_ENOUGH_NEW_RESULTS);
        $this->assertDatabaseCount('let_models', 1);
    }

    public function test_a_retrained_model_that_is_worse_is_kept_out_and_the_old_one_stays(): void
    {
        // Results that contradict everything the base dataset shows: strong
        // Selection scores that failed, weak ones that passed.
        $this->graduates(6, ['selection' => 70], LleResult::RESULT_FAIL);
        $this->graduates(6, ['selection' => 30], LleResult::RESULT_PASS);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/predictions/let/model/retrain')
            ->assertOk()
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_KEPT_CURRENT)
            ->assertJsonPath('live.source', 'built_in')
            ->assertJsonPath('live.version', 1)
            ->assertJsonPath('versions.0.version', 2)
            ->assertJsonPath('versions.0.active', false)
            ->assertJsonPath('versions.0.status', LetModel::STATUS_REJECTED)
            ->assertJsonPath('versions.0.can_activate', false)
            ->assertJsonPath('versions.1.active', true);

        // The administrator is told why.
        $this->assertStringContainsString('was not taken up', $response->json('message'));
        $this->assertStringContainsString('its Kappa', $response->json('message'));
        $this->assertStringContainsString('Version 1 stays in use', $response->json('message'));
        $this->assertNotNull($response->json('versions.0.note'));

        $this->assertLessThan(1.0, LetModel::query()->firstOrFail()->kappa);
        $this->assertSame('built_in', (new LetPredictionService())->liveModel()['source']);

        // A version that was never in use cannot be switched to.
        $this->postJson('/api/predictions/let/model/activate', ['version' => 2])->assertStatus(422);
    }

    public function test_an_administrator_can_go_back_to_any_version_that_was_in_use(): void
    {
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(5, ['selection' => 30], LleResult::RESULT_FAIL);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/predictions/let/model/retrain')->assertJsonPath('live.version', 2);

        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])
            ->assertOk()
            ->assertJsonPath('live.source', 'built_in')
            ->assertJsonPath('versions.0.active', false)
            ->assertJsonPath('versions.0.can_activate', true)
            ->assertJsonPath('versions.1.active', true);

        // Already in use.
        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])->assertStatus(422);

        $this->postJson('/api/predictions/let/model/activate', ['version' => 2])
            ->assertOk()
            ->assertJsonPath('live.version', 2);

        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.let.activate', 'actor_id' => $this->admin->id]);
    }

    public function test_retraining_waits_for_the_base_dataset(): void
    {
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(5, ['selection' => 30], LleResult::RESULT_FAIL);

        @unlink($this->basePath);
        $this->app->instance(LetBaseDataset::class, new LetBaseDataset($this->basePath));

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/predictions/let/model')
            ->assertJsonPath('base_dataset.present', false)
            ->assertJsonPath('can_retrain', false);

        $this->postJson('/api/predictions/let/model/retrain')
            ->assertStatus(422)
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_NO_BASE_DATASET);

        $this->assertDatabaseCount('let_models', 0);
    }

    public function test_only_an_administrator_retrains_or_changes_the_version(): void
    {
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(5, ['selection' => 30], LleResult::RESULT_FAIL);

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/predictions/let/model')->assertOk()->assertJsonMissingPath('live.tree');
        $this->postJson('/api/predictions/let/model/retrain')->assertForbidden();
        $this->postJson('/api/predictions/let/model/activate', ['version' => 1])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));
        $this->getJson('/api/predictions/let/model')->assertForbidden();
        $this->postJson('/api/predictions/let/model/retrain')->assertForbidden();

        $this->assertDatabaseCount('let_models', 0);
    }

    public function test_recording_results_never_retrains_by_itself(): void
    {
        Queue::fake();
        $this->graduates(5, ['selection' => 70], LleResult::RESULT_PASS);
        $this->graduates(5, ['selection' => 30], LleResult::RESULT_FAIL);

        $eleventh = $this->studentWithSemester(self::SCORES, 2025, 2);
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/lle-results', ['user_id' => $eleventh->id, 'exam_year' => 2026, 'result' => 'pass', 'taken_at' => '2026-09-14'])
            ->assertCreated();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('let_models', 0);
        $this->assertDatabaseHas('lle_results', ['user_id' => $eleventh->id, 'taken_at' => '2026-09-14 00:00:00']);
    }

    public function test_training_rows_come_only_from_graduates_with_a_real_result_and_a_complete_final_year_semester(): void
    {
        // Sat the board exam after a complete fourth-year semester.
        $graduate = $this->studentWithSemester(['selection' => 61] + self::SCORES, 2025, 2);
        $this->recordResult($graduate, LleResult::RESULT_FAIL);

        // A complete semester, but no board result: nothing to learn from.
        $this->studentWithSemester(self::SCORES, 2025, 2);

        // Complete in third year, one exam short in fourth: third-year
        // scores are never a training row.
        $thirdYearOnly = $this->studentWithSemester(self::SCORES, 2024, 2);
        $this->semester($thirdYearOnly, 2025, 2, ['it' => null] + self::SCORES);
        $this->recordResult($thirdYearOnly, LleResult::RESULT_PASS);

        // A demo account's invented outcome.
        $demo = $this->studentWithSemester(self::SCORES, 2025, 2, ['is_demo' => true]);
        $this->recordResult($demo, LleResult::RESULT_FAIL);

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertSame(["student:{$graduate->id}"], array_column($set['graduates'], 'key'));
        $this->assertSame(1, $set['skipped']);
        $this->assertCount(40, $set['base']);

        // The row: the six scores, the weighted overall, the real result.
        $row = $set['graduates'][0];
        $this->assertSame(LetPredictionService::RESULT_FAIL, $row['label']);
        $this->assertSame(LetPredictionService::requiredFeatures(), array_keys($row['features']));
        $this->assertSame(61.0, $row['features']['selection']);
        $this->assertSame(LetReadinessFeatureBuilder::overall($row['features']), $row['features']['overall']);
    }

    public function test_exams_taken_after_the_board_exam_are_left_out_of_a_graduates_row(): void
    {
        $graduate = $this->studentWithSemester(self::SCORES, 2025, 2);
        $this->recordResult($graduate, LleResult::RESULT_FAIL, ['taken_at' => '2026-09-14']);

        $before = app(LetTrainingSetBuilder::class)->build()['graduates'][0]['features'];

        // Preparing for a retake, the semester after.
        $this->semester($graduate, 2026, 1, array_fill_keys(array_keys(self::SCORES), 100), Carbon::parse('2026-10-05'));

        $this->assertSame($before, app(LetTrainingSetBuilder::class)->build()['graduates'][0]['features']);
    }

    public function test_the_base_dataset_keeps_a_missing_score_missing(): void
    {
        $rows = app(LetBaseDataset::class)->rows();

        $this->assertCount(40, $rows);
        $this->assertNull($rows[0]['features']['cataloging']);
        $this->assertSame(LetPredictionService::RESULT_FAIL, $rows[0]['label']);
        $this->assertSame(LetPredictionService::RESULT_PASS, $rows[39]['label']);
    }

    public function test_a_prediction_can_be_run_by_hand_with_a_subject_left_out(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/predictions/let', ['reference' => 80, 'management' => 70, 'indexing' => 60, 'selection' => 50, 'cataloging' => 90])
            ->assertOk()
            ->assertJsonPath('features.it', null)
            ->assertJsonPath('features.overall', round((0.20 * 80 + 0.20 * 70 + 0.15 * 60 + 0.15 * 50 + 0.20 * 90) / 0.90, 2))
            ->assertJsonPath('prediction', 'PASS');

        $this->postJson('/api/predictions/let', ['selection' => 40])->assertOk()->assertJsonPath('prediction', 'FAIL');
    }

    public function test_predictions_still_work_before_the_new_tables_exist(): void
    {
        // Code is deployed before migrations are run by hand on the server.
        Schema::drop('let_models');

        $predictor = new LetPredictionService();

        $this->assertSame('built_in', $predictor->liveModel()['source']);
        $this->assertSame('PASS', $predictor->predict(array_fill_keys(LetPredictionService::requiredFeatures(), 90))['prediction']);
    }

    /**
     * Forty past examinees in the model's file format. Selection decides:
     * 20 to 38 failed, 60 to 79 passed. A few other scores are missing.
     *
     * Two examinees share the highest failing score. J48 moves a cut down to
     * a score someone in the training rows really had, so a lone highest
     * failer, held out in cross-validation, lands above the cut made without
     * them and is counted wrong.
     */
    private function baseDataset(): string
    {
        $lines = [
            '@relation lle_board_exam_clean',
            '',
            '@attribute reference numeric',
            '@attribute management numeric',
            '@attribute indexing numeric',
            '@attribute selection numeric',
            '@attribute cataloging numeric',
            '@attribute it numeric',
            '@attribute overall numeric',
            '@attribute board_result {FAILED,PASSED}',
            '',
            '@data',
        ];

        foreach ([...range(20, 38), 38, ...range(60, 79)] as $index => $selection) {
            $scores = [
                'reference' => 60 + $index % 7,
                'management' => 62 + $index % 5,
                'indexing' => 58 + $index % 9,
                'selection' => $selection,
                'cataloging' => $index % 10 === 0 ? null : 61 + $index % 6,
                'it' => 64 + $index % 4,
            ];
            $features = LetReadinessFeatureBuilder::features($scores);

            $lines[] = implode(',', [
                ...array_map(static fn (?float $score): string => $score === null ? '?' : (string) $score, array_values($features)),
                $selection >= 60 ? 'PASSED' : 'FAILED',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function useBaseDataset(string $arff): void
    {
        $this->basePath = tempnam(sys_get_temp_dir(), 'lle') . '.arff';
        file_put_contents($this->basePath, $arff);

        $this->app->instance(LetBaseDataset::class, new LetBaseDataset($this->basePath));
    }

    /**
     * $count graduates with those fourth-year scores and that board result.
     * Their other scores fall where the base dataset's do, so that nothing
     * but the scores given tells them apart from it.
     *
     * @param  array<string, int>  $scores
     */
    private function graduates(int $count, array $scores, string $result): void
    {
        for ($index = 0; $index < $count; $index++) {
            $nth = $this->graduatesMade++;

            $this->recordResult($this->studentWithSemester($scores + [
                'reference' => 60 + $nth % 7,
                'management' => 62 + $nth % 5,
                'indexing' => 58 + $nth % 9,
                'selection' => 70,
                'cataloging' => 61 + $nth % 6,
                'it' => 64 + $nth % 4,
            ], 2025, 2), $result);
        }
    }

    /**
     * @param  array<string, int|null>  $scores
     * @param  array<string, mixed>  $attributes
     */
    private function studentWithSemester(array $scores, int $schoolYear, int $semester, array $attributes = []): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'year_level' => 4] + $attributes);
        $this->semester($student, $schoolYear, $semester, $scores);

        return $student;
    }

    /**
     * @param  array<string, int|null>  $scores
     */
    private function semester(User $student, int $schoolYear, int $semester, array $scores, ?Carbon $when = null): void
    {
        $when ??= $semester === 1 ? Carbon::create($schoolYear, 9, 15) : Carbon::create($schoolYear + 1, 2, 15);

        foreach (array_keys(LetReadinessFeatureBuilder::SUBJECTS) as $index => $key) {
            if (($scores[$key] ?? null) === null) {
                continue;
            }

            $exam = $this->exams["{$key}:{$schoolYear}:{$semester}"] ??= Exam::create([
                'title' => LetReadinessFeatureBuilder::SUBJECTS[$key] . ' Exam',
                'subject' => LetReadinessFeatureBuilder::SUBJECTS[$key],
                'total_items' => 100,
                'duration_minutes' => 60,
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                'one_take_only' => false,
                'shuffle_questions' => false,
                'school_year' => $schoolYear,
                'semester' => $semester,
                'created_by' => $this->staff->id,
            ]);

            ExamAttempt::create([
                'exam_id' => $exam->id,
                'room_id' => $this->room->id,
                'user_id' => $student->id,
                'status' => ExamAttempt::STATUS_SUBMITTED,
                'total_items' => 100,
                'duration_minutes' => 60,
                'answered_count' => 100,
                'correct_answers' => $scores[$key],
                'score_percent' => $scores[$key],
                'started_at' => $when->copy()->addDays($index)->subHour(),
                'submitted_at' => $when->copy()->addDays($index),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordResult(User $student, string $result, array $attributes = []): void
    {
        LleResult::create([
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => $result,
            'taken_at' => '2026-09-14',
            'recorded_by' => $this->staff->id,
        ] + $attributes);
    }
}
