<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * php artisan mail:test -- sends one message and says whether the mailer
 * accepted it, with the usual causes when it did not.
 */
class MailTestCommandTest extends TestCase
{
    public function test_with_the_log_mailer_it_warns_that_nothing_is_delivered(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['recipient' => 'admin@lnu.edu.ph'])
            ->expectsOutputToContain('MAIL_MAILER is "log"')
            ->expectsOutputToContain('Look for it in storage/logs/laravel.log.')
            ->assertSuccessful();
    }

    public function test_with_a_real_mailer_it_says_to_check_the_inbox(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('mail:test', ['recipient' => 'admin@lnu.edu.ph'])
            ->expectsOutputToContain('to     : admin@lnu.edu.ph')
            ->expectsOutputToContain('Accepted by the mailer without error.')
            ->expectsOutputToContain('Check the inbox, then the spam folder')
            ->assertSuccessful();
    }

    public function test_a_failed_send_lists_the_usual_causes_and_fails(): void
    {
        config(['mail.default' => 'array']);
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Connection refused'));

        $this->artisan('mail:test', ['recipient' => 'admin@lnu.edu.ph'])
            ->expectsOutputToContain('Sending failed: Connection refused')
            ->expectsOutputToContain('App Password')
            ->assertFailed();
    }

    public function test_the_unused_archive_rooms_command_does_nothing(): void
    {
        $this->artisan('app:archive-old-rooms-command')->assertSuccessful();
    }
}
