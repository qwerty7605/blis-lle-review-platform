<?php

use App\Models\Feedback;
use App\Services\FeedbackService;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/*
 * Feedback, sent by anyone from the Settings page.
 *
 * A problem is emailed to the developers as it arrives (see
 * config/services.php for where): nobody decides to leave the system
 * broken. An idea, a question or anything else waits for the administrator,
 * the head of BLIS, who answers it, turns it down, or sends it on to the
 * developers; nothing reaches them before that. The sender sees the status
 * and the administrator's reply under "Your feedback".
 */

Route::post('/feedback', function (Request $request, FeedbackService $feedbackService) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $validated = $request->validate([
        'type' => ['required', Rule::in(Feedback::TYPES)],
        'rating' => ['nullable', 'integer', 'between:1,5'],
        'message' => ['required', 'string', 'min:10', 'max:5000'],
        'may_contact' => ['sometimes', 'boolean'],
        'context' => ['sometimes', 'array'],
        'context.page' => ['nullable', 'string', 'max:255'],
        'context.app_version' => ['nullable', 'string', 'max:100'],
        'context.screen' => ['nullable', 'string', 'max:20'],
    ], [
        'message.min' => 'Tell us a little more: at least 10 characters.',
        'message.required' => 'Write your feedback first.',
    ]);

    $user = $request->user();
    $userAgent = (string) $request->userAgent();

    $feedback = Feedback::create([
        'user_id' => $user->id,
        'type' => $validated['type'],
        'rating' => $validated['rating'] ?? null,
        'message' => trim($validated['message']),
        'may_contact' => (bool) ($validated['may_contact'] ?? false),
        // What the page sent, plus what only the server knows for sure.
        'context' => array_filter([
            'page' => $validated['context']['page'] ?? null,
            'app_version' => $validated['context']['app_version'] ?? null,
            'screen' => $validated['context']['screen'] ?? null,
            'device' => $userAgent !== '' ? DeviceLabel::from($userAgent) : null,
            'user_agent' => $userAgent !== '' ? Str::limit($userAgent, 300, '') : null,
            'role' => $user->role,
        ], fn ($value) => $value !== null && $value !== ''),
        'status' => Feedback::STATUS_NEW,
    ]);

    if ($feedback->type === Feedback::TYPE_PROBLEM) {
        $feedbackService->emailDevelopers($feedback);
    }

    $recordAudit($user, 'feedback.send', 'feedback', $feedback->id, 'Sent feedback ('.Feedback::typeLabel($feedback->type).')', [], $request);

    return response()->json([
        'message' => $feedback->type === Feedback::TYPE_PROBLEM
            ? 'Thank you. Your report has been sent to the developers and the administrator.'
            : 'Thank you. The administrator will read it and decide what happens next.',
        'feedback' => $feedbackService->forSender($feedback->fresh()),
    ], 201);
})->middleware('throttle:feedback');

Route::get('/feedback', function (Request $request, FeedbackService $feedbackService) use ($ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $items = Feedback::query()
        ->where('user_id', $request->user()->id)
        ->latest()
        ->orderByDesc('id')
        ->limit(20)
        ->get();

    return response()->json([
        'feedback' => $items->map(fn (Feedback $feedback) => $feedbackService->forSender($feedback))->values(),
    ]);
});

Route::get('/admin/feedback', function (Request $request, FeedbackService $feedbackService) use ($ensureActive, $isAdmin) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    if (!$isAdmin($request->user())) {
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
});

// One item, for the link in the developers' email and the page's ?item=.
Route::get('/admin/feedback/{feedback}', function (Request $request, Feedback $feedback, FeedbackService $feedbackService) use ($ensureActive, $isAdmin) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    if (!$isAdmin($request->user())) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    return response()->json(['feedback' => $feedbackService->forAdmin($feedback->load(['user', 'reviewer']))]);
});

/*
 * The administrator's decision on one piece of feedback:
 *   send_to_developers  emails it, with an optional note (Planned)
 *   answer              a reply the sender sees (Answered, if it was New)
 *   decline             won't do, or not a problem, with an optional reason
 *   done                built or fixed
 *   reopen              back to New, or Planned if the developers have it
 */
Route::patch('/admin/feedback/{feedback}', function (Request $request, Feedback $feedback, FeedbackService $feedbackService) use ($ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $admin = $request->user();

    if (!$isAdmin($admin)) {
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

    $recordAudit($admin, 'feedback.'.$action, 'feedback', $feedback->id, match ($action) {
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
});
