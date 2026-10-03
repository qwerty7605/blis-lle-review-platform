<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * maintenance_mode used to be a dead switch: an administrator could turn it
 * on, the value was stored and read back by the settings screen, and the
 * platform carried on serving every request.
 *
 * The switch also has to stay reversible from the UI, which is what the
 * always-open endpoints are for -- blocking sign-in during maintenance would
 * lock the last administrator out of the only screen that turns it off.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SystemSettings::class)->forget();
    }

    public function test_students_and_staff_are_turned_away_while_maintenance_is_on(): void
    {
        $this->enableMaintenance();

        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Sanctum::actingAs($student);
        $this->getJson('/api/student/dashboard')
            ->assertStatus(503)
            ->assertJsonPath('maintenance_mode', true);

        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        Sanctum::actingAs($staff);
        $this->getJson('/api/exams')->assertStatus(503);
    }

    public function test_an_administrator_still_gets_through(): void
    {
        $this->enableMaintenance();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users')->assertOk();
    }

    public function test_signing_in_stays_open_so_the_switch_can_be_turned_off_again(): void
    {
        $this->enableMaintenance();

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@lnu.edu.ph',
            'password' => 'Secret12345!',
            'email_verified_at' => now(),
        ]);

        // Signed out, mid-maintenance: the administrator can still get in...
        $this->postJson('/api/auth/login', [
            'identifier' => 'admin@lnu.edu.ph',
            'password' => 'Secret12345!',
        ])->assertOk();

        // ...and turn it back off.
        Sanctum::actingAs($admin);
        $this->putJson('/api/settings/system', ['maintenance_mode' => false])->assertOk();

        app(SystemSettings::class)->forget();
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Sanctum::actingAs($student);
        $this->getJson('/api/student/dashboard')->assertOk();
    }

    public function test_registration_is_closed_while_maintenance_is_on(): void
    {
        $this->enableMaintenance();

        $this->postJson('/api/auth/register', [])->assertStatus(503);
    }

    public function test_nothing_is_blocked_while_maintenance_is_off(): void
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Sanctum::actingAs($student);

        $this->getJson('/api/student/dashboard')->assertOk();
    }

    private function enableMaintenance(): void
    {
        SystemSetting::updateOrCreate(['key' => 'maintenance_mode'], ['value' => '1']);
        app(SystemSettings::class)->forget();
    }
}
