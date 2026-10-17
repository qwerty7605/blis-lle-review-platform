<?php

namespace App\Services;

use App\Mail\FeedbackForDevelopers;
use App\Models\Feedback;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Getting feedback to the developers, and what the sender and the
 * administrator are shown of it.
 */
class FeedbackService
{
    /** Set in config/services.php; empty means feedback stays in the system. */
    public function developerEmail(): ?string
    {
        $address = trim((string) config('services.feedback.developer_email'));

        return $address !== '' ? $address : null;
    }

    /**
     * Emails it to the developers and records whether that worked. A failed
     * send never fails the request: the feedback is saved either way, and
     * the administrator sees that it still has to go.
     */
    public function emailDevelopers(Feedback $feedback): bool
    {
        $address = $this->developerEmail();

        if ($address === null) {
            $feedback->forceFill(['developer_email_error' => 'No developer email address is set.'])->save();

            return false;
        }

        try {
            Mail::to($address)->send(new FeedbackForDevelopers($feedback->loadMissing('user', 'reviewer')));
        } catch (Throwable $exception) {
            Log::warning('Feedback email to the developers failed.', ['feedback_id' => $feedback->id, 'error' => $exception->getMessage()]);
            $feedback->forceFill(['developer_email_error' => Str::limit($exception->getMessage(), 490)])->save();

            return false;
        }

        $feedback->forceFill(['developer_emailed_at' => now(), 'developer_email_error' => null])->save();

        return true;
    }

    /**
     * What the sender sees in "Your feedback". Who reviewed it and the note
     * to the developers stay with the administrator.
     *
     * @return array<string, mixed>
     */
    public function forSender(Feedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'type' => $feedback->type,
            'rating' => $feedback->rating,
            'message' => $feedback->message,
            'status' => $feedback->status,
            'admin_reply' => $feedback->admin_reply,
            'sent_to_developers' => $feedback->developer_emailed_at !== null,
            'created_at' => $feedback->created_at?->toISOString(),
            'updated_at' => $feedback->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function forAdmin(Feedback $feedback): array
    {
        $user = $feedback->user;

        return [
            ...$this->forSender($feedback),
            'may_contact' => $feedback->may_contact,
            'context' => $feedback->context ?? [],
            'developer_note' => $feedback->developer_note,
            'developer_emailed_at' => $feedback->developer_emailed_at?->toISOString(),
            'developer_email_error' => $feedback->developer_email_error,
            'reviewed_at' => $feedback->reviewed_at?->toISOString(),
            'reviewer' => $feedback->reviewer ? ['id' => $feedback->reviewer->id, 'name' => $feedback->reviewer->name] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'year_level' => $user->year_level,
            ] : null,
        ];
    }
}
