<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackReviewController extends Controller
{
    public function index(Request $request, FeedbackService $feedbackService)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Feedback::STATUSES)],
            'type' => ['nullable', Rule::in(Feedback::TYPES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Feedback::query()
            ->with(['user:id,name,email,role,year_level', 'reviewer:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->latest()
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25), ['*'], 'page', (int) ($validated['page'] ?? 1));

        $counts = Feedback::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'feedback' => collect($page->items())->map(fn (Feedback $feedback) => $feedbackService->forAdmin($feedback))->values(),
            'counts' => collect(Feedback::STATUSES)->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)]),
            'developer_email_set' => $feedbackService->developerEmail() !== null,
            'developer_email' => $feedbackService->developerEmail(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * One item, for the link in the developers' email and the page's ?item=.
     */
    public function show(Request $request, Feedback $feedback, FeedbackService $feedbackService)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['feedback' => $feedbackService->forAdmin($feedback->load(['user', 'reviewer']))]);
    }

    /**
     * The administrator's decision on one piece of feedback:
     *   send_to_developers  emails it, with an optional note (Planned)
     *   answer              a reply the sender sees (Answered, if it was New)
     *   decline             won't do, or not a problem, with an optional reason
     *   done                built or fixed
     *   reopen              back to New, or Planned if the developers have it
     */
    public function update(Request $request, Feedback $feedback, FeedbackService $feedbackService)
    {
        $admin = $request->user();

        if (!$this->isAdmin($admin)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['send_to_developers', 'answer', 'decline', 'done', 'reopen'])],
            'reply' => ['nullable', 'string', 'max:3000'],
            'developer_note' => ['nullable', 'string', 'max:3000'],
        ]);

        $action = $validated['action'];
        $reply = trim((string) ($validated['reply'] ?? ''));
        $closed = [Feedback::STATUS_DONE, Feedback::STATUS_DECLINED];

        $refusal = match (true) {
            $action === 'send_to_developers' && $feedback->developer_emailed_at !== null => 'The developers already have this.',
            $action === 'send_to_developers' && in_array($feedback->status, $closed, true) => 'Reopen it before sending it to the developers.',
            $action === 'answer' && $reply === '' => 'Write a reply first.',
            $action === 'decline' && $feedback->status === Feedback::STATUS_DECLINED => 'This is already closed.',
            $action === 'done' && $feedback->status === Feedback::STATUS_DONE => 'This is already marked done.',
            $action === 'reopen' && !in_array($feedback->status, [...$closed, Feedback::STATUS_ANSWERED], true) => 'This is still open.',
            default => null,
        };

        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 422);
        }

        $changes = ['reviewed_by' => $admin->id, 'reviewed_at' => now()];

        if ($reply !== '') {
            $changes['admin_reply'] = $reply;
        }

        $changes['status'] = match ($action) {
            'send_to_developers' => Feedback::STATUS_PLANNED,
            'answer' => $feedback->status === Feedback::STATUS_NEW ? Feedback::STATUS_ANSWERED : $feedback->status,
            'decline' => Feedback::STATUS_DECLINED,
            'done' => Feedback::STATUS_DONE,
            'reopen' => $feedback->developer_emailed_at !== null ? Feedback::STATUS_PLANNED : Feedback::STATUS_NEW,
        };

        if ($action === 'send_to_developers') {
            $note = trim((string) ($validated['developer_note'] ?? ''));
            $changes['developer_note'] = $note !== '' ? $note : null;
        }

        $feedback->forceFill($changes)->save();

        $emailed = $action === 'send_to_developers' ? $feedbackService->emailDevelopers($feedback) : null;

        $this->recordAudit($admin, 'feedback.'.$action, 'feedback', $feedback->id, match ($action) {
            'send_to_developers' => 'Sent feedback to the developers',
            'answer' => 'Replied to feedback',
            'decline' => 'Closed feedback without action',
            'done' => 'Marked feedback done',
            'reopen' => 'Reopened feedback',
        }, [], $request);

        return response()->json([
            'message' => match (true) {
                $emailed === false => 'Saved as sent, but the email did not go out. Try again shortly.',
                $emailed === true => 'Sent to the developers.',
                default => 'Saved.',
            },
            'email_sent' => $emailed,
            'feedback' => $feedbackService->forAdmin($feedback->fresh(['user', 'reviewer'])),
        ]);
    }
}
