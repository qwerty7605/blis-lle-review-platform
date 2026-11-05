<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewBotAttempt;
use App\Models\User;
use App\Services\ReviewBotEligibility;
use App\Services\ReviewBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewBotController extends Controller
{
    public function __construct(
        private readonly ReviewBotService $reviewBotService,
        private readonly ReviewBotEligibility $reviewBotEligibility,
    ) {
    }

    public function catalog(Request $request)
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'subjects' => $this->reviewBotService->catalog($user),
            'weak_area' => $this->reviewBotService->resolveWeakArea($user) ?: null,
            // Exams handed in whose practice is not open yet, and why.
            'held_back' => $this->reviewBotEligibility->heldBack($user),
        ]);
    }

    public function startSession(Request $request)
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $useWeakArea = $request->boolean('use_weak_area');

        $filters = [];

        if ($useWeakArea) {
            $weakArea = $this->reviewBotService->resolveWeakArea($user);

            if ($weakArea === []) {
                return response()->json([
                    'message' => 'We need at least a couple of graded exams before we can detect your weak areas.',
                ], 422);
            }

            $filters = [
                'subject' => $weakArea['subject'] ?? null,
                'topic_id' => $weakArea['topic_id'] ?? null,
                'subtopic_id' => $weakArea['subtopic_id'] ?? null,
                'source' => \App\Models\ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA,
            ];
        } else {
            $validated = $request->validate([
                'subject' => ['required', 'string', 'max:255'],
                'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
                'subtopic_id' => ['nullable', 'integer', 'exists:subtopics,id'],
                'difficulty' => ['nullable', 'string', 'in:easy,medium,hard'],
            ]);

            $filters = $validated;
        }

        $validatedCount = Validator::make($request->all(), [
            'question_count' => ['required', 'integer', 'min:3', 'max:30'],
        ])->validate();

        $filters['question_count'] = (int) $validatedCount['question_count'];

        try {
            $session = $this->reviewBotService->startSession($user, $filters);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->recordAudit(
            $user,
            'review-bot.session.start',
            'review_bot_attempt',
            $session['attempt']->id,
            'Started a Review Bot session',
            [
                'subject' => $session['attempt']->subject,
                'topic_id' => $session['attempt']->topic_id,
                'subtopic_id' => $session['attempt']->subtopic_id,
                'source' => $session['attempt']->source,
            ],
            $request,
        );

        return response()->json([
            'attempt' => [
                'id' => $session['attempt']->id,
                'subject' => $session['attempt']->subject,
                'topic_id' => $session['attempt']->topic_id,
                'subtopic_id' => $session['attempt']->subtopic_id,
                'total_items' => $session['attempt']->total_items,
            ],
            // Set when the session had to widen past the requested subtopic/topic,
            // relax the difficulty, or return fewer questions than asked for.
            'scope_used' => $session['scope_used'] ?? null,
            'scope_note' => $session['scope_note'] ?? null,
            'questions' => $session['questions'],
        ], 201);
    }

    public function submitSession(
        Request $request,
            ReviewBotAttempt $attempt,
    )
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT || (int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_id' => ['nullable', 'integer'],
            'answers.*.answer_text' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->reviewBotService->submitSession($attempt, $validated['answers']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->recordAudit(
            $user,
            'review-bot.session.submit',
            'review_bot_attempt',
            $attempt->id,
            'Submitted a Review Bot session',
            [
                'score_percent' => $result['score_percent'],
                'improved' => $result['improved'],
            ],
            $request,
        );

        return response()->json($result);
    }

    public function showSession(
        Request $request,
            ReviewBotAttempt $attempt,
    )
    {
        $user = $request->user();

        if ($user->role !== User::ROLE_STUDENT || (int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt->load(['topic:id,name', 'subtopic:id,name']);

        return response()->json(['attempt' => $attempt]);
    }
}
