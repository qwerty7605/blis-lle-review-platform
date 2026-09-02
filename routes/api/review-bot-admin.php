<?php

use App\Models\QuestionBankQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::post('/library/review-bot/generate', function (
    Request $request
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'subject' => ['required', 'string', 'max:255'],
        'topic' => ['nullable', 'string', 'max:255'],
        'subtopic' => ['nullable', 'string', 'max:255'],
        // How many EXAM ITEMS to cover, not how many questions to produce --
        // each item yields up to one question per cognitive level.
        'source_limit' => ['required', 'integer', 'min:1', 'max:50'],
    ]);

    $result = $reviewBotAdminService->queueGeneration(
        $user,
        $validated['subject'],
        $validated['topic'] ?? null,
        $validated['subtopic'] ?? null,
        (int) $validated['source_limit'],
    );

    $recordAudit(
        $user,
        'review-bot.admin.generate',
        'question_bank',
        $result['bank_id'],
        'Queued Review Bot question generation',
        $validated,
        $request,
    );

    return response()->json($result);
});

Route::get('/library/review-bot/questions', function (
    Request $request
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    if (!$isStaffMasterExaminer($request->user())) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'subject' => ['nullable', 'string', 'max:255'],
        'topic_id' => ['nullable', 'integer'],
        'status' => ['nullable', Rule::in(QuestionBankQuestion::STATUSES)],
    ]);

    return response()->json([
        'questions' => $reviewBotAdminService->listQuestions($validated),
    ]);
});

Route::patch('/library/review-bot/questions/{question}', function (
    Request $request,
    QuestionBankQuestion $question
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'question_text' => ['nullable', 'string'],
        'explanation' => ['nullable', 'string'],
        'difficulty' => ['nullable', Rule::in(QuestionBankQuestion::DIFFICULTIES)],
        'cognitive_level' => ['nullable', Rule::in(QuestionBankQuestion::COGNITIVE_LEVELS)],
        'options' => ['nullable', 'array'],
        'options.*.id' => ['required_with:options', 'integer'],
        'options.*.text' => ['required_with:options', 'string'],
        'options.*.is_correct' => ['nullable', 'boolean'],
    ]);

    $question = $reviewBotAdminService->updateQuestion($question, $validated);

    $recordAudit($user, 'review-bot.admin.update-question', 'question_bank_question', $question->id, 'Edited a Review Bot draft question', [], $request);

    return response()->json(['question' => $question]);
});

Route::post('/library/review-bot/publish', function (
    Request $request
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'question_ids' => ['required', 'array', 'min:1'],
        'question_ids.*' => ['integer'],
    ]);

    $published = $reviewBotAdminService->publish($validated['question_ids'], $user->id);

    $recordAudit($user, 'review-bot.admin.publish', 'question_bank_question', null, sprintf('Published %d Review Bot question(s)', $published), $validated, $request);

    return response()->json(['published' => $published]);
});

Route::post('/library/review-bot/unpublish', function (
    Request $request
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'question_ids' => ['required', 'array', 'min:1'],
        'question_ids.*' => ['integer'],
    ]);

    $unpublished = $reviewBotAdminService->unpublish($validated['question_ids']);

    $recordAudit($user, 'review-bot.admin.unpublish', 'question_bank_question', null, sprintf('Unpublished %d Review Bot question(s)', $unpublished), $validated, $request);

    return response()->json(['unpublished' => $unpublished]);
});

Route::delete('/library/review-bot/questions/{question}', function (
    Request $request,
    QuestionBankQuestion $question
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService,
    $recordAudit
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $questionId = $question->id;
    $reviewBotAdminService->delete($question);

    $recordAudit($user, 'review-bot.admin.delete-question', 'question_bank_question', $questionId, 'Deleted a Review Bot question', [], $request);

    return response()->json(['deleted' => true]);
});
