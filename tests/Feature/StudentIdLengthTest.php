<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A student ID is 7 to 10 digits wherever one is typed: at registration, and
 * when an admin creates, edits or recovers a student account. The ceiling used
 * to be 20, which is longer than any ID the institution issues -- so a number
 * typed or pasted twice was accepted as it stood, and the student was left
 * with an account under an ID nobody could match to them.
 */
class StudentIdLengthTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------- registration

    public function test_a_ten_digit_student_id_is_accepted(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload(['student_id' => '2300001123']))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['student_id' => '2300001123']);
    }

    public function test_an_eleven_digit_student_id_is_refused(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['student_id' => '23000011234']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_seven_digit_minimum_still_applies(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->payload(['student_id' => '230129']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->postJson('/api/auth/register', $this->payload(['student_id' => '2300001']))
            ->assertStatus(201);
    }

    public function test_a_student_id_must_be_digits_only(): void
    {
        $this->postJson('/api/auth/register', $this->payload(['student_id' => '23-01290']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->assertDatabaseCount('users', 0);
    }

    // ----------------------------------------------------------------- admin

    public function test_an_admin_can_create_a_student_with_a_ten_digit_id(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/users', $this->adminPayload(['student_id' => '2401234567']))
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['student_id' => '2401234567']);
    }

    public function test_an_admin_cannot_create_a_student_with_an_eleven_digit_id(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/admin/users', $this->adminPayload(['student_id' => '24012345678']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->assertDatabaseMissing('users', ['email' => 'new-student@lnu.edu.ph']);
    }

    public function test_an_admin_cannot_lengthen_a_student_id_past_ten_digits(): void
    {
        $student = $this->makeStudent('2309999');
        Sanctum::actingAs($this->makeAdmin());

        $this->patchJson("/api/admin/users/{$student->id}", ['student_id' => '23099990000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->assertSame('2309999', $student->fresh()->student_id);

        $this->patchJson("/api/admin/users/{$student->id}", ['student_id' => '2309999000'])
            ->assertOk();

        $this->assertSame('2309999000', $student->fresh()->student_id);
    }

    public function test_account_recovery_refuses_an_eleven_digit_student_id(): void
    {
        $student = $this->makeStudent('2309999000');
        Sanctum::actingAs($this->makeAdmin());

        $recovery = ['email' => 'recovered@lnu.edu.ph', 'password' => 'Secret12345!'];

        $this->postJson("/api/admin/users/{$student->id}/recover-account", ['student_id' => '23099990001', ...$recovery])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_id');

        $this->assertSame('student@lnu.edu.ph', $student->fresh()->email);

        $this->postJson("/api/admin/users/{$student->id}/recover-account", ['student_id' => '2309999000', ...$recovery])
            ->assertOk();

        $this->assertSame('recovered@lnu.edu.ph', $student->fresh()->email);
    }

    // --------------------------------------------------------------- helpers

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_id' => '2300001',
            'year_level' => 1,
            'email' => 'juan@lnu.edu.ph',
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
            'agreed' => true,
        ], $overrides);
    }

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Student',
            'email' => 'new-student@lnu.edu.ph',
            'student_id' => '2401234',
            'role' => User::ROLE_STUDENT,
            'year_level' => 1,
            'password' => 'Secret12345!',
        ], $overrides);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'email' => 'registrar@lnu.edu.ph',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    private function makeStudent(string $studentId): User
    {
        return User::factory()->create([
            'email' => 'student@lnu.edu.ph',
            'student_id' => $studentId,
            'role' => User::ROLE_STUDENT,
            'year_level' => 2,
            'student_status' => User::STUDENT_STATUS_ENROLLED,
            'admission_year' => 2025,
            'is_active' => true,
        ]);
    }
}
