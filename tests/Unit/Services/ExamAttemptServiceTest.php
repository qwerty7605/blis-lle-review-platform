<?php

namespace Tests\Unit\Services;

use App\Models\ExamAttempt;
use App\Services\ExamAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ExamAttemptService keeps an attempt's counts and score in step with its
 * answers, hands in an attempt whose time ran out, and builds what the exam
 * screen shows.
 */
class ExamAttemptServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function service(): ExamAttemptService
    {
        return app(ExamAttemptService::class);
    }

    private function scenario(int $items = 4): array
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, $items), [$room]);

        return [$exam, $room, $student];
    }

    public function test_the_counts_follow_the_answers_and_the_score_is_set_on_hand_in(): void
    {
        [$exam, $room, $student] = $this->scenario();
        $attempt = $this->makeOpenAttempt($exam, $room, $student, [true, false, true]);

        $attempt = $this->service()->refreshMetrics($attempt);
        $this->assertSame(3, $attempt->answered_count);
        $this->assertSame(2, $attempt->correct_answers);
        $this->assertNull($attempt->score_percent);

        $attempt = $this->service()->refreshMetrics($attempt, true);
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, $attempt->status);
        $this->assertEquals(50.0, $attempt->score_percent);
        $this->assertNotNull($attempt->submitted_at);
    }

    public function test_a_handed_in_attempt_with_no_score_gets_one(): void
    {
        [$exam, $room, $student] = $this->scenario();
        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, [true, true, true, true]);
        $attempt->forceFill(['score_percent' => null])->save();

        $this->assertEquals(100.0, $this->service()->refreshMetrics($attempt)->score_percent);
    }

    public function test_an_attempt_with_no_items_scores_zero(): void
    {
        [$exam, $room, $student] = $this->scenario();
        $attempt = $this->makeOpenAttempt($exam, $room, $student);
        $attempt->forceFill(['total_items' => 0])->save();

        $this->assertEquals(0.0, $this->service()->refreshMetrics($attempt, true)->score_percent);
    }

    public function test_only_an_attempt_past_its_deadline_is_handed_in_automatically(): void
    {
        [$exam, $room, $student] = $this->scenario();
        $running = $this->makeOpenAttempt($exam, $room, $student, [true]);
        $this->assertSame(ExamAttempt::STATUS_IN_PROGRESS, $this->service()->autoSubmitExpiredAttempt($running)->status);

        $noDeadline = $this->makeOpenAttempt($exam, $room, $this->makeStudent());
        $noDeadline->forceFill(['expires_at' => null])->save();
        $this->assertSame(ExamAttempt::STATUS_IN_PROGRESS, $this->service()->autoSubmitExpiredAttempt($noDeadline)->status);

        $late = $this->makeOpenAttempt($exam, $room, $this->makeStudent(), [true, true], now()->subHours(2));
        $handedIn = $this->service()->autoSubmitExpiredAttempt($late);
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, $handedIn->status);
        $this->assertEquals(50.0, $handedIn->score_percent);
        // Handed in when the time ran out, not when somebody next opened it.
        $this->assertTrue($handedIn->submitted_at->equalTo($late->expires_at));
    }

    public function test_the_screen_shows_the_time_left_and_skips_a_question_that_was_deleted(): void
    {
        [$exam, $room, $student] = $this->scenario(3);
        $attempt = $this->makeOpenAttempt($exam, $room, $student);
        $attempt->attemptQuestions()->where('item_number', 3)->first()->question->delete();

        $payload = $this->service()->buildAttemptPayload($attempt->fresh());

        $this->assertCount(2, $payload['questions']);
        $this->assertGreaterThan(0, $payload['attempt']['remaining_seconds']);
        $this->assertSame($exam->id, $payload['exam']['id']);
    }
}
