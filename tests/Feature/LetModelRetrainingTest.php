<?php

namespace Tests\Feature;

use App\Jobs\RetrainLetModelJob;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LetModel;
use App\Models\LetTrainingRecord;
use App\Models\LleResult;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use App\Services\MachineLearning\LetTrainingSetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The readiness model used to be a tree typed into the code: it could not
 * learn from the licensure results the system was collecting. It now retrains
 * from them, and takes the new tree only when it does at least as well as the
 * model in use on results that model has never seen.
 */
class LetModelRetrainingTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECTS = [
        'Information Technology',
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Reference Services',
        'Library Management',
        'Selection and Acquisition',
    ];

    private User $staff;

    private Room $room;

    /** @var array<string, Exam> */
    private array $exams = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $this->room = Room::create(['name' => 'BLIS 4A', 'code' => 'LET4A1', 'created_by' => $this->staff->id]);

        foreach (self::SUBJECTS as $subject) {
            $this->exams[$subject] = Exam::create([
                'title' => "{$subject} Mock",
                'subject' => $subject,
                'total_items' => 100,
                'duration_minutes' => 60,
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                'one_take_only' => false,
                'shuffle_questions' => false,
                'created_by' => $this->staff->id,
            ]);
        }
    }

    public function test_it_learns_what_the_built_in_model_gets_wrong_and_takes_over(): void
    {
        // In this cohort a strong Information Technology score is what
        // carried people through, whatever the rest looked like. The built-in
        // tree reads their low overall average and calls every one a fail.
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        $result = app(LetModelTrainer::class)->run();

        $this->assertSame(LetModelTrainer::OUTCOME_ACTIVATED, $result['outcome']);
        $this->assertSame(30, $result['model']->training_count);
        $this->assertSame(100.0, (float) $result['model']->metrics['candidate_accuracy']);
        $this->assertSame(50.0, (float) $result['model']->metrics['live_accuracy']);

        // Predictions now come from the retrained tree.
        $predictor = new LetPredictionService();
        $this->assertSame('retrained', $predictor->liveModel()['source']);

        $strongInIt = $this->features(['Information Technology' => 90]);
        $this->assertSame('FAIL', $predictor->predict($strongInIt, LetPredictionService::builtInTree())['prediction']);
        $this->assertSame('PASS', $predictor->predict($strongInIt)['prediction']);
    }

    public function test_a_student_sees_the_retrained_model_on_their_own_dashboard(): void
    {
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        $current = $this->fourthYear(['Information Technology' => 90]);
        Sanctum::actingAs($current);

        $this->getJson('/api/student/analytics')->assertJsonPath('predictive_model.prediction', 'FAIL');

        app(LetModelTrainer::class)->run();

        $this->getJson('/api/student/analytics')
            ->assertJsonPath('predictive_model.prediction', 'PASS')
            ->assertJsonPath('predictive_model.description', fn (string $text) => str_contains($text, 'retrained on 30 recorded licensure results'));
    }

    public function test_a_retrained_model_that_does_worse_is_kept_out(): void
    {
        // Here the built-in tree is right about everyone, and the learner is
        // rigged to be useless: it calls every student a fail.
        $this->cohort(12, [], LleResult::RESULT_PASS, 90);
        $this->cohort(12, [], LleResult::RESULT_FAIL, 40);

        $alwaysFail = new class extends DecisionTreeTrainer
        {
            public function train(array $rows, array $attributes): array
            {
                return ['leaf' => 'FAIL', 'instances' => (float) count($rows), 'errors' => 0.0];
            }
        };

        $trainer = new LetModelTrainer(app(LetTrainingSetBuilder::class), $alwaysFail, new LetPredictionService());
        $result = $trainer->run();

        $this->assertSame(LetModelTrainer::OUTCOME_KEPT_CURRENT, $result['outcome']);
        $this->assertSame(LetModel::STATUS_REJECTED, $result['model']->status);
        $this->assertSame(100.0, (float) $result['model']->metrics['live_accuracy']);
        $this->assertSame(50.0, (float) $result['model']->metrics['candidate_accuracy']);
        $this->assertSame('built_in', (new LetPredictionService())->liveModel()['source']);
    }

    public function test_it_waits_until_there_is_enough_to_learn_from(): void
    {
        $this->cohort(4, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(4, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        $result = app(LetModelTrainer::class)->run();

        $this->assertSame(LetModelTrainer::OUTCOME_NOT_ENOUGH_DATA, $result['outcome']);
        $this->assertDatabaseCount('let_models', 0);
    }

    public function test_it_does_not_retrain_when_nothing_has_changed_or_only_a_few_results_are_new(): void
    {
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        $trainer = app(LetModelTrainer::class);
        $trainer->run();

        $this->assertSame(LetModelTrainer::OUTCOME_NOTHING_NEW, $trainer->run()['outcome']);

        $this->cohort(3, ['Information Technology' => 90], LleResult::RESULT_PASS);

        $this->assertSame(LetModelTrainer::OUTCOME_NOT_ENOUGH_NEW_RESULTS, $trainer->run()['outcome']);
        $this->assertDatabaseCount('let_models', 1);
    }

    public function test_only_people_who_sat_the_exam_are_learned_from(): void
    {
        $graduate = $this->student(['Information Technology' => 90]);
        $this->recordResult($graduate, LleResult::RESULT_PASS);

        // Left without sitting the exam: not a failure, just not a record.
        $dropout = $this->student(['Information Technology' => 30]);
        $dropout->applyStudentStatus(User::STUDENT_STATUS_DROPPED);
        $dropout->save();

        // A demo account's invented outcome.
        $demo = $this->student(['Information Technology' => 30], 40, ['is_demo' => true]);
        $this->recordResult($demo, LleResult::RESULT_FAIL);

        // Sat the exam, but barely used the system: nothing to describe them by.
        $thin = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $this->attempt($thin, 'Information Technology', 80, Carbon::parse('2025-03-01'));
        $this->recordResult($thin, LleResult::RESULT_PASS);

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertSame(["student:{$graduate->id}"], array_column($set['records'], 'key'));
        $this->assertSame(1, $set['skipped']);
    }

    public function test_a_graduate_is_learned_from_only_with_the_history_a_prediction_needs(): void
    {
        // One exam short of what a student must have to be shown a prediction.
        $graduate = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $day = Carbon::parse('2025-02-01');

        for ($index = 0; $index < LetReadinessFeatureBuilder::MIN_GRADED_EXAMS - 1; $index++) {
            $this->attempt($graduate, self::SUBJECTS[$index % count(self::SUBJECTS)], 80, $day->copy()->addWeeks($index));
        }

        $this->recordResult($graduate, LleResult::RESULT_PASS);

        $set = app(LetTrainingSetBuilder::class)->build();
        $this->assertSame([], $set['records']);
        $this->assertSame(1, $set['skipped']);

        $this->attempt($graduate, 'Selection and Acquisition', 80, $day->copy()->addWeeks(41));

        $this->assertSame(["student:{$graduate->id}"], array_column(app(LetTrainingSetBuilder::class)->build()['records'], 'key'));
    }

    public function test_exams_taken_after_the_board_exam_are_left_out_of_a_graduates_record(): void
    {
        $graduate = $this->student(['Information Technology' => 90]);
        $this->recordResult($graduate, LleResult::RESULT_FAIL, ['taken_at' => '2026-09-15']);

        $before = app(LetTrainingSetBuilder::class)->build()['records'][0]['features'];

        // Preparing for a retake.
        foreach (self::SUBJECTS as $subject) {
            $this->attempt($graduate, $subject, 100, Carbon::parse('2026-11-01'));
        }

        $this->assertSame($before, app(LetTrainingSetBuilder::class)->build()['records'][0]['features']);
    }

    public function test_a_graduates_record_uses_the_numbers_their_prediction_was_made_from(): void
    {
        $student = $this->fourthYear(['Information Technology' => 88, 'Reference Services' => 61], 52);
        Sanctum::actingAs($student);

        $shown = $this->getJson('/api/student/analytics')->assertOk()->json('predictive_model');

        $built = app(LetReadinessFeatureBuilder::class)->forStudent($student);
        $direct = (new LetPredictionService())->predict($built['features']);

        $this->assertTrue($built['ready']);
        $this->assertSame($shown['prediction'], $direct['prediction']);
        $this->assertSame($shown['decision_rules'], $direct['path']);
    }

    public function test_an_administrator_can_go_back_to_the_previous_model_and_it_stays_back(): void
    {
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);
        app(LetModelTrainer::class)->run();

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/predictions/let/model/revert')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->postJson('/api/predictions/let/model/revert')
            ->assertOk()
            ->assertJsonPath('live.source', 'built_in')
            ->assertJsonPath('can_revert', false);

        // Retraining on the same results must not undo that decision.
        $this->assertSame(LetModelTrainer::OUTCOME_NOTHING_NEW, app(LetModelTrainer::class)->run()['outcome']);
        $this->assertSame('built_in', (new LetPredictionService())->liveModel()['source']);

        $this->postJson('/api/predictions/let/model/revert')->assertStatus(422);
    }

    public function test_staff_can_see_the_model_and_retrain_it_and_students_cannot(): void
    {
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        Sanctum::actingAs($this->staff);

        $this->getJson('/api/predictions/let/model')
            ->assertOk()
            ->assertJsonPath('live.source', 'built_in')
            ->assertJsonPath('data.usable', 30)
            ->assertJsonPath('data.new_since_live_model', 30)
            ->assertJsonMissingPath('live.tree');

        $this->postJson('/api/predictions/let/model/retrain')
            ->assertOk()
            ->assertJsonPath('outcome', LetModelTrainer::OUTCOME_ACTIVATED)
            ->assertJsonPath('live.source', 'retrained')
            ->assertJsonPath('live.trained_on', 30)
            ->assertJsonPath('history.0.status', LetModel::STATUS_ACTIVE);

        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.let.retrain', 'actor_id' => $this->staff->id]);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STUDENT]));
        $this->getJson('/api/predictions/let/model')->assertForbidden();
        $this->postJson('/api/predictions/let/model/retrain')->assertForbidden();
    }

    public function test_recording_results_queues_one_retraining_however_many_are_typed_in(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->staff);

        foreach ([$this->student([]), $this->student([]), $this->student([])] as $student) {
            $this->postJson('/api/lle-results', ['user_id' => $student->id, 'exam_year' => 2026, 'result' => 'pass'])
                ->assertCreated();
        }

        // A batch is entered one student at a time; one job waits for the lot.
        Queue::assertPushed(RetrainLetModelJob::class, 1);
    }

    public function test_removing_a_result_queues_retraining_too(): void
    {
        $student = $this->student([]);
        $this->recordResult($student, LleResult::RESULT_PASS);

        Queue::fake();
        Sanctum::actingAs($this->staff);

        $this->deleteJson('/api/lle-results/' . LleResult::query()->value('id'))->assertOk();

        Queue::assertPushed(RetrainLetModelJob::class, 1);
    }

    public function test_the_queued_job_retrains_on_its_own(): void
    {
        $this->cohort(15, ['Information Technology' => 90], LleResult::RESULT_PASS);
        $this->cohort(15, ['Information Technology' => 30], LleResult::RESULT_FAIL);

        RetrainLetModelJob::dispatchSync();

        $this->assertSame('retrained', (new LetPredictionService())->liveModel()['source']);
        $this->assertDatabaseHas('let_models', ['status' => LetModel::STATUS_ACTIVE, 'trigger' => LetModel::TRIGGER_AUTO]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'prediction.let.retrain', 'actor_id' => null]);
    }

    public function test_past_records_can_be_imported_from_a_file_and_are_learned_from(): void
    {
        $features = LetPredictionService::requiredFeatures();
        $path = tempnam(sys_get_temp_dir(), 'let') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, [...$features, 'Result']);
        fputcsv($handle, [...array_fill(0, 15, 88), 'PASS']);
        fputcsv($handle, [...array_fill(0, 15, 41), 'failed']);
        fputcsv($handle, [...array_fill(0, 14, 70), 140, 'pass']);   // out of range
        fputcsv($handle, [...array_fill(0, 15, 70), 'maybe']);       // not an outcome
        fclose($handle);

        $this->artisan('let:import-training-data', ['file' => $path])
            ->expectsOutputToContain('Imported 2 record(s).')
            ->assertSuccessful();

        unlink($path);

        $this->assertSame(2, LetTrainingRecord::query()->count());

        $records = app(LetTrainingSetBuilder::class)->build()['records'];
        $this->assertSame(['PASS', 'FAIL'], array_column($records, 'label'));
        $this->assertSame([LetTrainingSetBuilder::SOURCE_IMPORTED], array_values(array_unique(array_column($records, 'source'))));
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
     * $count graduates who all scored $scores (subject => percent, $other for
     * the rest) and all got the same licensure $result.
     *
     * @param  array<string, int>  $scores
     */
    private function cohort(int $count, array $scores, string $result, int $other = 40): void
    {
        for ($index = 0; $index < $count; $index++) {
            $this->recordResult($this->student($scores, $other), $result);
        }
    }

    /**
     * A student with a whole degree of graded exams: the six subjects eight
     * times over, a week apart through 2025, Information Technology first
     * each round.
     *
     * @param  array<string, int>  $scores
     * @param  array<string, mixed>  $attributes
     */
    private function student(array $scores, int $other = 40, array $attributes = []): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT] + $attributes);
        $day = Carbon::parse('2025-02-01');

        for ($round = 1; $round <= 8; $round++) {
            foreach (self::SUBJECTS as $subject) {
                $this->attempt($student, $subject, $scores[$subject] ?? $other, $day->copy());
                $day->addWeek();
            }
        }

        return $student;
    }

    /**
     * The same history on someone still enrolled, in the year a prediction
     * is shown from.
     *
     * @param  array<string, int>  $scores
     */
    private function fourthYear(array $scores, int $other = 40): User
    {
        return $this->student($scores, $other, ['year_level' => 4]);
    }

    private function attempt(User $student, string $subject, int $score, Carbon $when): void
    {
        ExamAttempt::create([
            'exam_id' => $this->exams[$subject]->id,
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function recordResult(User $student, string $result, array $attributes = []): void
    {
        LleResult::create([
            'user_id' => $student->id,
            'exam_year' => 2026,
            'result' => $result,
            'recorded_by' => $this->staff->id,
        ] + $attributes);
    }

    /**
     * The 15 features of a student with those scores.
     *
     * @param  array<string, int>  $scores
     * @return array<string, float>
     */
    private function features(array $scores, int $other = 40): array
    {
        return app(LetReadinessFeatureBuilder::class)->forStudent($this->student($scores, $other))['features'];
    }
}
