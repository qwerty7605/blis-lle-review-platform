<?php

namespace Tests\Feature;

use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\ReviewBotItemState;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Services\ReviewBotAdminService;
use App\Services\ReviewBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use App\Jobs\GenerateReviewBotQuestionsJob;
use RuntimeException;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

class ReviewBotTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Cataloging and Classification';

    // ---------------------------------------------------------------- catalog

    public function test_catalog_counts_published_questions_by_subject_topic_and_subtopic(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);
        $topic = Topic::create(['subject' => self::SUBJECT, 'name' => 'Descriptive Cataloging', 'created_by' => $teacher->id]);
        $subtopic = Subtopic::create(['topic_id' => $topic->id, 'name' => 'RDA', 'created_by' => $teacher->id]);

        $student = $this->makeStudent();
        $this->dealReviewBotSources($student, [
            $this->makeReviewQuestion($bank, ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id, 'difficulty' => 'easy']),
            $this->makeReviewQuestion($bank, ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id, 'difficulty' => 'hard']),
            $this->makeReviewQuestion($bank, ['topic_id' => $topic->id]),
            // Neither of these may be counted.
            $this->makeReviewQuestion($bank, ['topic_id' => $topic->id, 'status' => QuestionBankQuestion::STATUS_DRAFT]),
            $this->makeReviewQuestion($bank, ['topic_id' => $topic->id, 'status' => QuestionBankQuestion::STATUS_REJECTED]),
        ]);

        $catalog = collect(app(ReviewBotService::class)->catalog($student))->keyBy('subject');
        $entry = $catalog[self::SUBJECT];

        $this->assertSame(3, $entry['question_count']);
        $this->assertTrue($entry['bot_ready']);
        $this->assertCount(1, $entry['topics']);
        $this->assertSame('Descriptive Cataloging', $entry['topics'][0]['name']);
        $this->assertSame(3, $entry['topics'][0]['question_count']);
        $this->assertCount(1, $entry['topics'][0]['subtopics']);
        $this->assertSame(2, $entry['topics'][0]['subtopics'][0]['question_count']);
        $this->assertEqualsCanonicalizing(['easy', 'hard'], $entry['difficulties']);
    }

    public function test_catalog_reports_subjects_with_no_published_questions_as_not_ready(): void
    {
        $catalog = collect(app(ReviewBotService::class)->catalog($this->makeStudent()))->keyBy('subject');

        $this->assertSame(0, $catalog[self::SUBJECT]['question_count']);
        $this->assertFalse($catalog[self::SUBJECT]['bot_ready']);
        $this->assertSame([], $catalog[self::SUBJECT]['topics']);
    }

    // ------------------------------------------------------------- submission

    public function test_submitting_a_session_grades_and_records_mastery_state(): void
    {
        [$student, $attempt, $questions] = $this->startSessionWith(3);

        $result = app(ReviewBotService::class)->submitSession($attempt, [
            ['question_id' => $questions[0]->id, 'option_id' => $this->correctOptionId($questions[0])],
            ['question_id' => $questions[1]->id, 'option_id' => $this->wrongOptionId($questions[1])],
            // Third question deliberately left unanswered.
        ]);

        $this->assertSame(3, $result['total_items']);
        $this->assertSame(2, $result['answered_count']);
        $this->assertSame(1, $result['correct_answers']);
        $this->assertEqualsWithDelta(33.33, $result['score_percent'], 0.01);
        $this->assertCount(1, $result['missed_items']);

        $states = ReviewBotItemState::where('user_id', $student->id)->get()->keyBy('question_bank_question_id');

        $this->assertSame(3, $states->count());
        $this->assertSame(1, (int) $states[$questions[0]->id]->times_correct);
        $this->assertSame(1, (int) $states[$questions[0]->id]->consecutive_correct);
        $this->assertSame(0, (int) $states[$questions[1]->id]->times_correct);
        $this->assertSame(0, (int) $states[$questions[1]->id]->consecutive_correct);
        // An unanswered question still counts as seen -- it was served.
        $this->assertSame(1, (int) $states[$questions[2]->id]->times_seen);
    }

    public function test_a_session_cannot_be_submitted_twice(): void
    {
        [, $attempt, $questions] = $this->startSessionWith(2);
        $service = app(ReviewBotService::class);

        $answers = [['question_id' => $questions[0]->id, 'option_id' => $this->correctOptionId($questions[0])]];
        $service->submitSession($attempt, $answers);

        $this->expectException(RuntimeException::class);
        $service->submitSession($attempt->fresh(), $answers);
    }

    public function test_a_second_submission_cannot_inflate_mastery_counters(): void
    {
        [$student, $attempt, $questions] = $this->startSessionWith(1);
        $service = app(ReviewBotService::class);

        $answers = [['question_id' => $questions[0]->id, 'option_id' => $this->correctOptionId($questions[0])]];
        $service->submitSession($attempt, $answers);

        try {
            $service->submitSession($attempt->fresh(), $answers);
        } catch (RuntimeException) {
            // Expected -- the point is what the counters look like afterwards.
        }

        $state = ReviewBotItemState::where('user_id', $student->id)
            ->where('question_bank_question_id', $questions[0]->id)
            ->first();

        $this->assertSame(1, (int) $state->times_seen);
        $this->assertSame(1, (int) $state->times_correct);
        $this->assertSame(1, (int) $state->consecutive_correct);
    }

    public function test_mastery_streak_accumulates_across_sessions_and_resets_on_a_wrong_answer(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);
        $question = $this->makeReviewQuestion($bank);
        $student = $this->makeStudent();
        $this->dealReviewBotSources($student, [$question]);
        $service = app(ReviewBotService::class);

        foreach ([true, true, false] as $index => $answerCorrectly) {
            $session = $service->startSession($student, ['subject' => self::SUBJECT, 'question_count' => 1]);
            $optionId = $answerCorrectly ? $this->correctOptionId($question) : $this->wrongOptionId($question);
            $service->submitSession($session['attempt'], [['question_id' => $question->id, 'option_id' => $optionId]]);

            $state = ReviewBotItemState::where('user_id', $student->id)->first();

            $this->assertSame($index + 1, (int) $state->times_seen);
            $this->assertSame(
                $answerCorrectly ? $index + 1 : 0,
                (int) $state->consecutive_correct,
                'consecutive_correct should climb while correct and reset to zero on a miss',
            );
        }

        $state = ReviewBotItemState::where('user_id', $student->id)->first();
        $this->assertSame(3, (int) $state->times_seen);
        $this->assertSame(2, (int) $state->times_correct);
    }

    public function test_submission_writes_a_bounded_number_of_queries(): void
    {
        [, $attempt, $questions] = $this->startSessionWith(8);

        $answers = collect($questions)
            ->map(fn ($q) => ['question_id' => $q->id, 'option_id' => $this->correctOptionId($q)])
            ->all();

        DB::enableQueryLog();
        app(ReviewBotService::class)->submitSession($attempt, $answers);
        $writes = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => (bool) preg_match('/^(insert|update)/i', trim($entry['query'])))
            ->count();
        DB::disableQueryLog();

        // Two bulk upserts plus the attempt update. The per-question version
        // this replaced issued four writes per question -- 32 at this size.
        $this->assertLessThanOrEqual(4, $writes, 'submission should batch its writes, not write per question');
    }

    // ------------------------------------------------------- reject and reuse

    public function test_rejecting_a_draft_hides_it_from_the_review_queue_and_from_students(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);
        $keep = $this->makeReviewQuestion($bank, ['status' => QuestionBankQuestion::STATUS_DRAFT]);
        $decline = $this->makeReviewQuestion($bank, ['status' => QuestionBankQuestion::STATUS_DRAFT]);

        $admin = app(ReviewBotAdminService::class);
        $this->assertSame(1, $admin->reject([$decline->id]));

        $this->assertSame(
            QuestionBankQuestion::STATUS_REJECTED,
            $decline->fresh()->status,
        );

        $listed = $admin->listQuestions(['subject' => self::SUBJECT])->pluck('id');
        $this->assertTrue($listed->contains($keep->id));
        $this->assertFalse($listed->contains($decline->id), 'a declined draft should not reappear in the review queue');

        // Explicitly asking for them still works.
        $this->assertSame(
            [$decline->id],
            $admin->listQuestions(['status' => QuestionBankQuestion::STATUS_REJECTED])->pluck('id')->all(),
        );
    }

    public function test_an_exam_item_whose_variants_were_all_rejected_is_eligible_again(): void
    {
        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $reviewBank = $this->makeBank($teacher, QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . self::SUBJECT);

        $sourceItem = $this->makeAssessmentQuestion($sourceBank, 1);
        $variant = $this->makeReviewQuestion($reviewBank, [
            'source_question_id' => $sourceItem->id,
            'status' => QuestionBankQuestion::STATUS_DRAFT,
        ]);

        $eligible = fn (): array => QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('subject', self::SUBJECT)
            ->whereDoesntHave('liveGeneratedVariants')
            ->pluck('id')
            ->all();

        $this->assertSame([], $eligible(), 'an item with a live variant is already covered');

        app(ReviewBotAdminService::class)->reject([$variant->id]);

        $this->assertSame(
            [$sourceItem->id],
            $eligible(),
            'once every variant is declined the item has no usable coverage and must be regenerable',
        );
    }

    public function test_students_are_never_served_rejected_questions(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);
        $published = $this->makeReviewQuestion($bank);
        $rejected = $this->makeReviewQuestion($bank, ['status' => QuestionBankQuestion::STATUS_REJECTED]);
        $student = $this->makeStudent();
        $this->dealReviewBotSources($student, [$published, $rejected]);

        $session = app(ReviewBotService::class)->startSession($student, [
            'subject' => self::SUBJECT,
            'question_count' => 10,
        ]);

        $served = collect($session['questions'])->pluck('question_id');

        $this->assertTrue($served->contains($published->id));
        $this->assertFalse($served->contains($rejected->id));
    }

    // ---------------------------------------------------------------- coverage

    public function test_coverage_report_shows_missing_cognitive_levels_per_exam_item(): void
    {
        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $reviewBank = $this->makeBank($teacher, QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . self::SUBJECT);

        $covered = $this->makeAssessmentQuestion($sourceBank, 1);
        $bare = $this->makeAssessmentQuestion($sourceBank, 2);

        foreach (['recall', 'comprehension'] as $level) {
            $this->makeReviewQuestion($reviewBank, [
                'source_question_id' => $covered->id,
                'cognitive_level' => $level,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
            ]);
        }
        $this->makeReviewQuestion($reviewBank, [
            'source_question_id' => $covered->id,
            'cognitive_level' => 'analysis',
            'status' => QuestionBankQuestion::STATUS_REJECTED,
        ]);

        $report = collect(app(ReviewBotAdminService::class)->coverageReport(self::SUBJECT))
            ->keyBy('source_question_id');

        $this->assertSame(2, $report[$covered->id]['total']);
        $this->assertSame(2, $report[$covered->id]['published']);
        $this->assertSame(1, $report[$covered->id]['rejected']);
        $this->assertEqualsCanonicalizing(['recall', 'comprehension'], $report[$covered->id]['covered_levels']);
        // The declined analysis question must read as missing, not covered.
        $this->assertEqualsCanonicalizing(['application', 'analysis'], $report[$covered->id]['missing_levels']);

        $this->assertSame(0, $report[$bare->id]['total']);
        $this->assertEqualsCanonicalizing(
            QuestionBankQuestion::COGNITIVE_LEVELS,
            $report[$bare->id]['missing_levels'],
        );
    }

    // ------------------------------------------------- generation dispatch guard

    public function test_a_second_generation_for_the_same_file_is_not_dispatched(): void
    {
        Queue::fake();
        Cache::flush();

        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $this->makeAssessmentQuestion($sourceBank, 1);

        $admin = app(ReviewBotAdminService::class);

        $this->assertSame('queued', $admin->queueGeneration($teacher, $sourceBank)['status']);
        $this->assertSame(
            'in_progress',
            $admin->queueGeneration($teacher, $sourceBank)['status'],
            'a second click while a run is in flight must not dispatch another job',
        );

        Queue::assertPushed(GenerateReviewBotQuestionsJob::class, 1);
    }

    public function test_generation_can_be_queued_again_once_the_lock_is_released(): void
    {
        Queue::fake();
        Cache::flush();

        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $this->makeAssessmentQuestion($sourceBank, 1);

        $admin = app(ReviewBotAdminService::class);
        $admin->queueGeneration($teacher, $sourceBank);

        // What the job does in its finally block when the run finishes.
        Cache::forget('review-bot-generate:' . $sourceBank->id);

        $this->assertSame('queued', $admin->queueGeneration($teacher, $sourceBank)['status']);
        Queue::assertPushed(GenerateReviewBotQuestionsJob::class, 2);
    }

    public function test_the_draft_list_says_whether_a_run_for_that_file_is_still_going(): void
    {
        Queue::fake();
        Cache::flush();

        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $otherBank = $this->makeBank($teacher, 'Another File');
        $this->makeAssessmentQuestion($sourceBank, 1);

        Sanctum::actingAs($teacher);

        $list = fn (QuestionBank $bank) => $this
            ->getJson('/api/library/review-bot/questions?source_bank_id=' . $bank->id)
            ->assertOk();

        $list($sourceBank)->assertJsonPath('generating', false);

        app(ReviewBotAdminService::class)->queueGeneration($teacher, $sourceBank);

        $list($sourceBank)->assertJsonPath('generating', true);
        $list($otherBank)->assertJsonPath('generating', false);

        // What the job does in its finally block when the run finishes.
        Cache::forget('review-bot-generate:' . $sourceBank->id);

        $list($sourceBank)->assertJsonPath('generating', false);
    }

    public function test_the_draft_list_stops_saying_generating_once_the_job_has_run(): void
    {
        Cache::flush();

        $teacher = $this->makeTeacher();
        $sourceBank = $this->makeBank($teacher, 'Source File');
        $this->makeAssessmentQuestion($sourceBank, 1);

        // The queue runs jobs inline in tests, so by the time this returns the
        // job has been through handle() and let go of its lock.
        app(ReviewBotAdminService::class)->queueGeneration($teacher, $sourceBank);

        Sanctum::actingAs($teacher);

        $this->getJson('/api/library/review-bot/questions?source_bank_id=' . $sourceBank->id)
            ->assertOk()
            ->assertJsonPath('generating', false);
    }

    // ------------------------------------------------------------------ helpers

    private function makeTeacher(): User
    {
        return User::factory()->create([
            'email' => 'rb-teacher-' . uniqid() . '@example.com',
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
        ]);
    }

    private function makeStudent(): User
    {
        return User::factory()->create([
            'email' => 'rb-student-' . uniqid() . '@example.com',
            'role' => User::ROLE_STUDENT,
            'year_level' => 4,
            'is_active' => true,
        ]);
    }

    private function makeBank(User $teacher, string $title = 'Review Bot Bank'): QuestionBank
    {
        return QuestionBank::create([
            'title' => $title,
            'subject' => self::SUBJECT,
            'total_items' => 0,
            'created_by' => $teacher->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeReviewQuestion(QuestionBank $bank, array $attributes = []): QuestionBankQuestion
    {
        $question = QuestionBankQuestion::create(array_merge([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
            'subject' => self::SUBJECT,
            'status' => QuestionBankQuestion::STATUS_PUBLISHED,
            'item_number' => QuestionBankQuestion::where('question_bank_id', $bank->id)->count() + 1,
            'question_text' => 'Review question ' . uniqid(),
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
        ], $attributes));

        $this->attachOptions($question);

        return $question;
    }

    private function makeAssessmentQuestion(QuestionBank $bank, int $itemNumber): QuestionBankQuestion
    {
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => self::SUBJECT,
            'status' => QuestionBankQuestion::STATUS_PUBLISHED,
            'item_number' => $itemNumber,
            'question_text' => 'Exam item ' . $itemNumber,
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
        ]);

        $this->attachOptions($question);

        return $question;
    }

    private function attachOptions(QuestionBankQuestion $question): void
    {
        foreach (['A', 'B', 'C', 'D'] as $index => $label) {
            QuestionBankOption::create([
                'question_bank_question_id' => $question->id,
                'sort_order' => $index,
                'option_label' => $label,
                'option_text' => 'Option ' . $label,
                'is_correct' => $label === 'A',
            ]);
        }

        $question->load('options');
    }

    private function correctOptionId(QuestionBankQuestion $question): int
    {
        return (int) $question->options()->where('is_correct', true)->value('id');
    }

    private function wrongOptionId(QuestionBankQuestion $question): int
    {
        return (int) $question->options()->where('is_correct', false)->value('id');
    }

    /**
     * @return array{0: User, 1: ReviewBotAttempt, 2: array<int, QuestionBankQuestion>}
     */
    private function startSessionWith(int $count): array
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);

        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $questions[] = $this->makeReviewQuestion($bank);
        }

        $student = $this->makeStudent();
        $this->dealReviewBotSources($student, $questions);
        $session = app(ReviewBotService::class)->startSession($student, [
            'subject' => self::SUBJECT,
            'question_count' => $count,
        ]);

        // The service picks and orders the questions, so grade against what it
        // actually served rather than the creation order.
        $servedIds = collect($session['questions'])->pluck('question_id')->all();
        $byId = collect($questions)->keyBy('id');
        $served = array_map(fn (int $id) => $byId[$id], $servedIds);

        return [$student, $session['attempt'], $served];
    }
}
