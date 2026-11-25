<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Strong_pass1';

    public function test_it_creates_a_verified_active_administrator_from_the_prompts(): void
    {
        $this->artisan('blis:create-admin')
            ->expectsQuestion('Full name', 'Library Admin')
            ->expectsQuestion('Email address', ' Admin@LNU.edu.ph ')
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the password again', self::PASSWORD)
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@lnu.edu.ph')->firstOrFail();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertTrue((bool) $admin->is_active);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertNull($admin->student_id);

        $entry = AuditLog::query()->where('action', 'admin.user.create')->firstOrFail();
        $this->assertNull($entry->actor_id);
        $this->assertSame($admin->id, (int) $entry->target_id);
        $this->assertSame('console', $entry->metadata['via']);
    }

    public function test_the_new_administrator_can_sign_in(): void
    {
        $this->artisan('blis:create-admin', ['--name' => 'Library Admin', '--email' => 'admin@lnu.edu.ph'])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the password again', self::PASSWORD)
            ->assertSuccessful();

        $this->postJson('/api/auth/login', ['identifier' => 'admin@lnu.edu.ph', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_a_weak_password_creates_nothing(): void
    {
        $this->artisan('blis:create-admin', ['--name' => 'Library Admin', '--email' => 'admin@lnu.edu.ph'])
            ->expectsQuestion('Password (hidden)', 'password')
            ->expectsOutputToContain('Nothing was created.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_mismatched_passwords_create_nothing(): void
    {
        $this->artisan('blis:create-admin', ['--name' => 'Library Admin', '--email' => 'admin@lnu.edu.ph'])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Type the password again', 'Strong_pass2')
            ->expectsOutputToContain('The two passwords do not match.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_an_address_already_in_use_is_refused_before_the_password_is_asked(): void
    {
        User::factory()->create(['email' => 'admin@lnu.edu.ph']);

        $this->artisan('blis:create-admin', ['--name' => 'Library Admin', '--email' => 'admin@lnu.edu.ph'])
            ->expectsOutputToContain('An account with this email address already exists.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $this->artisan('blis:create-admin', ['--name' => 'Library Admin', '--email' => 'not-an-email'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
