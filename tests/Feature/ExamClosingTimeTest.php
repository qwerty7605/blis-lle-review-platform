<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What an exam's end time means for a student already taking it, chosen per
 * exam (ends_at_close). Off, the default: the end time stops new starts and
 * anyone who started before it keeps their full duration, and can come back
 * to finish after it. On: every attempt is handed in at the end time.
 *
 * Before this, the timer always ran the full duration but resuming went
 * through the same end-time check as starting, so a student who left after
 * the end time could not get back in to finish.
 */
class ExamClosingTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_letting_them_finish_gives_the_full_duration_past_the_end_time(): void
    {
        [, $room, $exam] = $this->scenario(false);

        $attempt = $this->start($exam, $room)->assertCreated();

        // 30 minutes, though the exam closes in 10.
        $this->assertEqualsWithDelta(
            now()->addMinutes(30)->getTimestamp(),
            ExamAttempt::find($attempt->json('attempt.id'))->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_a_student_who_started_before_the_end_time_can_come_back_after_it(): void
    {
        [, $room, $exam] = $this->scenario(false);
        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');

        $this->travel(20)->minutes();

        $this->start($exam, $room)
            ->assertOk()
            ->assertJsonPath('resumed', true)
            ->assertJsonPath('attempt.id', $attemptId);

        $question = QuestionBankQuestion::query()->first();
        $this->patchJson("/api/student/exam-attempts/{$attemptId}/answers", [
            'question_id' => $question->id,
            'selected_option_id' => $question->options()->where('is_correct', true)->value('id'),
        ])->assertOk();
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertOk();
    }

    public function test_the_room_tells_the_student_until_when_they_can_finish(): void
    {
        [, $room, $exam] = $this->scenario(false);
        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        $deadline = ExamAttempt::find($attemptId)->expires_at;

        $this->travel(20)->minutes();

        $row = collect($this->getJson("/api/rooms/{$room->id}")->assertOk()->json('room.assigned_exams'))
            ->firstWhere('id', $exam->id);

        $this->assertNotNull($row);
        $this->assertSame('in_progress', $row['student_attempt_state']);
        $this->assertEqualsWithDelta($deadline->getTimestamp(), strtotime($row['student_attempt_expires_at']), 1);
    }

    public function test_nobody_starts_after_the_end_time(): void
    {
        [, $room, $exam] = $this->scenario(false);

        $this->travel(20)->minutes();

        $this->start($exam, $room)->assertStatus(422);
        $this->assertSame(0, ExamAttempt::count());
    }

    public function test_ending_for_everyone_cuts_the_attempt_at_the_end_time(): void
    {
        [, $room, $exam] = $this->scenario(true);

        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');

        $this->assertEqualsWithDelta(
            $exam->schedule_end_at->getTimestamp(),
            ExamAttempt::find($attemptId)->expires_at->getTimestamp(),
            1,
        );
    }

    public function test_after_the_end_time_an_attempt_that_ends_there_is_handed_in_not_resumed(): void
    {
        [, $room, $exam] = $this->scenario(true);
        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');

        $this->travel(20)->minutes();

        $this->start($exam, $room)->assertStatus(422);
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, ExamAttempt::find($attemptId)->status);
    }

    public function test_changing_the_end_time_or_the_choice_moves_the_deadlines_of_attempts_under_way(): void
    {
        [$student, $room, $exam, $staff] = $this->scenario(true);
        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        $startedAt = ExamAttempt::find($attemptId)->started_at;

        Sanctum::actingAs($staff);

        // A later end time: the deadline follows it.
        $laterEnd = now()->addMinutes(20)->startOfMinute();
        $this->patchJson("/api/exams/{$exam->id}", ['schedule_end_at' => $laterEnd->toDateTimeString()])->assertOk();
        $this->assertEqualsWithDelta($laterEnd->getTimestamp(), ExamAttempt::find($attemptId)->expires_at->getTimestamp(), 1);

        // Letting them finish instead: the full 30 minutes come back.
        $this->patchJson("/api/exams/{$exam->id}", ['ends_at_close' => false])->assertOk();
        $this->assertEqualsWithDelta($startedAt->copy()->addMinutes(30)->getTimestamp(), ExamAttempt::find($attemptId)->expires_at->getTimestamp(), 1);
    }

    public function test_the_choice_is_saved_with_a_new_exam(): void
    {
        [, , $exam, $staff] = $this->scenario(false);
        Sanctum::actingAs($staff);

        $id = $this->postJson('/api/exams', [
            'title' => 'Strict Quiz',
            'question_bank_ids' => [$exam->question_bank_id],
            'total_items' => 3,
            'duration_minutes' => 30,
            'schedule_start_at' => now()->addHour()->toDateTimeString(),
            'schedule_end_at' => now()->addHours(2)->toDateTimeString(),
            'ends_at_close' => true,
        ])->assertCreated()->json('exam.id');

        $this->assertTrue(Exam::find($id)->ends_at_close);
    }

    private function start(Exam $exam, Room $room): TestResponse
    {
        return $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id]);
    }

    /** @return array{0: User, 1: Room, 2: Exam, 3: User} */
    private function scenario(bool $endsAtClose): array
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => 'BLIS 2A', 'code' => 'STRT2A', 'created_by' => $staff->id]);
        $room->members()->attach($student->id);

        $bank = QuestionBank::create([
            'title' => 'Reference Set',
            'subject' => 'Reference, Bibliography and User Services',
            'source_filename' => 'reference.docx',
            'total_items' => 3,
            'created_by' => $staff->id,
        ]);

        foreach ([1, 2, 3] as $itemNumber) {
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => 'Reference, Bibliography and User Services',
                'item_number' => $itemNumber,
                'question_text' => "Question {$itemNumber}",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Correct answer',
            ])->options()->createMany([
                ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Correct answer', 'is_correct' => true],
                ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Incorrect answer', 'is_correct' => false],
            ]);
        }

        $exam = Exam::create([
            'title' => 'Reference Quiz',
            'subject' => 'Reference, Bibliography and User Services',
            'question_bank_id' => $bank->id,
            'total_items' => 3,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'schedule_start_at' => now()->subHour(),
            'schedule_end_at' => now()->addMinutes(10),
            'ends_at_close' => $endsAtClose,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($student);

        return [$student, $room, $exam, $staff];
    }
}
