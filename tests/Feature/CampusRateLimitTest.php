<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rate limits, seen from a school lab.
 *
 * A lab, or the school Wi-Fi, reaches the server through one network address,
 * so every limit counted "per address" is really a limit on the whole room.
 * Those have to be wide enough for a full campus (about 230 students) doing
 * the same thing in the same minute. What stops an attacker is the limit
 * counted per account and per mailbox, and those must stay as tight as they
 * were however wide the room's allowance gets.
 */
class CampusRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SCHOOL = '203.0.113.10';

    private const ELSEWHERE = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel pads every password-reset call to 200 ms so that timing
        // cannot reveal which addresses have accounts. Nothing here measures
        // time, so skip the wait.
        Sleep::fake();
    }

    // ------------------------------------------------------------ signing in

    public function test_120_students_can_sign_in_from_one_address_in_the_same_minute(): void
    {
        $students = User::factory()->count(121)->create();
        $this->atAddress(self::SCHOOL);

        foreach ($students->take(120) as $student) {
            $this->signIn($student)->assertOk();
        }

        // The 121st waits for the minute to pass...
        $this->signIn($students->last())->assertStatus(429);

        $this->travel(61)->seconds();

        // ...and then gets in.
        $this->signIn($students->last())->assertOk();
    }

    public function test_guessing_one_accounts_password_is_still_cut_off_after_five_tries(): void
    {
        [$target, $classmate] = User::factory()->count(2)->create()->all();
        $this->atAddress(self::SCHOOL);

        for ($try = 1; $try <= 5; $try++) {
            $this->signIn($target, 'wrong-guess-'.$try)->assertStatus(401);
        }

        // Refused now even with the right password.
        $this->signIn($target)->assertStatus(429);

        // The classmate at the next computer shares the address, not the lockout.
        $this->signIn($classmate)->assertOk();
    }

    // ----------------------------------------------------------- registering

    public function test_a_lab_of_45_can_register_within_two_minutes(): void
    {
        Notification::fake();
        $this->atAddress(self::SCHOOL);

        for ($seat = 1; $seat <= 30; $seat++) {
            $this->register($seat)->assertStatus(201);
        }

        $this->register(31)->assertStatus(429);

        $this->travel(61)->seconds();

        for ($seat = 31; $seat <= 45; $seat++) {
            $this->register($seat)->assertStatus(201);
        }

        $this->assertDatabaseCount('users', 45);
    }

    public function test_registrations_from_one_address_stop_at_150_an_hour(): void
    {
        Notification::fake();
        $this->atAddress(self::SCHOOL);
        $seat = 0;

        // Three labs and a bit: five full minutes, a minute apart.
        for ($minute = 1; $minute <= 5; $minute++) {
            for ($n = 1; $n <= 30; $n++) {
                $this->register(++$seat)->assertStatus(201);
            }

            $this->travel(61)->seconds();
        }

        // A fresh minute, but the hour is spent.
        $this->register(151)->assertStatus(429);

        $this->travel(1)->hours();

        $this->register(151)->assertStatus(201);
    }

    // ------------------------------------------- before and after signing in

    public function test_signed_out_requests_share_600_a_minute_per_address_and_leave_signed_in_students_alone(): void
    {
        $student = User::factory()->create();
        $this->atAddress(self::SCHOOL);

        for ($n = 1; $n <= 600; $n++) {
            $this->getJson('/api/settings/public/notice')->assertOk();
        }

        $this->getJson('/api/settings/public/notice')->assertStatus(429);

        // Someone already signed in, in the same room, is counted on their own.
        $this->signedInAs($student)->getJson('/api/auth/me')->assertOk();

        // So is another network.
        $this->signedOut()->atAddress(self::ELSEWHERE);
        $this->getJson('/api/settings/public/notice')->assertOk();
    }

    public function test_each_signed_in_student_still_has_120_a_minute_of_their_own(): void
    {
        [$busy, $neighbour] = User::factory()->count(2)->create()->all();
        $this->atAddress(self::SCHOOL);

        $this->signedInAs($busy);

        for ($n = 1; $n <= 120; $n++) {
            $this->getJson('/api/auth/me')->assertOk();
        }

        $this->getJson('/api/auth/me')->assertStatus(429);

        // Same address, different student: untouched.
        $this->signedInAs($neighbour)->getJson('/api/auth/me')->assertOk();
    }

    // ------------------------------------------------- endpoints that send mail

    public static function mailEndpoints(): array
    {
        return [
            'resend the verification email' => ['/api/email/verification-notification'],
            'ask for a password reset' => ['/api/auth/forgot-password'],
        ];
    }

    #[DataProvider('mailEndpoints')]
    public function test_one_mailbox_can_still_be_asked_for_only_three_times_an_hour_from_anywhere(string $endpoint): void
    {
        Notification::fake();
        $mailbox = ['email' => 'target@lnu.edu.ph'];
        $this->atAddress(self::SCHOOL);

        for ($n = 1; $n <= 3; $n++) {
            $this->postJson($endpoint, $mailbox)->assertOk();
        }

        $this->postJson($endpoint, $mailbox)->assertStatus(429);

        // Moving to another network does not help: the limit follows the mailbox.
        $this->atAddress(self::ELSEWHERE)->postJson($endpoint, $mailbox)->assertStatus(429);
    }

    #[DataProvider('mailEndpoints')]
    public function test_thirty_different_students_an_hour_can_ask_from_one_address(string $endpoint): void
    {
        Notification::fake();
        $this->atAddress(self::SCHOOL);

        for ($n = 1; $n <= 30; $n++) {
            $this->postJson($endpoint, ['email' => "student{$n}@lnu.edu.ph"])->assertOk();
        }

        $this->postJson($endpoint, ['email' => 'student31@lnu.edu.ph'])->assertStatus(429);

        // The 31st is held up in that room only.
        $this->atAddress(self::ELSEWHERE)
            ->postJson($endpoint, ['email' => 'student31@lnu.edu.ph'])
            ->assertOk();
    }

    public function test_the_reset_form_allows_twice_as_many_tries_as_links_one_address_can_ask_for(): void
    {
        $this->atAddress(self::SCHOOL);

        $form = [
            'token' => 'not-a-real-token',
            'email' => 'student@lnu.edu.ph',
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
        ];

        // 30 links an hour can be asked for, and people mistype the form.
        for ($n = 1; $n <= 60; $n++) {
            $this->postJson('/api/auth/reset-password', $form)->assertStatus(422);
        }

        $this->postJson('/api/auth/reset-password', $form)->assertStatus(429);
    }

    // ---------------------------------------------------------------- helpers

    private function atAddress(string $address): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }

    private function signIn(User $user, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => $password,
        ]);
    }

    /**
     * One test stands in for many browsers, so drop the user the previous
     * request resolved -- a fresh request to the real server starts without one.
     */
    private function signedInAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('spa')->plainTextToken);
    }

    private function signedOut(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken();
    }

    private function register(int $seat): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'first_name' => 'Student',
            'last_name' => 'Seat '.$seat,
            'student_id' => (string) (2400000 + $seat),
            'year_level' => 1,
            'email' => "seat{$seat}@lnu.edu.ph",
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
            'agreed' => true,
        ]);
    }
}
