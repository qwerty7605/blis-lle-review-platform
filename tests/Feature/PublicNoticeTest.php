<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The announcement banner was write-only: an administrator could save one, but
 * /settings/system is staff-and-admin gated, so the people the announcement is
 * addressed to -- students -- had no endpoint that would return it, and no
 * screen rendered it. This is the endpoint they can actually reach.
 */
class PublicNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SystemSettings::class)->forget();
    }

    public function test_the_notice_is_readable_without_signing_in(): void
    {
        SystemSetting::updateOrCreate(['key' => 'announcement_banner'], ['value' => 'Server upgrade at 9 PM.']);
        app(SystemSettings::class)->forget();

        $this->getJson('/api/settings/public/notice')
            ->assertOk()
            ->assertJsonPath('announcement_banner', 'Server upgrade at 9 PM.')
            ->assertJsonMissingPath('maintenance_mode');
    }

    public function test_a_student_can_read_the_announcement_an_admin_saved(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Sanctum::actingAs($admin);
        $this->putJson('/api/settings/system', ['announcement_banner' => 'Midterms moved to Friday.'])->assertOk();

        app(SystemSettings::class)->forget();

        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Sanctum::actingAs($student);

        $this->getJson('/api/settings/public/notice')
            ->assertOk()
            ->assertJsonPath('announcement_banner', 'Midterms moved to Friday.');
    }

    public function test_an_empty_announcement_comes_back_as_an_empty_string(): void
    {
        $this->getJson('/api/settings/public/notice')
            ->assertOk()
            ->assertJsonPath('announcement_banner', '');
    }
}
