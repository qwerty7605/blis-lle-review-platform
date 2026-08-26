<?php

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::get('/exams', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $normalizeDeliveryMode) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $query = Exam::query()
        ->with('creator:id,name')
        ->with([
            'questionBank:id,title,subject,total_items',
            'questionBanks:id,title,subject,total_items',
            'rooms:id,name,code',
        ])
        ->withCount('rooms')
        ->where('created_by', $user->id)
        ->latest();

    $exams = $query->get()->map(function (Exam $exam) use ($normalizeDeliveryMode) {
        $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);
        $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

        return $exam;
    })->values();

    return response()->json([
        'exams' => $exams,
    ]);
});

Route::post('/exams', function (Request $request) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $recordAudit,
    $resolveExamQuestionBankIds,
    $findAccessibleQuestionBanks,
    $normalizeDeliveryMode
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'title' => ['required', 'string', 'max:255'],
        'description' => ['nullable', 'string'],
        'question_bank_ids' => ['nullable', 'array'],
        'question_bank_ids.*' => ['integer', 'exists:question_banks,id'],
        'question_bank_id' => ['nullable', 'integer', 'exists:question_banks,id'],
        'total_items' => ['required', 'integer', 'min:1', 'max:1000'],
        'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
        'scheduled_at' => ['nullable', 'date'],
        'schedule_start_at' => ['nullable', 'date'],
        'schedule_end_at' => ['nullable', 'date'],
        'one_take_only' => ['nullable', 'boolean'],
        'shuffle_questions' => ['nullable', 'boolean'],
        'results_visibility_mode' => ['nullable', Rule::in(['hidden', 'visible'])],
        'room_ids' => ['nullable', 'array'],
        'room_ids.*' => ['integer', 'exists:rooms,id'],
    ]);

    $deliveryMode = Exam::DELIVERY_MODE_OPEN_NAVIGATION;
    $scheduleStartAt = $validated['schedule_start_at'] ?? $validated['scheduled_at'] ?? null;
    $scheduleEndAt = $validated['schedule_end_at'] ?? null;

    if (!is_null($scheduleStartAt) && !is_null($scheduleEndAt) && strtotime((string) $scheduleEndAt) < strtotime((string) $scheduleStartAt)) {
        return response()->json([
            'message' => 'Schedule end must be after or equal to schedule start.',
        ], 422);
    }

    $selectedQuestionBankIds = $resolveExamQuestionBankIds($validated);
    $selectedQuestionBanks = $findAccessibleQuestionBanks($user, $selectedQuestionBankIds, false);

    if (count($selectedQuestionBankIds) !== $selectedQuestionBanks->count()) {
        return response()->json([
            'message' => 'One or more selected question sets are not accessible.',
        ], 422);
    }

    if ($selectedQuestionBanks->isNotEmpty() && $selectedQuestionBanks->sum('questions_count') < (int) $validated['total_items']) {
        return response()->json([
            'message' => 'Selected question sets do not have enough questions for the item count.',
        ], 422);
    }

    $exam = Exam::create([
        'title' => $validated['title'],
        'subject' => Exam::summarizeQuestionBankSubject($selectedQuestionBanks),
        'description' => $validated['description'] ?? null,
        'question_bank_id' => $selectedQuestionBankIds[0] ?? null,
        'total_items' => $validated['total_items'],
        'duration_minutes' => $validated['duration_minutes'],
        'scheduled_at' => $scheduleStartAt,
        'schedule_start_at' => $scheduleStartAt,
        'schedule_end_at' => $scheduleEndAt,
        'delivery_mode' => $deliveryMode,
        'one_take_only' => (bool) ($validated['one_take_only'] ?? false),
        'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
        'results_visibility_mode' => $validated['results_visibility_mode'] ?? 'hidden',
        'created_by' => $user->id,
    ]);

    $exam->syncQuestionBanks($selectedQuestionBankIds);

    $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

    if ($roomIds->isNotEmpty()) {
        $allowedCount = Room::query()
            ->whereIn('id', $roomIds)
            ->where('created_by', $user->id)
            ->count();

        if ($allowedCount !== $roomIds->count()) {
            $exam->delete();

            return response()->json([
                'message' => 'One or more selected rooms are not accessible.',
            ], 422);
        }

        $now = now();
        $syncPayload = $roomIds->mapWithKeys(fn ($roomId) => [$roomId => [
            'assigned_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]])->all();

        $exam->rooms()->sync($syncPayload);
    }

    $exam->load([
        'creator:id,name',
        'questionBank:id,title,subject,total_items',
        'questionBanks:id,title,subject,total_items',
        'rooms:id,name,code',
    ])->loadCount('rooms');
    $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

    $recordAudit(
        $user,
        'exam.create',
        'exam',
        $exam->id,
        'Exam created',
        [
            'title' => $exam->title,
            'scheduled_at' => $exam->scheduled_at,
            'schedule_start_at' => $exam->schedule_start_at,
            'schedule_end_at' => $exam->schedule_end_at,
            'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
            'question_bank_id' => $exam->question_bank_id,
            'question_bank_ids' => $exam->question_bank_ids,
            'one_take_only' => (bool) $exam->one_take_only,
            'shuffle_questions' => (bool) $exam->shuffle_questions,
            'rooms_assigned' => $exam->rooms_count,
        ],
        $request,
    );

    $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);

    return response()->json([
        'message' => 'Exam created',
        'exam' => $exam,
    ], 201);
});

