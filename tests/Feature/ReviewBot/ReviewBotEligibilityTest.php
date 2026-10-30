<?php

namespace Tests\Feature\ReviewBot;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\Topic;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\ReviewBotEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * A student may practise only Review Bot questions built from exam items they
 * were dealt in their own submitted sittings, of exams whose results are
 * shown and that they can no longer retake (ReviewBotEligibility). Checked
 * where the student reads them: the catalog, starting a session, and the
 * drills the study plan suggests.
 */
class ReviewBotEligibilityTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Cataloging and Classification';

    private User $teacher;

    private User $student;

    private Topic $topic;

    private Room $room;

    private QuestionBank $examBank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->topic = $this->makeTopic(self::SUBJECT, 'Authority Control', $this->teacher);
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
        $this->examBank = $this->makeBank($this->teacher, 4, ['subject' => self::SUBJECT], ['topic_id' => $this->topic->id]);

        Sanctum::actingAs($this->student);
    }

    public function test_questions_from_an_exam_the_student_never_took_are_not_offered(): void
    {
        $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => true]);
        $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(0);
        $this->assertCannotStart();
    }

    public function test_questions_from_an_exam_started_but_not_submitted_are_not_offered(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => true]);
        $this->makeOpenAttempt($exam, $this->room, $this->student, [true, true]);
        $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(0);
        $this->assertCannotStart();
    }

    public function test_only_the_items_the_student_was_dealt_are_offered(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => true]);
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, true, false]);

        // The exam drew from four items, but this sitting dealt only the first two.
        $dealt = $this->examBank->questions->take(2);
        $attempt->attemptQuestions()->whereNotIn('question_bank_question_id', $dealt->pluck('id'))->delete();
        $attempt->answers()->whereNotIn('question_bank_question_id', $dealt->pluck('id'))->delete();

        $published = $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(2);

        $served = $this->beginPractice(10)->assertCreated()->json('questions.*.question_id');
        $this->assertEqualsCanonicalizing(
            collect($published)->filter(fn (QuestionBankQuestion $q): bool => $dealt->pluck('id')->contains($q->source_question_id))->pluck('id')->all(),
            $served,
        );
    }

    public function test_questions_from_an_exam_with_hidden_results_are_not_offered(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], [
            'one_take_only' => true,
            'results_visibility_mode' => 'hidden',
        ]);
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, true, true]);
        $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(0);
        $this->assertCannotStart();

        $exam->forceFill(['results_visibility_mode' => 'visible'])->save();

        $this->assertCatalogCount(4);
    }

    public function test_questions_from_an_exam_the_student_can_still_retake_wait_until_they_cannot(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], [
            'one_take_only' => false,
            'schedule_end_at' => now()->addDay(),
        ]);
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, false, false]);
        $this->publishFrom($this->examBank->questions);

        // One sitting of two used, the exam still open, still assigned here.
        $this->assertCatalogCount(0);
        $this->assertCannotStart();

        // Each of these ends the chance to retake on its own.
        $this->withRetakeEnded($exam, fn () => $exam->forceFill(['schedule_end_at' => now()->subMinute()])->save());
        $this->withRetakeEnded($exam, fn () => $this->room->exams()->updateExistingPivot($exam->id, ['archived_at' => now()]));
        $this->withRetakeEnded($exam, fn () => $this->room->forceFill(['archived_at' => now()])->save());
        $this->withRetakeEnded($exam, fn () => $this->room->members()->detach($this->student->id));
        $this->withRetakeEnded($exam, fn () => $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, true, true]));
    }

    public function test_an_exam_with_no_end_time_counts_once_its_sittings_are_used(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => false]);
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, false, false]);
        $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(0);

        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, true, false]);

        $this->assertCatalogCount(4);
        $this->beginPractice(3)->assertCreated();
    }

    public function test_another_students_sitting_does_not_make_questions_eligible(): void
    {
        $classmate = $this->makeStudent();
        $this->room->members()->attach($classmate->id);
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => true]);
        $this->makeSubmittedAttempt($exam, $this->room, $classmate, [true, true, true, true]);
        $this->publishFrom($this->examBank->questions);

        $this->assertCatalogCount(0);
        $this->assertSame([], app(ReviewBotEligibility::class)->sourceIds($this->student));
        $this->assertCount(4, app(ReviewBotEligibility::class)->sourceIds($classmate));
    }

    public function test_the_study_plan_suggests_a_drill_only_where_the_student_has_eligible_questions(): void
    {
        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], [
            'one_take_only' => true,
            'results_visibility_mode' => 'hidden',
        ]);
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [false, false, false, false]);
        $this->publishFrom($this->examBank->questions);

        $plan = app(DashboardAnalyticsService::class)->buildStudyPlan($this->student);
        $this->assertFalse($plan[0]['review_ready'], 'hidden results: nothing to drill');
        $this->assertContains('practice_not_published', array_column(app(DashboardAnalyticsService::class)->buildActionPlan($this->student), 'key'));

        $exam->forceFill(['results_visibility_mode' => 'visible'])->save();

        $plan = app(DashboardAnalyticsService::class)->buildStudyPlan($this->student);
        $this->assertTrue($plan[0]['review_ready']);
        $this->assertContains('drill_weak_area', array_column(app(DashboardAnalyticsService::class)->buildActionPlan($this->student), 'key'));
    }

    public function test_a_top_up_only_draws_on_the_students_eligible_items(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        app(\App\Services\ReviewBotAdminService::class)->reviewBotBankFor(self::SUBJECT, $this->teacher->id);

        $exam = $this->makeExam($this->teacher, $this->examBank, [$this->room], ['one_take_only' => true]);
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, true, true]);
        $this->publishFrom($this->examBank->questions->take(1));

        // Asks for more than there is, so the session comes up short.
        $this->beginPractice(10)->assertCreated();

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\GenerateReviewBotQuestionsJob::class,
            fn ($job): bool => $job->sourceQuestionIds !== null
                && collect($job->sourceQuestionIds)->sort()->values()->all() === $this->examBank->questions->pluck('id')->sort()->values()->all(),
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * One published Review Bot question per source item.
     *
     * @return list<QuestionBankQuestion>
     */
    private function publishFrom(iterable $sources): array
    {
        $bank = app(\App\Services\ReviewBotAdminService::class)->reviewBotBankFor(self::SUBJECT, $this->teacher->id);
        $published = [];

        foreach ($sources as $source) {
            $published[] = $this->makeQuestion($bank, QuestionBankQuestion::where('question_bank_id', $bank->id)->count() + 1, [
                'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'subject' => self::SUBJECT,
                'topic_id' => $this->topic->id,
                'difficulty' => 'medium',
                'source_question_id' => $source->id,
            ]);
        }

        return $published;
    }

    private function beginPractice(int $count)
    {
        return $this->postJson('/api/student/review-bot/sessions', [
            'subject' => self::SUBJECT,
            'topic_id' => $this->topic->id,
            'question_count' => max(3, $count),
        ]);
    }

    private function assertCatalogCount(int $expected): void
    {
        $entry = collect($this->getJson('/api/student/review-bot/catalog')->assertOk()->json('subjects'))
            ->firstWhere('subject', self::SUBJECT);

        $this->assertSame($expected, $entry['question_count']);
        $this->assertSame($expected > 0, $entry['bot_ready']);
    }

    private function assertCannotStart(): void
    {
        $this->beginPractice(3)
            ->assertStatus(422)
            ->assertJsonPath('message', ReviewBotEligibility::EMPTY_MESSAGE);
    }

    /**
     * Ends the chance to retake one way, checks the questions are offered,
     * then puts everything back for the next way.
     */
    private function withRetakeEnded(Exam $exam, callable $end): void
    {
        $end();
        $this->assertCatalogCount(4);

        $exam->forceFill(['schedule_end_at' => now()->addDay()])->save();
        $this->room->forceFill(['archived_at' => null])->save();
        $this->room->exams()->updateExistingPivot($exam->id, ['archived_at' => null]);
        $this->room->members()->syncWithoutDetaching([$this->student->id]);

        // Back to the one sitting the test started with.
        ExamAttempt::query()->where('user_id', $this->student->id)->orderBy('id')->get()->slice(1)->each(function (ExamAttempt $attempt): void {
            $attempt->answers()->delete();
            $attempt->attemptQuestions()->delete();
            $attempt->delete();
        });

        $this->assertCatalogCount(0);
    }
}
