<?php

namespace Tests\Feature\Rooms;

use App\Models\AuditLog;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Room routes (routes/api/rooms.php) not already covered elsewhere: renaming
 * a room, the gradebook PDF, joining with a code that does not exist,
 * removing someone who is not a student or not a member, leaving a room one
 * is not in, archiving and restoring an exam in a room, and who may do each.
 */
class RoomRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->room = $this->makeRoom($this->teacher, [$this->student], ['name' => 'BLIS 4A', 'code' => 'ROOM4A']);

        Sanctum::actingAs($this->teacher);
    }

    public function test_the_owner_renames_a_room_and_it_is_audited(): void
    {
        $this->patchJson("/api/rooms/{$this->room->id}", ['name' => 'BLIS 4B'])
            ->assertOk()
            ->assertJsonPath('room.name', 'BLIS 4B');

        $log = AuditLog::where('action', 'room.update')->firstOrFail();
        $this->assertSame(['from' => 'BLIS 4A', 'to' => 'BLIS 4B'], array_intersect_key($log->metadata, ['from' => 1, 'to' => 1]));
    }

    public function test_a_room_name_is_required(): void
    {
        $this->patchJson("/api/rooms/{$this->room->id}", ['name' => ''])->assertJsonValidationErrors('name');
    }

    public function test_only_the_owner_or_an_administrator_manages_a_room(): void
    {
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 1), [$this->room]);
        $id = $this->room->id;

        foreach ([$this->makeTeacher(), $this->student] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->patchJson("/api/rooms/{$id}", ['name' => 'x'])->assertForbidden();
            $this->deleteJson("/api/rooms/{$id}")->assertForbidden();
            $this->postJson("/api/rooms/{$id}/restore")->assertForbidden();
            $this->deleteJson("/api/rooms/{$id}/members/{$this->student->id}")->assertForbidden();
            $this->getJson("/api/rooms/{$id}/export-grades")->assertForbidden();
            $this->postJson("/api/rooms/{$id}/exams/{$exam->id}/archive")->assertForbidden();
            $this->postJson("/api/rooms/{$id}/exams/{$exam->id}/restore")->assertForbidden();
        }

        Sanctum::actingAs($this->makeStudent());
        $this->postJson('/api/rooms', ['name' => 'Mine'])->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->patchJson("/api/rooms/{$id}", ['name' => 'Renamed by admin'])->assertOk();
    }

    public function test_a_teacher_cannot_open_another_teacher_s_room(): void
    {
        Sanctum::actingAs($this->makeTeacher());

        $this->getJson("/api/rooms/{$this->room->id}")->assertForbidden();
    }

    public function test_the_gradebook_downloads_as_a_pdf(): void
    {
        $response = $this->get("/api/rooms/{$this->room->id}/export-grades?format=pdf")->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('room4a-grades-', $response->headers->get('Content-Disposition'));
    }

    public function test_a_code_that_matches_no_room_is_not_found(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->postJson('/api/rooms/join', ['code' => 'NOPE00'])->assertNotFound()->assertJsonPath('message', 'Room code not found');
    }

    public function test_only_students_who_are_members_can_be_removed(): void
    {
        $otherTeacher = $this->makeTeacher();
        $this->deleteJson("/api/rooms/{$this->room->id}/members/{$otherTeacher->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only student members can be removed from rooms.');

        $outsider = $this->makeStudent();
        $this->deleteJson("/api/rooms/{$this->room->id}/members/{$outsider->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Student is not enrolled in this room.');
    }

    public function test_leaving_a_room_one_is_not_in_is_refused_and_staff_do_not_leave_rooms(): void
    {
        Sanctum::actingAs($this->makeStudent());
        $this->deleteJson("/api/rooms/{$this->room->id}/leave")
            ->assertNotFound()
            ->assertJsonPath('message', 'You are not enrolled in this room');

        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/api/rooms/{$this->room->id}/leave")->assertForbidden();
    }

    public function test_an_exam_is_archived_and_restored_in_a_room_once_each(): void
    {
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 1), [$this->room]);
        $base = "/api/rooms/{$this->room->id}/exams/{$exam->id}";

        $this->postJson("{$base}/restore")->assertStatus(422)->assertJsonPath('message', 'This exam is already active in the selected room.');
        $this->postJson("{$base}/archive")->assertOk();
        $this->postJson("{$base}/archive")->assertStatus(422)->assertJsonPath('message', 'This exam is already archived in the selected room.');
        $this->postJson("{$base}/restore")->assertOk();
    }

    public function test_an_exam_not_in_the_room_has_no_assignment_to_archive_or_restore(): void
    {
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 1));

        $this->postJson("/api/rooms/{$this->room->id}/exams/{$exam->id}/archive")
            ->assertNotFound()->assertJsonPath('message', 'Exam assignment not found for this room.');
        $this->postJson("/api/rooms/{$this->room->id}/exams/{$exam->id}/restore")
            ->assertNotFound()->assertJsonPath('message', 'Exam assignment not found for this room.');
    }

    public function test_a_teacher_cannot_archive_another_teacher_s_exam_from_their_room(): void
    {
        $others = $this->makeExam($this->makeTeacher(), null, [$this->room]);

        $this->postJson("/api/rooms/{$this->room->id}/exams/{$others->id}/archive")->assertForbidden();
        $this->postJson("/api/rooms/{$this->room->id}/exams/{$others->id}/restore")->assertForbidden();
    }

    public function test_a_disabled_account_is_signed_out_of_every_room_route(): void
    {
        $exam = $this->makeExam($this->teacher, null, [$this->room]);
        $id = $this->room->id;
        $this->teacher->forceFill(['is_active' => false])->save();

        foreach ([
            ['GET', '/api/rooms'],
            ['GET', "/api/rooms/{$id}"],
            ['PATCH', "/api/rooms/{$id}"],
            ['DELETE', "/api/rooms/{$id}"],
            ['POST', "/api/rooms/{$id}/archive"],
            ['POST', "/api/rooms/{$id}/restore"],
            ['POST', '/api/rooms'],
            ['POST', '/api/rooms/join'],
            ['DELETE', "/api/rooms/{$id}/members/{$this->student->id}"],
            ['DELETE', "/api/rooms/{$id}/leave"],
            ['GET', "/api/rooms/{$id}/export-grades"],
            ['POST', "/api/rooms/{$id}/exams/{$exam->id}/archive"],
            ['POST', "/api/rooms/{$id}/exams/{$exam->id}/restore"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
