<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/student/review-bot/subjects', function (Request $request) use ($ensureActive, $reviewBotService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($user->role !== User::ROLE_STUDENT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    return response()->json([
        'subjects' => $reviewBotService->listSubjects(),
    ]);
});

Route::post('/student/review-bot/generate', function (
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

    $validated = $request->validate([
        'question_count' => ['required', 'integer', 'min:3', 'max:30'],
        'auto_detect_weak_areas' => ['nullable', 'boolean'],
        'subjects' => [
            \Illuminate\Validation\Rule::requiredIf(fn () => !$request->boolean('auto_detect_weak_areas')),
            'nullable',
            'array',
            'max:6',
        ],
        'subjects.*' => ['required', 'string', 'max:255'],
    ]);

    $autoDetectWeakAreas = (bool) ($validated['auto_detect_weak_areas'] ?? false);

    try {
        $reviewSet = $reviewBotService->generateQuiz(
            $autoDetectWeakAreas ? [] : $validated['subjects'],
            (int) $validated['question_count'],
            $user,
            $autoDetectWeakAreas,
        );
    } catch (\RuntimeException $exception) {
        return response()->json([
            'message' => $exception->getMessage(),
        ], 422);
    }

    $recordAudit(
        $user,
        'review-bot.generate',
        'review_bot',
        null,
        'Generated a Review Bot practice set',
        [
            'question_count' => (int) $validated['question_count'],
            'subjects' => $reviewSet['subjects'],
            'generator' => $reviewSet['generator'],
            'weak_subjects' => $reviewSet['weak_subjects'],
            'auto_detected' => $reviewSet['auto_detected'],
        ],
        $request,
    );

    $subjectList = implode(', ', $reviewSet['subjects']);
    $weakSubjectList = implode(', ', $reviewSet['weak_subjects']);

    $baseMessage = match ($reviewSet['generator']) {
        'ai' => 'AI-generated quiz ready',
        'library_remix' => 'Quiz ready, built from your teachers\' question banks',
        default => 'Quiz ready',
    };

    if ($reviewSet['auto_detected']) {
        $message = $weakSubjectList !== ''
            ? "{$baseMessage}. Auto-detected your weak subjects: {$subjectList}."
            : "{$baseMessage}. You're not weak in any subject yet, so this set reviews: {$subjectList}.";
    } elseif ($reviewSet['generator'] === 'ai' && $weakSubjectList !== '') {
        $message = "{$baseMessage} — focused on your weaker subjects: {$weakSubjectList}.";
    } else {
        $message = "{$baseMessage}.";
    }

    return response()->json([
        'message' => $message,
        'generator' => $reviewSet['generator'],
        'subjects' => $reviewSet['subjects'],
        'questions' => $reviewSet['questions'],
        'weak_subjects' => $reviewSet['weak_subjects'],
        'auto_detected' => $reviewSet['auto_detected'],
    ]);
});
