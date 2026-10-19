<?php

namespace Tests\Feature\Exams;

use App\Models\AuditLog;
use App\Models\ExamAttempt;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * exams:submit-expired: an attempt whose time ran out is submitted even if its
 * student never opens it again, so its score reaches the teacher's reports.
 */
class SubmitExpiredAttemptsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_only_attempts_past_their_deadline_are_submitted(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent();
        $ben = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$ana, $ben]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 4), [$room], ['duration_minutes' => 30]);

        $abandoned = $this->makeOpenAttempt($exam, $room, $ana, [true, true, false], now()->subHours(3));
        $running = $this->makeOpenAttempt($exam, $room, $ben, [true]);

        $this->artisan('exams:submit-expired')->expectsOutput('Submitted 1 expired attempt(s).')->assertSuccessful();

        $abandoned->refresh();
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, $abandoned->status);
        $this->assertEquals(50.0, $abandoned->score_percent);
        $this->assertTrue($abandoned->submitted_at->equalTo($abandoned->expires_at));
        $this->assertSame(ExamAttempt::STATUS_IN_PROGRESS, $running->refresh()->status);

        $log = AuditLog::where('action', 'exam.attempt.auto_submit')->sole();
        $this->assertNull($log->actor_id);
        $this->assertSame($abandoned->id, (int) $log->target_id);
        $this->assertSame($ana->id, $log->metadata['student_id']);

        // Nothing left to do the second time.
        $this->artisan('exams:submit-expired')->expectsOutput('Submitted 0 expired attempt(s).');
    }

    public function test_it_runs_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'exams:submit-expired'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
