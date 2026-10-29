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
 * Starting an exam looks for an attempt and creates one as a single locked
 * step. It used to be two steps, so requests arriving together -- Start
 * pressed twice, the exam open in two tabs -- each created an attempt and
 * stepped past the one-take and retake limits.
 *
 * Two requests cannot be raced from inside one test process, so these pin the
 * decisions that step has to keep making: resume, refuse, or start afresh.
 */
class ExamAttemptStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_again_resumes_the_attempt_instead_of_creating_another(): void
    {
        [$student, $room, $exam] = $this->scenario(true);

        $first = $this->start($exam, $room)->assertCreated()->assertJsonPath('resumed', false);
        $second = $this->start($exam, $room)->assertOk()->assertJsonPath('resumed', true);

        $this->assertSame($first->json('attempt.id'), $second->json('attempt.id'));
        $this->assertSame(1, $this->attemptCount($student, $exam));
    }

    public function test_a_one_take_exam_cannot_be_started_again_once_submitted(): void
    {
        [$student, $room, $exam] = $this->scenario(true);

        $this->startAndSubmit($exam, $room);

        $this->start($exam, $room)->assertStatus(422);
        $this->assertSame(1, $this->attemptCount($student, $exam));
    }

    public function test_one_retake_is_allowed_and_a_third_attempt_is_not(): void
    {
        [$student, $room, $exam] = $this->scenario(false);

        $first = $this->startAndSubmit($exam, $room);
        $retake = $this->startAndSubmit($exam, $room);

        $this->assertNotSame($first, $retake);

        $this->start($exam, $room)->assertStatus(422);
        $this->assertSame(2, $this->attemptCount($student, $exam));
    }

    public function test_an_attempt_that_ran_out_of_time_is_closed_before_the_retake_starts(): void
    {
        [$student, $room, $exam] = $this->scenario(false);

        $expiredId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        ExamAttempt::whereKey($expiredId)->update(['expires_at' => now()->subMinute()]);

        $retake = $this->start($exam, $room)->assertCreated()->assertJsonPath('resumed', false);

        $this->assertNotSame($expiredId, $retake->json('attempt.id'));
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, ExamAttempt::find($expiredId)->status);
        $this->assertSame(2, $this->attemptCount($student, $exam));
    }

    public function test_running_out_of_time_on_a_one_take_exam_uses_up_the_take(): void
    {
        [$student, $room, $exam] = $this->scenario(true);

        $expiredId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        ExamAttempt::whereKey($expiredId)->update(['expires_at' => now()->subMinute()]);

        // Closed as submitted, and that was the one take.
        $this->start($exam, $room)->assertStatus(422);

        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, ExamAttempt::find($expiredId)->status);
        $this->assertSame(1, $this->attemptCount($student, $exam));
    }

    public function test_an_exam_archived_from_the_room_cannot_be_started(): void
    {
        [$student, $room, $exam] = $this->scenario(false);

        $this->archiveFromRoom($exam, $room);

        $this->start($exam, $room)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This exam has been archived from this room and can no longer be started.');

        $this->assertSame(0, $this->attemptCount($student, $exam));
    }

    public function test_an_attempt_under_way_can_still_be_resumed_after_the_exam_is_archived(): void
    {
        [$student, $room, $exam] = $this->scenario(false);

        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        $this->archiveFromRoom($exam, $room);

        $this->start($exam, $room)
            ->assertOk()
            ->assertJsonPath('resumed', true)
            ->assertJsonPath('attempt.id', $attemptId);

        // Finishing it is allowed; the retake that would have followed is not.
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertOk();
        $this->start($exam, $room)->assertStatus(422);

        $this->assertSame(1, $this->attemptCount($student, $exam));
    }

    public function test_an_exam_under_way_in_one_room_cannot_be_started_in_another(): void
    {
        [$student, $room, $exam] = $this->scenario(true);
        $otherRoom = $this->anotherRoomWith($student, $exam);

        $attemptId = $this->start($exam, $room)->assertCreated()->json('attempt.id');

        $this->start($exam, $otherRoom)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You already have this exam in progress in BLIS 2A. Finish it there first.');

        $this->assertSame(1, $this->attemptCount($student, $exam));

        // Still resumable where it was started, and the one take is spent
        // for both rooms once it is submitted.
        $this->start($exam, $room)->assertOk()->assertJsonPath('attempt.id', $attemptId);
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertOk();

        $this->start($exam, $otherRoom)->assertStatus(422);
        $this->assertSame(1, $this->attemptCount($student, $exam));
    }

    public function test_an_attempt_that_ran_out_of_time_in_another_room_does_not_block_the_retake(): void
    {
        [$student, $room, $exam] = $this->scenario(false);
        $otherRoom = $this->anotherRoomWith($student, $exam);

        $expiredId = $this->start($exam, $room)->assertCreated()->json('attempt.id');
        ExamAttempt::whereKey($expiredId)->update(['expires_at' => now()->subMinute()]);

        $this->start($exam, $otherRoom)->assertCreated()->assertJsonPath('resumed', false);

        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, ExamAttempt::find($expiredId)->status);
        $this->assertSame(2, $this->attemptCount($student, $exam));
    }

    private function archiveFromRoom(Exam $exam, Room $room): void
    {
        $exam->rooms()->updateExistingPivot($room->id, [
            'archived_at' => now(),
            'archived_by' => $exam->created_by,
        ]);
    }

    private function anotherRoomWith(User $student, Exam $exam): Room
    {
        $room = Room::create(['name' => 'BLIS 2B', 'code' => 'STRT2B', 'created_by' => $exam->created_by]);
        $room->members()->attach($student->id);

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $exam->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $room;
    }

    private function start(Exam $exam, Room $room): TestResponse
    {
        return $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id]);
    }

    private function startAndSubmit(Exam $exam, Room $room): int
    {
        $attemptId = (int) $this->start($exam, $room)->assertCreated()->json('attempt.id');

        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertOk();

        return $attemptId;
    }

    private function attemptCount(User $student, Exam $exam): int
    {
        return ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->where('user_id', $student->id)
            ->count();
    }

    /** @return array{0: User, 1: Room, 2: Exam} */
    private function scenario(bool $oneTakeOnly): array
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
            'one_take_only' => $oneTakeOnly,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($student);

        return [$student, $room, $exam];
    }
}
