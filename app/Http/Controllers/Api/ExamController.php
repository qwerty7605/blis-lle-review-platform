<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\ExamDeliveryModeService;
use App\Services\ExamQuestionBanks;
use App\Services\ExamSeriesRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    public const NO_QUESTION_SET_MESSAGE = 'Choose at least one question set from the Library for this exam.';

    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
        private readonly ExamDeliveryModeService $deliveryModeService,
        private readonly ExamQuestionBanks $examQuestionBanks,
        private readonly ExamSeriesRules $examSeriesRules,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = Exam::query()
            ->with('creator:id,name')
            ->with([
                'questionBank:id,title,subject,total_items',
                'questionBanks:id,title,subject,total_items',
                'rooms:id,name,code',
                'series:id,name,type',
            ])
            ->withCount('rooms')
            ->when(!$this->oversees($user), fn ($query) => $query->where('created_by', $user->id))
            ->latest();

        $exams = $query->get()->map(function (Exam $exam) {
            $exam->delivery_mode = $this->deliveryModeService->normalize($exam->delivery_mode);
            $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

            return $exam;
        })->values();

        return response()->json([
            'exams' => $exams,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
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
            'ends_at_close' => ['nullable', 'boolean'],
            'one_take_only' => ['nullable', 'boolean'],
            'shuffle_questions' => ['nullable', 'boolean'],
            'results_visibility_mode' => ['nullable', Rule::in(['hidden', 'visible'])],
            // Left out, a regular mock exam.
            'exam_type' => ['nullable', Rule::in(Exam::TYPES)],
            'exam_series_id' => ['nullable', 'integer', 'exists:exam_series,id'],
            // Left out, they are taken from the exam's date (see Exam::booted()).
            'school_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'semester' => ['nullable', Rule::in(Exam::SEMESTERS)],
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

        $selectedQuestionBankIds = $this->examQuestionBanks->resolveIds($validated);

        // An exam with no questions can be scheduled but never taken, which a
        // teacher only finds out when the class cannot start it.
        if ($selectedQuestionBankIds === []) {
            return response()->json(['message' => self::NO_QUESTION_SET_MESSAGE], 422);
        }
        $selectedQuestionBanks = $this->examQuestionBanks->findAccessible($user, $selectedQuestionBankIds, $this->oversees($user));

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

        $examSubject = Exam::summarizeQuestionBankSubject($selectedQuestionBanks);
        $typing = $this->examSeriesRules->resolve(
            $validated['exam_type'] ?? Exam::TYPE_REGULAR,
            isset($validated['exam_series_id']) ? (int) $validated['exam_series_id'] : null,
            $examSubject,
        );

        if (is_string($typing)) {
            return response()->json(['message' => $typing, 'errors' => ['exam_series_id' => [$typing]]], 422);
        }

        $exam = Exam::create([
            'title' => $validated['title'],
            'subject' => $examSubject,
            ...$typing,
            'description' => $validated['description'] ?? null,
            'question_bank_id' => $selectedQuestionBankIds[0] ?? null,
            'total_items' => $validated['total_items'],
            'duration_minutes' => $validated['duration_minutes'],
            'scheduled_at' => $scheduleStartAt,
            'schedule_start_at' => $scheduleStartAt,
            'schedule_end_at' => $scheduleEndAt,
            'ends_at_close' => (bool) ($validated['ends_at_close'] ?? false),
            'delivery_mode' => $deliveryMode,
            'one_take_only' => (bool) ($validated['one_take_only'] ?? false),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'results_visibility_mode' => $validated['results_visibility_mode'] ?? 'hidden',
            'school_year' => $validated['school_year'] ?? null,
            'semester' => $validated['semester'] ?? null,
            'created_by' => $user->id,
        ]);

        $exam->syncQuestionBanks($selectedQuestionBankIds);

        $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

        if ($roomIds->isNotEmpty()) {
            $allowedCount = Room::query()
                ->whereIn('id', $roomIds)
                ->when(!$this->oversees($user), fn ($query) => $query->where('created_by', $user->id))
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
            'series:id,name,type',
        ])->loadCount('rooms');
        $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

        $this->recordAudit(
            $user,
            'exam.create',
            'exam',
            $exam->id,
            'Exam created',
            [
                'title' => $exam->title,
                'exam_type' => $exam->exam_type,
                'exam_series_id' => $exam->exam_series_id,
                'scheduled_at' => $exam->scheduled_at,
                'schedule_start_at' => $exam->schedule_start_at,
                'schedule_end_at' => $exam->schedule_end_at,
                'ends_at_close' => (bool) $exam->ends_at_close,
                'delivery_mode' => $this->deliveryModeService->normalize($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'question_bank_ids' => $exam->question_bank_ids,
                'one_take_only' => (bool) $exam->one_take_only,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
                'rooms_assigned' => $exam->rooms_count,
            ],
            $request,
        );

        $exam->delivery_mode = $this->deliveryModeService->normalize($exam->delivery_mode);

        return response()->json([
            'message' => 'Exam created',
            'exam' => $exam,
        ], 201);
    }

    public function update(Request $request, Exam $exam)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $exam->created_by)) {
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
            'ends_at_close' => ['sometimes', 'boolean'],
            'one_take_only' => ['sometimes', 'boolean'],
            'shuffle_questions' => ['sometimes', 'boolean'],
            'results_visibility_mode' => ['sometimes', Rule::in(['hidden', 'visible'])],
            'exam_type' => ['sometimes', 'required', Rule::in(Exam::TYPES)],
            'exam_series_id' => ['sometimes', 'nullable', 'integer', 'exists:exam_series,id'],
            'school_year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'semester' => ['sometimes', 'required', Rule::in(Exam::SEMESTERS)],
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

        $resolvedQuestionBankIds = $this->examQuestionBanks->resolveIds($validated, $exam);

        // Also an exam saved before this was required: it gets one on its next edit.
        if ($resolvedQuestionBankIds === []) {
            return response()->json(['message' => self::NO_QUESTION_SET_MESSAGE], 422);
        }
        $resolvedTotalItems = array_key_exists('total_items', $validated)
            ? (int) $validated['total_items']
            : (int) $exam->total_items;

        $resolvedQuestionBanks = $this->examQuestionBanks->findAccessible($user, $resolvedQuestionBankIds, $this->oversees($user));

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

        $examSubject = Exam::summarizeQuestionBankSubject($resolvedQuestionBanks);
        $typing = $this->examSeriesRules->resolve(
            $validated['exam_type'] ?? (string) $exam->exam_type,
            array_key_exists('exam_series_id', $validated)
                ? ($validated['exam_series_id'] === null ? null : (int) $validated['exam_series_id'])
                : ($exam->exam_series_id === null ? null : (int) $exam->exam_series_id),
            $examSubject,
            (int) $exam->id,
        );

        if (is_string($typing)) {
            return response()->json(['message' => $typing, 'errors' => ['exam_series_id' => [$typing]]], 422);
        }

        $previousType = (string) $exam->exam_type;
        $previousSeriesId = $exam->exam_series_id;

        $exam->fill(collect($validated)->except([
            'scheduled_at',
            'schedule_start_at',
            'schedule_end_at',
            'question_bank_ids',
            'exam_type',
            'exam_series_id',
        ])->all());
        $exam->fill($typing);
        $exam->subject = $examSubject;
        $exam->question_bank_id = $resolvedQuestionBankIds[0] ?? null;
        $exam->scheduled_at = $resolvedScheduleStart;
        $exam->schedule_start_at = $resolvedScheduleStart;
        $exam->schedule_end_at = $resolvedScheduleEnd;
        $exam->delivery_mode = Exam::DELIVERY_MODE_OPEN_NAVIGATION;

        $exam->save();
        $exam->syncQuestionBanks($resolvedQuestionBankIds);

        // A new closing time, or a change of what it means, reaches the students
        // already taking the exam: their deadlines are worked out again.
        if ($exam->wasChanged(['schedule_end_at', 'ends_at_close'])) {
            $exam->refreshOpenAttemptDeadlines();
        }

        if (array_key_exists('room_ids', $validated)) {
            $roomIds = collect($validated['room_ids'] ?? [])->unique()->values();

            if ($roomIds->isNotEmpty()) {
                $allowedCount = Room::query()
                    ->whereIn('id', $roomIds)
                    ->when(!$this->oversees($user), fn ($query) => $query->where('created_by', $user->id))
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

            // The form lists only rooms in use, so an archived room is never in
            // what it sends back. Its link stays: that room's results are this
            // exam's, and restoring the room has to bring the exam back with it.
            $syncPayload += $exam->rooms()
                ->whereNotNull('rooms.archived_at')
                ->pluck('rooms.id')
                ->mapWithKeys(fn ($roomId) => [$roomId => []])
                ->all();

            $exam->rooms()->sync($syncPayload);
        }

        $exam->load([
            'creator:id,name',
            'questionBank:id,title,subject,total_items',
            'questionBanks:id,title,subject,total_items',
            'rooms:id,name,code',
            'series:id,name,type',
        ])->loadCount('rooms');
        $exam->question_bank_ids = $exam->resolvedQuestionBankIds();

        // A type (or series) is what puts an exam into students' Estimated
        // Outcome. Changing it once there are results changes their outcomes,
        // so it is written down on its own.
        $attemptCount = ExamAttempt::query()->where('exam_id', $exam->id)->count();

        if ($attemptCount > 0 && ($previousType !== $exam->exam_type || (int) $previousSeriesId !== (int) $exam->exam_series_id)) {
            $this->recordAudit(
                $user,
                'exam.type.change',
                'exam',
                $exam->id,
                sprintf(
                    'Changed the type of "%s" from %s to %s with %d attempt(s) on record; students\' Estimated Outcomes follow it',
                    $exam->title,
                    Exam::TYPE_LABELS[$previousType] ?? $previousType,
                    Exam::TYPE_LABELS[$exam->exam_type] ?? $exam->exam_type,
                    $attemptCount,
                ),
                [
                    'from_type' => $previousType,
                    'to_type' => $exam->exam_type,
                    'from_series_id' => $previousSeriesId,
                    'to_series_id' => $exam->exam_series_id,
                    'attempts' => $attemptCount,
                ],
                $request,
            );
        }

        $this->recordAudit(
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
                'ends_at_close' => (bool) $exam->ends_at_close,
                'delivery_mode' => $this->deliveryModeService->normalize($exam->delivery_mode),
                'question_bank_id' => $exam->question_bank_id,
                'question_bank_ids' => $exam->question_bank_ids,
                'one_take_only' => (bool) $exam->one_take_only,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
                'rooms_assigned' => $exam->rooms_count,
            ],
            $request,
        );

        $exam->delivery_mode = $this->deliveryModeService->normalize($exam->delivery_mode);

        return response()->json([
            'message' => 'Exam updated',
            'exam' => $exam,
        ]);
    }

    public function destroy(Request $request, Exam $exam)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $exam->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // An exam students have sat cannot go: their attempts, answers and scores
        // all hang off it, and used to be deleted along with it without a word.
        // Archiving the exam from its rooms retires it with that history intact.
        if ($exam->attempts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this exam because students have already taken it. Archive it from its rooms instead, which keeps their results.',
            ], 422);
        }

        $examId = $exam->id;
        $examTitle = $exam->title;
        $examOwnerId = $exam->created_by;

        // One unit, so that a delete the database refuses -- an attempt started
        // since the check above -- does not leave the exam cut off from its rooms.
        DB::transaction(function () use ($exam): void {
            $exam->rooms()->detach();
            $exam->delete();
        });

        $this->recordAudit(
            $user,
            'exam.delete',
            'exam',
            $examId,
            'Exam deleted',
            ['title' => $examTitle, 'owner_id' => $examOwnerId],
            $request,
        );

        return response()->json(['message' => 'Exam deleted']);
    }

    /**
     * Retire an exam without deleting it: it is archived from every room it is
     * still in, in one go. The exam, its attempts and its results all stay, and
     * each room can restore it. This is what the Delete Exam dialog offers beside
     * Delete, and the way out for an exam students have already taken, which
     * cannot be deleted.
     */
    public function archive(Request $request, Exam $exam)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $exam->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $active = DB::table('exam_room')->where('exam_id', $exam->id)->whereNull('archived_at');
        $roomIds = (clone $active)->pluck('room_id')->map(fn ($id) => (int) $id)->all();

        if ($roomIds === []) {
            return response()->json([
                'message' => 'This exam is not in any room, or is already archived from all of them, so there is nothing to archive.',
            ], 422);
        }

        $archivedAt = now();

        $active->update([
            'archived_at' => $archivedAt,
            'archived_by' => $user->id,
            'updated_at' => $archivedAt,
        ]);

        $this->recordAudit(
            $user,
            'exam.archive',
            'exam',
            $exam->id,
            'Exam archived from its rooms',
            ['title' => $exam->title, 'owner_id' => $exam->created_by, 'room_ids' => $roomIds],
            $request,
        );

        return response()->json([
            'message' => sprintf(
                'Exam archived from %d %s. Its results are kept, and each room can restore it.',
                count($roomIds),
                count($roomIds) === 1 ? 'room' : 'rooms',
            ),
            'archived_rooms' => count($roomIds),
        ]);
    }

    public function itemAnalytics(Request $request, Exam $exam)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $exam->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'item_analytics' => $this->dashboardAnalyticsService->buildItemDifficulty($user, $exam),
        ]);
    }

    public function liveDashboard(Request $request, Exam $exam)
    {
        $user = $request->user();

        // The teacher who owns both the exam and the room, or an administrator,
        // who oversees every room and reads the board without owning either.
        $overseesAllRooms = $this->isAdmin($user);

        if (!$overseesAllRooms && !$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$overseesAllRooms && !$this->oversees($user, $exam->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $deliveryMode = $this->deliveryModeService->normalize($exam->delivery_mode);

        $room = Room::query()->find((int) $validated['room_id']);

        if (!$room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if (!$overseesAllRooms && !$this->oversees($user, $room->created_by)) {
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
                'ends_at_close' => (bool) $exam->ends_at_close,
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
    }
}
