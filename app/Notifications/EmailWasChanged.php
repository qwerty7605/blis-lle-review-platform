<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the address an account has just stopped using, so a change its
 * owner did not make does not go unnoticed.
 */
class EmailWasChanged extends Notification
{
    public function __construct(private readonly string $newEmail)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your '.config('app.name').' sign-in email was changed')
            ->greeting('Your sign-in email was changed.')
            ->line("Your account now signs in with {$this->newEmail}. This address is no longer attached to it.")
            ->line('If you did not make this change, contact an administrator straight away.');
    }
}
