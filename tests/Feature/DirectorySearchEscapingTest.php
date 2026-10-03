<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Binding the search term stops SQL injection, but it leaves LIKE's own
 * metacharacters alive inside the bound value. Unescaped, "%" matches every
 * row and "_" matches any single character -- so a directory search answers a
 * question nobody asked, and an email holding an underscore (common enough)
 * silently matches the wrong people.
 */
class DirectorySearchEscapingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_percent_sign_matches_nothing_rather_than_everything(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        User::factory()->count(3)->create(['role' => User::ROLE_STUDENT]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/students/directory?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'students');
    }

    public function test_an_underscore_does_not_stand_in_for_any_character(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        User::factory()->create(['role' => User::ROLE_STUDENT, 'name' => 'Student One']);

        Sanctum::actingAs($staff);

        // "S_udent" would match "Student" if the underscore kept its wildcard
        // meaning.
        $this->getJson('/api/students/directory?search=S_udent')
            ->assertOk()
            ->assertJsonCount(0, 'students');
    }

    public function test_a_literal_underscore_still_finds_the_account_holding_one(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'juan_dela@lnu.edu.ph',
        ]);
        User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'juanXdela@lnu.edu.ph',
        ]);

        Sanctum::actingAs($staff);

        // Escaping must not break the ordinary case: the one real underscore
        // matches, the character that a wildcard would have caught does not.
        $this->getJson('/api/students/directory?search=juan_dela')
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.email', 'juan_dela@lnu.edu.ph');
    }

    public function test_the_admin_directory_escapes_the_same_way(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->count(3)->create(['role' => User::ROLE_STUDENT]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'users');
    }

    public function test_the_backslash_is_escaped_before_the_wildcards(): void
    {
        // Escaping "%" first would turn a backslash into "\\%" and match a
        // literal backslash instead of a literal percent sign.
        $this->assertSame('\\\\\\%', Search::escapeLike('\\%'));
        $this->assertSame('\%\_', Search::escapeLike('%_'));
        $this->assertSame('plain', Search::escapeLike('plain'));
    }
}
