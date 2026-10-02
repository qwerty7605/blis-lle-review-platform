<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDirectoryPagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_come_back_in_the_order_the_directory_shows_them(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Zed Admin']);
        User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER, 'name' => 'Ana Staff']);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Beth Fourth', 'year_level' => 4]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Abe Fourth', 'year_level' => 4]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Cara First', 'year_level' => 1]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Dan Unset', 'year_level' => null]);
        User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'name' => 'Abby Alumni',
            'year_level' => 4,
            'is_active' => false,
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $names = collect($this->getJson('/api/admin/users?archive_state=all')->assertOk()->json('users'))
            ->pluck('name')
            ->all();

        // Current students by year (no year last), archived students, staff,
        // then admins -- the order the directory's sections read in, so a
        // second page carries on where the first one stopped.
        $this->assertSame([
            'Cara First',
            'Abe Fourth',
            'Beth Fourth',
            'Dan Unset',
            'Abby Alumni',
            'Ana Staff',
            'Zed Admin',
        ], $names);
    }

    public function test_group_totals_cover_the_whole_filtered_list_not_the_page(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(3)->create(['role' => User::ROLE_STUDENT, 'year_level' => 1]);
        User::factory()->count(4)->create(['role' => User::ROLE_STUDENT, 'year_level' => 2]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'year_level' => null]);
        User::factory()->count(2)->create([
            'role' => User::ROLE_STUDENT,
            'year_level' => 4,
            'is_active' => false,
            'archived_at' => now(),
        ]);
        User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);

        Sanctum::actingAs($admin);

        // A page of two still reports every heading's full size.
        $response = $this->getJson('/api/admin/users?archive_state=all&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('group_totals.staff_master_examiner', 1)
            ->assertJsonPath('group_totals.admin', 1);

        $this->assertEquals([1 => 3, 2 => 4, 'unassigned' => 1], $response->json('group_totals.current'));
        $this->assertEquals([4 => 2], $response->json('group_totals.archived'));

        // And the totals follow the list's filters, unlike the summary tiles.
        $filtered = $this->getJson('/api/admin/users?archive_state=all&year_level=2')
            ->assertOk()
            ->assertJsonCount(4, 'users')
            ->assertJsonPath('group_totals.staff_master_examiner', 0)
            ->assertJsonPath('group_totals.admin', 0);

        $this->assertEquals([2 => 4], $filtered->json('group_totals.current'));
        $this->assertEquals([], $filtered->json('group_totals.archived'));
    }

    public function test_audit_logs_page_without_skipping_or_repeating_entries(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // One shared timestamp, the case where ordering by time alone leaves
        // the order between pages undefined.
        $this->freezeTime();

        $ids = collect(range(1, 5))
            ->map(fn (int $index) => AuditLog::query()->create([
                'actor_id' => $admin->id,
                'action' => 'test.entry',
                'description' => "Entry {$index}",
            ])->id)
            ->sortDesc()
            ->values()
            ->all();

        Sanctum::actingAs($admin);

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("/api/admin/audit-logs?per_page=2&page={$page}")
                ->assertOk()
                ->assertJsonPath('meta.total', 5)
                ->assertJsonPath('meta.last_page', 3);

            $seen = [...$seen, ...collect($response->json('logs'))->pluck('id')->all()];
        }

        $this->assertSame($ids, $seen);

        // The old limit parameter still sizes the list.
        $this->getJson('/api/admin/audit-logs?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'logs');
    }
}
