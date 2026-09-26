<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserDirectorySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_summary_counts_the_whole_directory_not_the_loaded_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(2)->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        User::factory()->count(205)->create(['role' => User::ROLE_STUDENT]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'is_active' => false]);
        User::factory()->count(3)->create(['role' => User::ROLE_STUDENT, 'is_active' => false, 'archived_at' => now()]);

        Sanctum::actingAs($admin);

        $expected = [
            'current_students' => 206,
            'archived_students' => 3,
            'staff_and_admin' => 3,
            // The disabled current student; archived alumni count as archived.
            'inactive' => 1,
        ];

        // More accounts than one page holds: the list stops at the page size,
        // the summary does not.
        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonCount(200, 'users')
            ->assertJsonPath('meta.total', 212)
            ->assertJsonPath('summary', $expected);

        // And it ignores the list's filters.
        $this->getJson('/api/admin/users?search=matches-nobody')
            ->assertOk()
            ->assertJsonCount(0, 'users')
            ->assertJsonPath('summary', $expected);
    }
}
