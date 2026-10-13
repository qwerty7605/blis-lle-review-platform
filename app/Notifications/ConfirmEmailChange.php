<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the address an account wants to move to. Nothing changes until its
 * link is opened, which is what shows the new mailbox is theirs.
 */
class ConfirmEmailChange extends Notification
{
    public function __construct(private readonly string $url, private readonly int $minutes)
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
            ->subject('Confirm your new '.config('app.name').' email address')
            ->greeting('Confirm this address.')
            ->line('Someone asked to use this address to sign in to their '.config('app.name').' account.')
            ->action('Use this address', $this->url)
            ->line("This link stops working in {$this->minutes} minutes. Opening it signs the account out everywhere, and it signs in with this address from then on.")
            ->line('If this was not you, ignore this email -- nothing has changed.');
    }
}
