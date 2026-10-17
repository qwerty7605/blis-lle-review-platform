<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * A piece of feedback for the developers: a problem as soon as it is
 * reported, anything else once the administrator has sent it on. The
 * sender's name and address are in it only if they agreed to be contacted.
 */
class FeedbackForDevelopers extends Mailable
{
    public function __construct(public Feedback $feedback)
    {
    }

    public function envelope(): Envelope
    {
        $sender = $this->feedback->user;

        return new Envelope(
            subject: sprintf(
                '[%s feedback] %s: %s',
                config('app.name'),
                Feedback::typeLabel($this->feedback->type),
                Str::limit(Str::squish($this->feedback->message), 70),
            ),
            replyTo: $this->feedback->may_contact && $sender?->email
                ? [new Address($sender->email, $sender->name)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feedback_for_developers',
            with: [
                'feedback' => $this->feedback,
                'typeLabel' => Feedback::typeLabel($this->feedback->type),
                'sender' => $this->feedback->user,
                'reviewer' => $this->feedback->reviewer,
                'context' => $this->feedback->context ?? [],
                'adminUrl' => rtrim((string) config('app.frontend_url'), '/') . '/dashboard/feedback?item=' . $this->feedback->id,
            ],
        );
    }
}
