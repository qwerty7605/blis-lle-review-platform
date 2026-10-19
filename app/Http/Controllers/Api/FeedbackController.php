<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\FeedbackService;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function store(Request $request, FeedbackService $feedbackService)
    {
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

        $this->recordAudit($user, 'feedback.send', 'feedback', $feedback->id, 'Sent feedback ('.Feedback::typeLabel($feedback->type).')', [], $request);

        return response()->json([
            'message' => $feedback->type === Feedback::TYPE_PROBLEM
                ? 'Thank you. Your report has been sent to the developers and the administrator.'
                : 'Thank you. The administrator will read it and decide what happens next.',
            'feedback' => $feedbackService->forSender($feedback->fresh()),
        ], 201);
    }

    public function index(Request $request, FeedbackService $feedbackService)
    {
        $items = Feedback::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'feedback' => $items->map(fn (Feedback $feedback) => $feedbackService->forSender($feedback))->values(),
        ]);
    }
}
