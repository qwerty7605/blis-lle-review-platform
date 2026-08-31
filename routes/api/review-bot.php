<?php

use App\Models\ReviewBotAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('/student/review-bot/catalog', function (Request $request) use ($ensureActive, $reviewBotService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($user->role !== User::ROLE_STUDENT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    return response()->json([
        'subjects' => $reviewBotService->catalog(),
        'weak_area' => $reviewBotService->resolveWeakArea($user),
    ]);
});

Route::post('/student/review-bot/sessions', function (
    Request $request
) use (
    $ensureActive,
    $reviewBotService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($user->role !== User::ROLE_STUDENT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $useWeakArea = $request->boolean('use_weak_area');

    $filters = [];

    if ($useWeakArea) {
        $weakArea = $reviewBotService->resolveWeakArea($user);

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
        $session = $reviewBotService->startSession($user, $filters);
    } catch (\RuntimeException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }

    $recordAudit(
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
        'questions' => $session['questions'],
    ], 201);
});

Route::post('/student/review-bot/sessions/{attempt}/submit', function (
    Request $request,
    ReviewBotAttempt $attempt
) use (
    $ensureActive,
    $reviewBotService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

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
        $result = $reviewBotService->submitSession($attempt, $validated['answers']);
    } catch (\RuntimeException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }

    $recordAudit(
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
});

Route::get('/student/review-bot/sessions/{attempt}', function (
    Request $request,
    ReviewBotAttempt $attempt
) use ($ensureActive) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($user->role !== User::ROLE_STUDENT || (int) $attempt->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $attempt->load(['topic:id,name', 'subtopic:id,name']);

    return response()->json(['attempt' => $attempt]);
});
