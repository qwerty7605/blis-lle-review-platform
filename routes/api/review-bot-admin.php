<?php

use App\Models\QuestionBank;
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
        // The Library file to generate from -- the single source of truth for
        // uploaded exam questions (see ReviewBotAdminService class docblock).
        // That's the only input: no topic/subtopic/count picking. The service
        // reads the whole file's own mapping and covers most of it automatically.
        'source_bank_id' => ['required', 'integer'],
    ]);

    $sourceBank = QuestionBank::query()->find($validated['source_bank_id']);

    if (!$sourceBank || (int) $sourceBank->created_by !== (int) $user->id) {
        return response()->json(['message' => 'That file was not found in your Library.'], 404);
    }

    $result = $reviewBotAdminService->queueGeneration($user, $sourceBank);

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
        // Scopes to one Library file's generation batch -- see listQuestions().
        'source_bank_id' => ['nullable', 'integer'],
    ]);

    return response()->json([
        'questions' => $reviewBotAdminService->listQuestions($validated),
        // Whether more drafts for this file are still on their way. The page
        // keeps reading this list for as long as it is true.
        'generating' => filled($validated['source_bank_id'] ?? null)
            && $reviewBotAdminService->isGenerating((int) $validated['source_bank_id']),
        // Every status, whichever one was asked for: the tabs show all three.
        'counts' => filled($validated['source_bank_id'] ?? null)
            ? $reviewBotAdminService->statusCounts((int) $validated['source_bank_id'])
            : null,
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

    // Review Bot banks are shared per subject across every master examiner who
    // teaches it (see ReviewBotAdminService::reviewBotBankFor()), not owned by
    // whichever teacher happened to create the bank first -- so this is
    // deliberately just a usage_type check, unlike the Library's per-teacher
    // ownership checks on their own uploaded banks.
    if (!$isStaffMasterExaminer($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'question_text' => ['nullable', 'string'],
        'explanation' => ['nullable', 'string'],
        'difficulty' => ['nullable', Rule::in(QuestionBankQuestion::DIFFICULTIES)],
        'cognitive_level' => ['nullable', Rule::in(QuestionBankQuestion::COGNITIVE_LEVELS)],
        // Re-mapping ("map/assign"): corrects or narrows the topic/subtopic
        // this question inherited from its source item, before publishing.
        'topic_name' => ['nullable', 'string', 'max:255'],
        'subtopic_name' => ['nullable', 'string', 'max:255'],
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

Route::post('/library/review-bot/reject', function (
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

    // Declining keeps the row. Deleting it would make the source exam item
    // look untouched to the next generation run, which would then produce the
    // same rejected question again.
    $rejected = $reviewBotAdminService->reject($validated['question_ids']);

    $recordAudit($user, 'review-bot.admin.reject', 'question_bank_question', null, sprintf('Declined %d Review Bot question(s)', $rejected), $validated, $request);

    return response()->json(['rejected' => $rejected]);
});

Route::get('/library/review-bot/coverage', function (
    Request $request
) use (
    $ensureActive,
    $isStaffMasterExaminer,
    $reviewBotAdminService
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
        'source_bank_id' => ['nullable', 'integer'],
    ]);

    return response()->json([
        'coverage' => $reviewBotAdminService->coverageReport(
            $validated['subject'],
            isset($validated['source_bank_id']) ? (int) $validated['source_bank_id'] : null,
        ),
    ]);
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

    // Shared per-subject bank -- see the matching note on the PATCH route above.
    if (!$isStaffMasterExaminer($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $questionId = $question->id;
    $reviewBotAdminService->delete($question);

    $recordAudit($user, 'review-bot.admin.delete-question', 'question_bank_question', $questionId, 'Deleted a Review Bot question', [], $request);

    return response()->json(['deleted' => true]);
});
