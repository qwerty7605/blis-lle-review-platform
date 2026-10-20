<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomGradeExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_grade_export_defaults_to_xlsx(): void
    {
        [$staff, $room] = $this->makeRoomContext();
        $student = $this->makeUser('room-export-student@example.com', User::ROLE_STUDENT, '2302001');
        $room->members()->attach($student->id);

        $exam = $this->makeExam($staff, 'Cataloging Quiz', 'Cataloging', 20);
        $this->assignExamToRoom($exam, $room, $staff);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 20,
            'duration_minutes' => 60,
            'answered_count' => 20,
            'correct_answers' => 17,
            'score_percent' => 85,
            'started_at' => now()->subDay(),
            'submitted_at' => now()->subDay()->addHour(),
        ]);

        Sanctum::actingAs($staff);

        $response = $this->get("/api/rooms/{$room->id}/export-grades");

        $response->assertOk()->assertDownload();
        $this->assertStringContainsString(
            '.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_room_grade_export_supports_csv(): void
    {
        [$staff, $room] = $this->makeRoomContext();
        $student = $this->makeUser('room-export-csv@example.com', User::ROLE_STUDENT, '2302002');
        $room->members()->attach($student->id);

        $exam = $this->makeExam($staff, 'Reference Services Mock', 'Reference Services', 40);
        $this->assignExamToRoom($exam, $room, $staff);

        ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 40,
            'duration_minutes' => 60,
            'answered_count' => 40,
            'correct_answers' => 32,
            'score_percent' => 80,
            'started_at' => now()->subDay(),
            'submitted_at' => now()->subDay()->addHour(),
        ]);

        Sanctum::actingAs($staff);

        $response = $this->get("/api/rooms/{$room->id}/export-grades?format=csv");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Average %', $csv);
        $this->assertStringContainsString('Reference Services Mock (%)', $csv);
        $this->assertStringContainsString('80', $csv);
    }

    public function test_room_grade_export_rejects_invalid_format(): void
    {
        [$staff, $room] = $this->makeRoomContext();

        Sanctum::actingAs($staff);

        $this->getJson("/api/rooms/{$room->id}/export-grades?format=docx")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    /**
     * @return array{0: User, 1: Room}
     */
    private function makeRoomContext(): array
    {
        $staff = $this->makeUser('room-export-teacher@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $room = Room::create([
            'name' => 'BLIS 2A',
            'code' => 'B2AEXP',
            'created_by' => $staff->id,
        ]);

        return [$staff, $room];
    }

    private function makeUser(string $email, string $role, ?string $studentId = null): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'student_id' => $studentId,
            'is_active' => true,
        ]);
    }

    private function makeExam(User $staff, string $title, ?string $subject = null, int $totalItems = 50): Exam
    {
        return Exam::create([
            'title' => $title,
            'subject' => $subject,
            'description' => null,
            'total_items' => $totalItems,
            'duration_minutes' => 60,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);
    }

    private function assignExamToRoom(Exam $exam, Room $room, User $staff): void
    {
        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'archived_at' => null,
            'archived_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
