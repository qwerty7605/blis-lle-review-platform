<?php

namespace Tests\Feature\Exams;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Extra time from the Live Board: only the students given it get it, past
 * the closing time if need be, and it survives a later change to the
 * closing time. The exam's own duration stays the same for everyone.
 */
class ExtraTimeTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private Room $room;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->room = $this->makeRoom($this->teacher);
        $this->exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 4), [$this->room]);
    }

    public function test_a_teacher_gives_one_student_more_time_and_nobody_else(): void
    {
        [$first, $second] = [$this->student(), $this->student()];
        $mine = $this->makeOpenAttempt($this->exam, $this->room, $first, [], now()->subMinutes(10));
        $theirs = $this->makeOpenAttempt($this->exam, $this->room, $second, [], now()->subMinutes(10));
        $theirDeadline = $theirs->expires_at->copy();

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$mine->id}/extra-time", [
            'minutes' => 10,
            'reason' => 'Internet dropped',
        ])->assertOk()->assertJsonPath('attempt.extra_minutes', 10);

        $mine->refresh();
        $this->assertSame(10, $mine->extra_minutes);
        $this->assertTrue($mine->expires_at->eq($mine->started_at->copy()->addMinutes(40)));
        $this->assertTrue($theirs->fresh()->expires_at->eq($theirDeadline));
        $this->assertSame(30, (int) $this->exam->fresh()->duration_minutes);

        $audit = AuditLog::query()->where('action', 'exam.attempt.extra_time')->firstOrFail();
        $this->assertSame(10, $audit->metadata['minutes']);
        $this->assertSame('Internet dropped', $audit->metadata['reason']);

        // The student's own exam page counts down from the new deadline.
        Sanctum::actingAs($first);
        $remaining = $this->getJson("/api/student/exam-attempts/{$mine->id}")->assertOk()->json('attempt.remaining_seconds');
        $this->assertGreaterThan(25 * 60, $remaining);
    }

    public function test_extra_time_runs_past_the_closing_time(): void
    {
        $this->exam->update(['schedule_end_at' => now()->addMinutes(5), 'ends_at_close' => true]);
        $student = $this->student();
        $attempt = $this->makeOpenAttempt($this->exam, $this->room, $student, [], now()->subMinutes(10));
        $attempt->forceFill(['expires_at' => $this->exam->fresh()->schedule_end_at])->save();

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attempt->id}/extra-time", ['minutes' => 10])->assertOk();

        $this->assertTrue($attempt->fresh()->expires_at->eq($this->exam->fresh()->schedule_end_at->copy()->addMinutes(10)));
    }

    public function test_extra_time_survives_a_later_change_to_the_closing_time(): void
    {
        $this->exam->update(['schedule_end_at' => now()->addHour(), 'ends_at_close' => true]);
        $attempt = $this->makeOpenAttempt($this->exam, $this->room, $this->student(), [], now()->subMinutes(10));

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attempt->id}/extra-time", ['minutes' => 5])->assertOk();
        $this->patchJson("/api/exams/{$this->exam->id}", ['schedule_end_at' => now()->addHours(2)->toIso8601String()])->assertOk();

        $attempt->refresh();
        $this->assertTrue($attempt->expires_at->eq($attempt->started_at->copy()->addMinutes(35)));
    }

    public function test_everyone_taking_it_in_the_room_gets_the_same_extra_time(): void
    {
        $taking = [$this->student(), $this->student()];
        $done = $this->student();
        $attempts = array_map(fn (User $student) => $this->makeOpenAttempt($this->exam, $this->room, $student, [], now()->subMinutes(10)), $taking);
        $submitted = $this->makeSubmittedAttempt($this->exam, $this->room, $done, [true, true, true, true]);

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/exams/{$this->exam->id}/extra-time", ['room_id' => $this->room->id, 'minutes' => 5])
            ->assertOk()
            ->assertJsonCount(2, 'attempts');

        foreach ($attempts as $attempt) {
            $this->assertSame(5, $attempt->fresh()->extra_minutes);
        }

        $this->assertSame(0, (int) $submitted->fresh()->extra_minutes);
    }

    public function test_an_attempt_that_has_ended_gets_none(): void
    {
        $attempt = $this->makeSubmittedAttempt($this->exam, $this->room, $this->student(), [true, false, true, true]);

        Sanctum::actingAs($this->teacher);

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attempt->id}/extra-time", ['minutes' => 5])->assertStatus(422);
        $this->postJson("/api/exams/{$this->exam->id}/extra-time", ['room_id' => $this->room->id, 'minutes' => 5])->assertStatus(422);
    }

    public function test_only_the_exams_teacher_or_an_administrator_gives_extra_time(): void
    {
        $student = $this->student();
        $attempt = $this->makeOpenAttempt($this->exam, $this->room, $student);
        $url = "/api/exams/{$this->exam->id}/attempts/{$attempt->id}/extra-time";

        Sanctum::actingAs($this->makeTeacher());
        $this->postJson($url, ['minutes' => 5])->assertForbidden();

        Sanctum::actingAs($student);
        $this->postJson($url, ['minutes' => 5])->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->postJson($url, ['minutes' => 5])->assertOk();

        $this->postJson($url, ['minutes' => 0])->assertStatus(422);
        $this->postJson($url, ['minutes' => 500])->assertStatus(422);
    }

    private function student(): User
    {
        $student = $this->makeStudent();
        $this->room->members()->attach($student->id);

        return $student;
    }
}
