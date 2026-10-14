<?php

namespace Tests\Unit\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ExamDeliveryModeService;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The three small services: RoleService (who counts as staff),
 * ExamDeliveryModeService (every exam uses open navigation), and
 * AuditLogService (one row per recorded action).
 */
class SmallServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_told_apart(): void
    {
        $roles = new RoleService();
        $admin = new User(['role' => User::ROLE_ADMIN]);
        $teacher = new User(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = new User(['role' => User::ROLE_STUDENT]);

        $this->assertTrue($roles->isAdmin($admin));
        $this->assertFalse($roles->isAdmin($teacher));
        $this->assertTrue($roles->isStaffMasterExaminer($teacher));
        $this->assertFalse($roles->isStaffMasterExaminer($admin));
        $this->assertTrue($roles->canManageRooms($admin));
        $this->assertTrue($roles->canManageRooms($teacher));
        $this->assertFalse($roles->canManageRooms($student));
    }

    public function test_every_delivery_mode_becomes_open_navigation(): void
    {
        $service = new ExamDeliveryModeService();

        foreach ([null, '', 'one_by_one', 'open_navigation', 'anything'] as $mode) {
            $this->assertSame(Exam::DELIVERY_MODE_OPEN_NAVIGATION, $service->normalize($mode));
        }
    }

    public function test_an_action_is_recorded_with_who_did_it_and_from_where(): void
    {
        $actor = User::factory()->create();
        $request = Request::create('/api/x', 'POST', server: ['REMOTE_ADDR' => '10.1.2.3']);

        (new AuditLogService())->record($actor, 'room.create', 'room', 9, 'Room created', ['code' => 'ABC'], $request);

        $log = AuditLog::firstOrFail();
        $this->assertSame($actor->id, $log->actor_id);
        $this->assertSame('room.create', $log->action);
        $this->assertSame('room', $log->target_type);
        $this->assertSame(9, (int) $log->target_id);
        $this->assertSame(['code' => 'ABC'], $log->metadata);
        $this->assertSame('10.1.2.3', $log->ip_address);
    }

    public function test_an_action_with_no_actor_or_request_is_still_recorded(): void
    {
        (new AuditLogService())->record(null, 'system.cleanup');

        $log = AuditLog::firstOrFail();
        $this->assertNull($log->actor_id);
        $this->assertNull($log->ip_address);
        $this->assertSame([], $log->metadata);
    }
}
