<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * Tells the developers a request failed with a server error (see
 * App\Support\ErrorAlerts). Holds what is needed to find the fault -- the
 * error, where it was thrown, which address was asked for and by whom -- and
 * never the request's body, which can hold passwords and answers.
 */
class ServerErrorAlert extends Mailable
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public array $details)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[%s error] %s: %s',
                config('app.name'),
                class_basename($this->details['class']),
                Str::limit(Str::squish($this->details['message']), 80),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.server_error_alert', with: ['details' => $this->details]);
    }
}