Route::patch('/exams/{exam}', function (Request $request, Exam $exam) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $recordAudit,
    $resolveExamQuestionBankIds,
    $findAccessibleQuestionBanks,
    $normalizeDeliveryMode
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $exam->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'title' => ['sometimes', 'required', 'string', 'max:255'],
        'description' => ['sometimes', 'nullable', 'string'],
        'question_bank_ids' => ['sometimes', 'array'],
        'question_bank_ids.*' => ['integer', 'exists:question_banks,id'],
        'question_bank_id' => ['sometimes', 'nullable', 'integer', 'exists:question_banks,id'],
        'total_items' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'],
        'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:600'],
        'scheduled_at' => ['sometimes', 'nullable', 'date'],
        'schedule_start_at' => ['sometimes', 'nullable', 'date'],
        'schedule_end_at' => ['sometimes', 'nullable', 'date'],
        'one_take_only' => ['sometimes', 'boolean'],
        'shuffle_questions' => ['sometimes', 'boolean'],
        'results_visibility_mode' => ['sometimes', Rule::in(['hidden', 'visible'])],
        'room_ids' => ['sometimes', 'array'],
        'room_ids.*' => ['integer', 'exists:rooms,id'],
    ]);

    $currentScheduleStart = $exam->schedule_start_at ?? $exam->scheduled_at;
    $resolvedScheduleStart = array_key_exists('schedule_start_at', $validated)
        ? $validated['schedule_start_at']
        : (array_key_exists('scheduled_at', $validated) ? $validated['scheduled_at'] : $currentScheduleStart);
    $resolvedScheduleEnd = array_key_exists('schedule_end_at', $validated)
        ? $validated['schedule_end_at']
        : $exam->schedule_end_at;

    if (!is_null($resolvedScheduleStart) && !is_null($resolvedScheduleEnd) && strtotime((string) $resolvedScheduleEnd) < strtotime((string) $resolvedScheduleStart)) {
        return response()->json([
            'message' => 'Schedule end must be after or equal to schedule start.',
        ], 422);
    }

    $resolvedQuestionBankIds = $resolveExamQuestionBankIds($validated, $exam);
    $resolvedTotalItems = array_key_exists('total_items', $validated)
        ? (int) $validated['total_items']
        : (int) $exam->total_items;

    $resolvedQuestionBanks = $findAccessibleQuestionBanks($user, $resolvedQuestionBankIds, false);

    if (count($resolvedQuestionBankIds) !== $resolvedQuestionBanks->count()) {
        return response()->json([
            'message' => 'One or more selected question sets are not accessible.',
        ], 422);
    }

    if ($resolvedQuestionBanks->isNotEmpty() && $resolvedQuestionBanks->sum('questions_count') < $resolvedTotalItems) {
        return response()->json([
            'message' => 'Selected question sets do not have enough questions for the item count.',
        ], 422);
    }

    $exam->fill(collect($validated)->except([
        'scheduled_at',
        'schedule_start_at',
        'schedule_end_at',
        'question_bank_ids',
    ])->all());
    $exam->subject = Exam::summarizeQuestionBankSubject($resolvedQuestionBanks);
    $exam->question_bank_id = $resolvedQuestionBankIds[0] ?? null;
    $exam->scheduled_at = $resolvedScheduleStart;
    $exam->schedule_start_at = $resolvedScheduleStart;
    $exam->schedule_end_at = $resolvedScheduleEnd;
    $exam->delivery_mode = Exam::DELIVERY_MODE_OPEN_NAVIGATION;

    $exam->save();
    $exam->syncQuestionBanks($resolvedQuestionBankIds);

    if (array_key_exists('room_ids', $validated)) {
        $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

        if ($roomIds->isNotEmpty()) {
            $allowedCount = Room::query()
                ->whereIn('id', $roomIds)
                ->where('created_by', $user->id)
                ->count();

            if ($allowedCount !== $roomIds->count()) {
                return response()->json([
                    'message' => 'One or more selected rooms are not accessible.',
                ], 422);
            }
        }

        $now = now();
        $syncPayload = $roomIds->mapWithKeys(fn ($roomId) => [$roomId => [
            'assigned_by' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]])->all();

        $exam->rooms()->sync($syncPayload);
    }

    $exam->load([
        'creator:id,name',
        'questionBank:id,title,subject,total_items',
        'questionBanks:id,title,subject,total_items',
        'rooms:id,name,code',
    ])->loadCount('rooms');
    $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

    $recordAudit(
        $user,
        'exam.update',
        'exam',
        $exam->id,
        'Exam updated',
        [
            'title' => $exam->title,
            'scheduled_at' => $exam->scheduled_at,
            'schedule_start_at' => $exam->schedule_start_at,
            'schedule_end_at' => $exam->schedule_end_at,
            'delivery_mode' => $normalizeDeliveryMode($exam->delivery_mode),
            'question_bank_id' => $exam->question_bank_id,
            'question_bank_ids' => $exam->question_bank_ids,
            'one_take_only' => (bool) $exam->one_take_only,
            'shuffle_questions' => (bool) $exam->shuffle_questions,
            'rooms_assigned' => $exam->rooms_count,
        ],
        $request,
    );

    $exam->delivery_mode = $normalizeDeliveryMode($exam->delivery_mode);

    return response()->json([
        'message' => 'Exam updated',
        'exam' => $exam,
    ]);
});

