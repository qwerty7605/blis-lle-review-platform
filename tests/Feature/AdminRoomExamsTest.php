<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An administrator oversees every room, but a room opened by one showed its
 * students and nothing else: the exams were left out of the reply, and the
 * Live Board answered only the teacher who owned the room.
 *
 * An administrator now sees what that teacher sees there. Other teachers and
 * students still do not.
 */
class AdminRoomExamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_sees_the_exams_of_a_room_with_how_many_have_submitted(): void
    {
        [$room, $exam, $archivedExam, $student] = $this->scenario();

        Sanctum::actingAs($student);
        $attemptId = $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id])
            ->assertCreated()
            ->json('attempt.id');
        $this->postJson("/api/student/exam-attempts/{$attemptId}/submit")->assertOk();

        Sanctum::actingAs($this->admin());
        $reply = $this->getJson("/api/rooms/{$room->id}")->assertOk();

        $this->assertSame([$exam->id], array_column($reply->json('room.assigned_exams'), 'id'));
        $this->assertSame('Reference Quiz', $reply->json('room.assigned_exams.0.title'));
        $this->assertSame('1 / 2 submitted', $reply->json('room.assigned_exams.0.progress'));

        $this->assertSame([$archivedExam->id], array_column($reply->json('room.archived_exams'), 'id'));

        // What an administrator already saw is still there.
        $this->assertCount(2, $reply->json('room.members'));
    }

    public function test_an_administrator_can_open_the_live_board_of_any_room(): void
    {
        [$room, $exam, , $student] = $this->scenario();

        Sanctum::actingAs($student);
        $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id])->assertCreated();

        Sanctum::actingAs($this->admin());
        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")
            ->assertOk()
            ->assertJsonPath('summary.students_total', 2)
            ->assertJsonPath('summary.attempts_started', 1)
            ->assertJsonPath('summary.attempts_submitted', 0);
    }

    public function test_the_live_board_still_refuses_an_exam_that_is_not_in_the_room(): void
    {
        [$room, $exam] = $this->scenario();
        $otherRoom = Room::create(['name' => 'BLIS 3A', 'code' => 'ADM3A1', 'created_by' => $exam->created_by]);

        Sanctum::actingAs($this->admin());
        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$otherRoom->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This exam is not assigned to the selected room.');
    }

    public function test_another_teacher_and_a_student_still_cannot_read_the_room_or_its_live_board(): void
    {
        [$room, $exam, , $student] = $this->scenario();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]));
        $this->getJson("/api/rooms/{$room->id}")->assertStatus(403);
        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")->assertStatus(403);

        Sanctum::actingAs($student);
        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")->assertStatus(403);
    }

    public function test_the_rooms_own_teacher_sees_the_same_as_before(): void
    {
        [$room, $exam] = $this->scenario();

        Sanctum::actingAs(User::find($room->created_by));

        $this->assertSame(
            [$exam->id],
            array_column($this->getJson("/api/rooms/{$room->id}")->assertOk()->json('room.assigned_exams'), 'id'),
        );
        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")->assertOk();
    }

    /** @return array{0: Room, 1: Exam, 2: Exam, 3: User} */
    private function scenario(): array
    {
        $teacher = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $classmate = User::factory()->create(['role' => User::ROLE_STUDENT]);

        $room = Room::create(['name' => 'BLIS 2A', 'code' => 'ADM2A1', 'created_by' => $teacher->id]);
        $room->members()->attach([$student->id, $classmate->id]);

        $bank = QuestionBank::create([
            'title' => 'Reference Set',
            'subject' => 'Reference, Bibliography and User Services',
            'source_filename' => 'reference.docx',
            'total_items' => 2,
            'created_by' => $teacher->id,
        ]);

        foreach ([1, 2] as $itemNumber) {
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

        $makeExam = fn (string $title): Exam => Exam::create([
            'title' => $title,
            'subject' => 'Reference, Bibliography and User Services',
            'question_bank_id' => $bank->id,
            'total_items' => 2,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $teacher->id,
        ]);

        $exam = $makeExam('Reference Quiz');
        $archivedExam = $makeExam('Last Term Quiz');

        $exam->rooms()->attach($room->id, ['assigned_by' => $teacher->id, 'created_at' => now(), 'updated_at' => now()]);
        $archivedExam->rooms()->attach($room->id, [
            'assigned_by' => $teacher->id,
            'archived_at' => now(),
            'archived_by' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$room, $exam, $archivedExam, $student];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }
}
