<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailWasChanged;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

/**
 * The three account emails: confirming a new sign-in address, telling the
 * old address it was replaced, and verifying a new account.
 */
class AccountEmailNotificationsTest extends TestCase
{
    public function test_the_confirmation_email_carries_the_link_and_how_long_it_lasts(): void
    {
        $notification = new ConfirmEmailChange('https://example.test/confirm', 60);
        $mail = $notification->toMail(new User());

        $this->assertSame(['mail'], $notification->via(new User()));
        $this->assertStringContainsString('Confirm your new', $mail->subject);
        $this->assertSame('https://example.test/confirm', $mail->actionUrl);
        $this->assertStringContainsString('stops working in 60 minutes', implode(' ', $mail->outroLines));
    }

    public function test_the_old_address_is_told_which_address_replaced_it(): void
    {
        $notification = new EmailWasChanged('new@lnu.edu.ph');
        $mail = $notification->toMail(new User());

        $this->assertSame(['mail'], $notification->via(new User()));
        $this->assertStringContainsString('sign-in email was changed', $mail->subject);
        $this->assertStringContainsString('new@lnu.edu.ph', implode(' ', $mail->introLines));
    }

    public function test_the_verification_email_says_how_many_hours_the_link_lasts(): void
    {
        config(['auth.verification.expire' => 1440]);
        $user = User::factory()->make(['id' => 5, 'email' => 'juan@lnu.edu.ph']);

        $mail = (new VerifyEmail())->toMail($user);

        $this->assertStringContainsString('Verify your', $mail->subject);
        $this->assertStringContainsString('stops working in 24 hours', implode(' ', $mail->outroLines));
        $this->assertStringContainsString('/email/verify/5/', $mail->actionUrl);
    }
}