Route::delete('/exams/{exam}', function (Request $request, Exam $exam) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $exam->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $examId = $exam->id;
    $examTitle = $exam->title;

    $exam->rooms()->detach();
    $exam->delete();

    $recordAudit(
        $user,
        'exam.delete',
        'exam',
        $examId,
        'Exam deleted',
        ['title' => $examTitle],
        $request,
    );

    return response()->json(['message' => 'Exam deleted']);
});

Route::get('/exams/{exam}/live-dashboard', function (Request $request, Exam $exam) use (
    $isStaffMasterExaminer,
    $ensureActive,
    $normalizeDeliveryMode
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $exam->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'room_id' => ['required', 'integer', 'exists:rooms,id'],
    ]);

    $deliveryMode = $normalizeDeliveryMode($exam->delivery_mode);

    $room = Room::query()->find((int) $validated['room_id']);

    if (!$room) {
        return response()->json(['message' => 'Room not found.'], 404);
    }

    if ((int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $isAssigned = $exam->rooms()
        ->where('rooms.id', $room->id)
        ->exists();

    if (!$isAssigned) {
        return response()->json([
            'message' => 'This exam is not assigned to the selected room.',
        ], 422);
    }

    $students = $room->members()
        ->select('users.id', 'users.name', 'users.email', 'users.student_id')
        ->where('users.role', User::ROLE_STUDENT)
        ->orderBy('users.name')
        ->get();

    $attemptsByStudentId = ExamAttempt::query()
        ->where('exam_id', $exam->id)
        ->where('room_id', $room->id)
        ->whereIn('user_id', $students->pluck('id'))
        ->with([
            'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
            'attemptQuestions.question:id,question_type',
            'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
            'answers.selectedOption:id,option_label,option_text',
        ])
        ->orderByDesc('started_at')
        ->get()
        ->groupBy('user_id')
        ->map(fn ($group) => $group->first());

    $maxItems = max(
        (int) $exam->total_items,
        (int) $attemptsByStudentId->max(fn (ExamAttempt $attempt) => (int) $attempt->total_items),
    );

    if ($maxItems < 1) {
        $maxItems = 1;
    }

    $itemStats = [];
    for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
        $itemStats[$itemNumber] = [
            'started_count' => 0,
            'answered_count' => 0,
            'correct_count' => 0,
        ];
    }

    $rows = $students->values()->map(function (User $student) use ($attemptsByStudentId, $maxItems, &$itemStats): array {
        /** @var ExamAttempt|null $attempt */
        $attempt = $attemptsByStudentId->get((int) $student->id);
        $itemPayloadByNumber = [];

        if ($attempt) {
            $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

            foreach ($attempt->attemptQuestions->sortBy('item_number')->values() as $attemptQuestion) {
                $questionId = (int) $attemptQuestion->question_bank_question_id;
                $itemNumber = (int) $attemptQuestion->item_number;
                $answer = $answersByQuestionId->get($questionId);
                $isAnswered = !is_null($answer);

                $responseText = null;
                if ($isAnswered) {
                    if ($answer->selectedOption) {
                        $optionLabel = trim((string) $answer->selectedOption->option_label);
                        $optionText = trim((string) $answer->selectedOption->option_text);
                        $responseText = filled($optionLabel)
                            ? trim($optionLabel . '. ' . $optionText)
                            : $optionText;
                    } else {
                        $responseText = trim((string) ($answer->answer_text ?? ''));
                    }

                    $responseText = $responseText === '' ? null : $responseText;
                }

                $itemPayloadByNumber[$itemNumber] = [
                    'item_number' => $itemNumber,
                    'question_id' => $questionId,
                    'question_type' => $attemptQuestion->question?->question_type,
                    'answered' => $isAnswered,
                    'response' => $responseText,
                    'is_correct' => $isAnswered
                        ? (is_null($answer->is_correct) ? null : (bool) $answer->is_correct)
                        : null,
                    'answered_at' => $answer?->answered_at,
                ];
            }

            $attemptItemCap = max(
                (int) $attempt->total_items,
                empty($itemPayloadByNumber) ? 0 : (int) max(array_keys($itemPayloadByNumber)),
            );

            for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                if ($itemNumber > $attemptItemCap) {
                    continue;
                }

                $itemStats[$itemNumber]['started_count']++;

                $itemData = $itemPayloadByNumber[$itemNumber] ?? null;
                if (!($itemData['answered'] ?? false)) {
                    continue;
                }

                $itemStats[$itemNumber]['answered_count']++;

                if (($itemData['is_correct'] ?? null) === true) {
                    $itemStats[$itemNumber]['correct_count']++;
                }
            }
        }

        $items = [];
        for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
            $items[] = $itemPayloadByNumber[$itemNumber] ?? [
                'item_number' => $itemNumber,
                'question_id' => null,
                'question_type' => null,
                'answered' => false,
                'response' => null,
                'is_correct' => null,
                'answered_at' => null,
            ];
        }

        return [
            'user' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'student_id' => $student->student_id,
            ],
            'attempt' => $attempt
                ? [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                    'answered_count' => (int) $attempt->answered_count,
                    'total_items' => (int) $attempt->total_items,
                    'score_percent' => is_null($attempt->score_percent) ? null : (float) $attempt->score_percent,
                    'started_at' => $attempt->started_at,
                    'submitted_at' => $attempt->submitted_at,
                ]
                : null,
            'items' => $items,
        ];
    })->all();

    $attemptsStarted = collect($rows)
        ->filter(fn (array $row) => !is_null($row['attempt'] ?? null))
        ->count();

    $attemptsSubmitted = collect($rows)
        ->filter(fn (array $row) => ($row['attempt']['status'] ?? null) === ExamAttempt::STATUS_SUBMITTED)
        ->count();

    $itemSummary = [];
    for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
        $startedCount = (int) $itemStats[$itemNumber]['started_count'];
        $answeredCount = (int) $itemStats[$itemNumber]['answered_count'];
        $correctCount = (int) $itemStats[$itemNumber]['correct_count'];

        $itemSummary[] = [
            'item_number' => $itemNumber,
            'started_count' => $startedCount,
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'answered_percent' => $startedCount > 0
                ? (int) round(($answeredCount / $startedCount) * 100)
                : 0,
            'correct_percent' => $answeredCount > 0
                ? (int) round(($correctCount / $answeredCount) * 100)
                : null,
        ];
    }

    return response()->json([
        'exam' => [
            'id' => $exam->id,
            'title' => $exam->title,
            'subject' => $exam->subject,
            'total_items' => (int) $exam->total_items,
            'duration_minutes' => (int) $exam->duration_minutes,
            'scheduled_at' => $exam->scheduled_at,
            'schedule_start_at' => $exam->schedule_start_at,
            'schedule_end_at' => $exam->schedule_end_at,
            'delivery_mode' => $deliveryMode,
            'shuffle_questions' => (bool) $exam->shuffle_questions,
            'one_take_only' => (bool) $exam->one_take_only,
        ],
        'room' => [
            'id' => $room->id,
            'name' => $room->name,
            'code' => $room->code,
        ],
        'summary' => [
            'students_total' => $students->count(),
            'attempts_started' => $attemptsStarted,
            'attempts_submitted' => $attemptsSubmitted,
        ],
        'rows' => $rows,
        'item_summary' => $itemSummary,
        'generated_at' => now(),
    ]);
});
