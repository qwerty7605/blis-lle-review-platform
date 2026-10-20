<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * "Accepting new students" on a room: off, the code lets nobody new in and the
 * class already in the room stays as it is.
 */
class RoomJoiningSwitchTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_a_closed_room_turns_new_students_away_and_keeps_its_class(): void
    {
        $teacher = $this->makeTeacher();
        $member = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$member]);

        Sanctum::actingAs($teacher);
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])
            ->assertOk()
            ->assertJsonPath('message', 'This room is no longer accepting new students.');

        $this->assertNotNull($room->fresh()->joins_closed_at);
        $this->getJson("/api/rooms/{$room->id}")->assertOk()->assertJsonPath('room.members_count', 1);
        $this->assertNotNull($this->getJson('/api/rooms')->json('rooms.0.joins_closed_at'));

        Sanctum::actingAs($this->makeStudent());
        $this->postJson('/api/rooms/join', ['code' => $room->code])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This room is not accepting new students. Ask your teacher to open it.');
        $this->assertDatabaseCount('room_user', 1);

        // Someone already in the room typing the code again is not turned away.
        Sanctum::actingAs($member);
        $this->postJson('/api/rooms/join', ['code' => $room->code])->assertOk();
        $this->getJson("/api/rooms/{$room->id}")->assertOk();
    }

    public function test_reopening_lets_the_same_code_work_again(): void
    {
        $teacher = $this->makeTeacher();
        $room = $this->makeRoom($teacher);
        $code = $room->code;

        Sanctum::actingAs($teacher);
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])->assertOk();
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => true])
            ->assertOk()
            ->assertJsonPath('joins_closed_at', null);

        $this->assertSame($code, $room->fresh()->code);

        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $this->postJson('/api/rooms/join', ['code' => $code])->assertOk();
        $this->assertDatabaseHas('room_user', ['room_id' => $room->id, 'user_id' => $student->id]);
    }

    public function test_setting_it_to_what_it_already_is_changes_nothing(): void
    {
        $teacher = $this->makeTeacher();
        $room = $this->makeRoom($teacher);

        Sanctum::actingAs($teacher);
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])->assertOk();
        $closedAt = $room->fresh()->joins_closed_at;

        $this->travel(5)->minutes();
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])
            ->assertOk()
            ->assertJsonPath('message', 'This room is already closed to new students.');

        $this->assertEquals($closedAt, $room->fresh()->joins_closed_at);
        $this->assertSame(1, AuditLog::where('action', 'room.joins.close')->count());
    }

    public function test_only_the_room_s_teacher_or_an_administrator_changes_it(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);

        Sanctum::actingAs($this->makeTeacher());
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])->assertForbidden();

        Sanctum::actingAs($student);
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])->assertForbidden();

        $this->assertNull($room->fresh()->joins_closed_at);

        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);
        $this->putJson("/api/rooms/{$room->id}/joining", ['accepting_students' => false])->assertOk();
        $this->putJson("/api/rooms/{$room->id}/joining", [])->assertStatus(422);

        $log = AuditLog::where('action', 'room.joins.close')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($room->id, (int) $log->target_id);
    }
}
