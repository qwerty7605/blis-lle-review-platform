<?php

namespace Tests\Unit\Support;

use App\Models\SystemSetting;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SystemSettings reads the announcement once per
 * request, and treats a missing settings table as "nothing set".
 */
class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_settings_are_read(): void
    {
        SystemSetting::create(['key' => 'announcement_banner', 'value' => '  Exam week  ']);

        $settings = new SystemSettings();

        $this->assertSame('Exam week', $settings->announcementBanner());
    }

    public function test_without_a_settings_table_nothing_is_on(): void
    {
        Schema::drop('system_settings');

        $settings = new SystemSettings();

        $this->assertSame('', $settings->announcementBanner());
    }
}
