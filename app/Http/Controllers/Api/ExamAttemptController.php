<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use App\Services\ExamAttemptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExamAttemptController extends Controller
{
    public function __construct(
        private readonly ExamAttemptService $examAttemptService,
    ) {
    }

    public function start(
        Request $request,
            Exam $exam,
    )
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json([
                'message' => 'Only student accounts can start exam attempts.',
            ], 403);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
        ]);

        $roomId = (int) $validated['room_id'];

        $isMember = $user->rooms()
            ->where('rooms.id', $roomId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'message' => 'You are not a member of the selected room.',
            ], 403);
        }

        // A room cannot be archived while anyone is mid-exam in it, so there is
        // no attempt here to resume: an archived room starts nothing.
        if (Room::query()->whereKey($roomId)->whereNotNull('archived_at')->exists()) {
            return response()->json([
                'message' => 'This room has been archived by your teacher.',
            ], 422);
        }

        $assignment = DB::table('exam_room')
            ->where('exam_id', $exam->id)
            ->where('room_id', $roomId)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'This exam is not assigned to the selected room.',
            ], 422);
        }

        // Decided inside the transaction below, after the look for an attempt
        // already under way: archiving an exam takes it off the room's list, and
        // a student who was in the middle of it still has to be able to finish.
        $assignmentArchived = !is_null($assignment->archived_at);

        $scheduleStartAt = $exam->schedule_start_at ?? $exam->scheduled_at;
        $scheduleEndAt = $exam->schedule_end_at;

        if ($scheduleStartAt && now()->lt($scheduleStartAt)) {
            return response()->json([
                'message' => 'This exam is not yet available based on schedule.',
                'available_at' => $scheduleStartAt,
            ], 422);
        }

        $questionBankIds = $exam->resolvedQuestionBankIds();

        if ($questionBankIds === []) {
            return response()->json([
                'message' => 'This exam has no question set configured yet.',
            ], 422);
        }

        $questionBankOrder = array_flip($questionBankIds);
        $questionBanks = QuestionBank::query()
            ->withCount('questions')
            ->whereIn('id', $questionBankIds)
            ->get()
            ->sortBy(fn (QuestionBank $bank) => $questionBankOrder[(int) $bank->id] ?? PHP_INT_MAX)
            ->values();

        if ($questionBanks->count() !== count($questionBankIds)) {
            return response()->json([
                'message' => 'One or more linked question sets could not be found.',
            ], 422);
        }

        $availableQuestionCount = (int) $questionBanks->sum('questions_count');
        $targetItems = min((int) $exam->total_items, $availableQuestionCount);

        if ($targetItems < 1) {
            return response()->json([
                'message' => 'No questions are available for this exam.',
            ], 422);
        }

        $maxAllowedAttempts = (bool) $exam->one_take_only ? 1 : 2;
        $durationMinutes = max(1, (int) $exam->duration_minutes);
        // Items per set (Exam::itemSplit()); null deals from all sets as one pool.
        $itemSplit = $exam->itemSplit();

        // Looking for an attempt and creating one have to be a single step per
        // student. They used to be two, with nothing in between: requests arriving
        // together -- Start pressed twice, the exam open in two tabs -- each saw
        // no attempt and each created one, which also stepped past the one-take
        // and retake limits. Holding the student's own row makes the second
        // request wait for the first, and then find the attempt it made.
        [$outcome, $attempt] = DB::transaction(function () use ($exam, $roomId, $user, $questionBankIds, $targetItems, $maxAllowedAttempts, $durationMinutes, $assignmentArchived, $scheduleEndAt, $itemSplit): array {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            // Every room, not only this one. The limits below count attempts on
            // the exam whichever room they were made in, but only finished ones:
            // looking for an open attempt in this room alone let a student in two
            // rooms that share the exam have one under way in each, and so sit a
            // one-take exam twice.
            $openAttempts = ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
                ->latest('started_at')
                ->get();

            $openElsewhere = null;

            foreach ($openAttempts as $openAttempt) {
                $openAttempt = $this->examAttemptService->autoSubmitExpiredAttempt($openAttempt);

                if ($openAttempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
                    continue;
                }

                if ((int) $openAttempt->room_id === $roomId) {
                    return ['resumed', $openAttempt];
                }

                $openElsewhere ??= $openAttempt;
            }

            if ($openElsewhere) {
                return ['open_elsewhere', $openElsewhere];
            }

            // Checked only now, after the look for an attempt under way: the
            // closing time stops new starts, but someone who started before it
            // comes back to finish (or, with ends_at_close, finds the attempt
            // already handed in at the closing time).
            if ($scheduleEndAt && now()->gt($scheduleEndAt)) {
                return ['closed', null];
            }

            // The room page stops listing an archived exam, but that is the page.
            // Without this the exam could still be started by asking for it.
            if ($assignmentArchived) {
                return ['archived', null];
            }

            $submittedAttemptCount = ExamAttempt::query()
                ->where('exam_id', $exam->id)
                ->where('user_id', $user->id)
                ->where('status', ExamAttempt::STATUS_SUBMITTED)
                ->count();

            if ($submittedAttemptCount >= $maxAllowedAttempts) {
                return ['limit_reached', null];
            }

            if ($itemSplit !== null) {
                // The same mix for every student: so many items from each set,
                // random within the set when the exam shuffles, else its first
                // ones in order; then the whole exam in a random order too.
                $questionIds = collect($itemSplit)
                    ->flatMap(function (int $count, int $bankId) use ($exam): array {
                        if ($count < 1) {
                            return [];
                        }

                        $fromSet = DB::table('question_bank_questions')->where('question_bank_id', $bankId);

                        if ((bool) $exam->shuffle_questions) {
                            $fromSet->inRandomOrder();
                        } else {
                            $fromSet->orderBy('item_number')->orderBy('id');
                        }

                        return $fromSet->limit($count)->pluck('id')->all();
                    })
                    ->values();

                $questionIds = ((bool) $exam->shuffle_questions ? $questionIds->shuffle() : $questionIds)->values()->all();
            } else {
                $questionQuery = DB::table('question_bank_questions')
                    ->whereIn('question_bank_id', $questionBankIds);

                if ((bool) $exam->shuffle_questions) {
                    $questionQuery->inRandomOrder();
                } else {
                    $bankOrderSql = collect($questionBankIds)
                        ->values()
                        ->map(fn ($bankId, $index) => 'WHEN ' . (int) $bankId . ' THEN ' . $index)
                        ->implode(' ');

                    $questionQuery
                        ->orderByRaw('CASE question_bank_id ' . $bankOrderSql . ' ELSE ' . count($questionBankIds) . ' END')
                        ->orderBy('item_number')
                        ->orderBy('id');
                }

                $questionIds = $questionQuery
                    ->limit($targetItems)
                    ->pluck('id')
                    ->values()
                    ->all();
            }
            $actualTotalItems = count($questionIds);

            if ($actualTotalItems < 1) {
                return ['no_questions', null];
            }

            $startedAt = now();

            $createdAttempt = ExamAttempt::create([
                'exam_id' => $exam->id,
                'room_id' => $roomId,
                'user_id' => $user->id,
                'status' => ExamAttempt::STATUS_IN_PROGRESS,
                'total_items' => $actualTotalItems,
                'duration_minutes' => $durationMinutes,
                'answered_count' => 0,
                'correct_answers' => 0,
                'started_at' => $startedAt,
                'expires_at' => $exam->attemptDeadline($startedAt, $durationMinutes),
            ]);

            $rows = [];

            foreach ($questionIds as $index => $questionId) {
                $rows[] = [
                    'exam_attempt_id' => $createdAttempt->id,
                    'question_bank_question_id' => $questionId,
                    'item_number' => $index + 1,
                    'is_bookmarked' => false,
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ];
            }

            ExamAttemptQuestion::insert($rows);

            return ['started', $createdAttempt->refresh()];
        });

        if ($outcome === 'resumed') {
            return response()->json([
                'message' => 'Resuming your active attempt.',
                'resumed' => true,
                ...$this->examAttemptService->buildAttemptPayload($attempt),
            ]);
        }

        if ($outcome === 'open_elsewhere') {
            $otherRoomName = Room::query()->whereKey($attempt->room_id)->value('name');

            return response()->json([
                'message' => $otherRoomName
                    ? "You already have this exam in progress in {$otherRoomName}. Finish it there first."
                    : 'You already have this exam in progress in another room. Finish it there first.',
            ], 422);
        }

        if ($outcome === 'closed') {
            return response()->json([
                'message' => 'This exam is no longer available because the schedule window has ended.',
                'available_until' => $scheduleEndAt,
            ], 422);
        }

        if ($outcome === 'archived') {
            return response()->json([
                'message' => 'This exam has been archived from this room and can no longer be started.',
            ], 422);
        }

        if ($outcome === 'limit_reached') {
            return response()->json([
                'message' => (bool) $exam->one_take_only
                    ? 'This exam allows one attempt only and your attempt is already submitted.'
                    : 'Retake limit reached. You can only take this exam twice (1 initial attempt + 1 retake).',
            ], 422);
        }

        if ($outcome === 'no_questions') {
            return response()->json([
                'message' => 'No questions are available for this exam.',
            ], 422);
        }

        $this->recordAudit(
            $user,
            'exam.attempt.start',
            'exam_attempt',
            $attempt->id,
            'Student started an exam attempt',
            [
                'exam_id' => $exam->id,
                'room_id' => $roomId,
                'total_items' => $attempt->total_items,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Exam attempt started.',
            'resumed' => false,
            ...$this->examAttemptService->buildAttemptPayload($attempt),
        ], 201);
    }

    public function show(
        Request $request,
            ExamAttempt $attempt,
    )
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $this->examAttemptService->autoSubmitExpiredAttempt($attempt);

        return response()->json($this->examAttemptService->buildAttemptPayload($attempt));
    }

    public function saveAnswers(
        Request $request,
            ExamAttempt $attempt,
    )
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $this->examAttemptService->autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'This attempt is already submitted.',
                ...$this->examAttemptService->buildAttemptPayload($attempt),
            ], 422);
        }

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:question_bank_questions,id'],
            'selected_option_id' => ['nullable', 'integer', 'exists:question_bank_options,id'],
            'answer_text' => ['nullable', 'string'],
        ]);

        $questionId = (int) $validated['question_id'];

        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_bank_question_id', $questionId)
            ->first();

        if (!$attemptQuestion) {
            return response()->json([
                'message' => 'The selected question is not part of this attempt.',
            ], 422);
        }

        $question = $attemptQuestion->question()
            ->with('options:id,question_bank_question_id,option_label,option_text,is_correct')
            ->first();

        if (!$question) {
            return response()->json([
                'message' => 'Question not found.',
            ], 422);
        }

        $selectedOptionId = is_null($validated['selected_option_id'] ?? null)
            ? null
            : (int) $validated['selected_option_id'];
        $answerText = array_key_exists('answer_text', $validated)
            ? trim((string) $validated['answer_text'])
            : null;
        $answerText = $answerText === '' ? null : $answerText;

        if ($question->question_type === 'open_ended') {
            if (is_null($answerText)) {
                ExamAttemptAnswer::query()
                    ->where('exam_attempt_id', $attempt->id)
                    ->where('question_bank_question_id', $questionId)
                    ->delete();
            } else {
                $isCorrect = $this->examAttemptService->isCorrectOpenAnswer($question, $answerText);

                ExamAttemptAnswer::updateOrCreate(
                    [
                        'exam_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $questionId,
                    ],
                    [
                        'question_bank_option_id' => null,
                        'answer_text' => $answerText,
                        'is_correct' => $isCorrect,
                        'answered_at' => now(),
                    ],
                );
            }
        } else {
            if (is_null($selectedOptionId)) {
                ExamAttemptAnswer::query()
                    ->where('exam_attempt_id', $attempt->id)
                    ->where('question_bank_question_id', $questionId)
                    ->delete();
            } else {
                $selectedOption = $question->options
                    ->firstWhere('id', $selectedOptionId);

                if (!$selectedOption) {
                    return response()->json([
                        'message' => 'Selected option is not valid for this question.',
                    ], 422);
                }

                ExamAttemptAnswer::updateOrCreate(
                    [
                        'exam_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $questionId,
                    ],
                    [
                        'question_bank_option_id' => $selectedOption->id,
                        'answer_text' => $selectedOption->option_text,
                        'is_correct' => (bool) $selectedOption->is_correct,
                        'answered_at' => now(),
                    ],
                );
            }
        }

        $attempt = $this->examAttemptService->refreshMetrics($attempt->refresh(), false);
        $attempt = $this->examAttemptService->autoSubmitExpiredAttempt($attempt);

        return response()->json([
            'message' => 'Answer saved.',
            ...$this->examAttemptService->buildAttemptPayload($attempt),
        ]);
    }

    public function bookmark(
        Request $request,
            ExamAttempt $attempt,
            int $questionId,
    )
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $this->examAttemptService->autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'This attempt is already submitted.',
                ...$this->examAttemptService->buildAttemptPayload($attempt),
            ], 422);
        }

        $validated = $request->validate([
            'is_bookmarked' => ['required', 'boolean'],
        ]);

        $attemptQuestion = $attempt->attemptQuestions()
            ->where('question_bank_question_id', $questionId)
            ->first();

        if (!$attemptQuestion) {
            return response()->json([
                'message' => 'The selected question is not part of this attempt.',
            ], 422);
        }

        $attemptQuestion->is_bookmarked = (bool) $validated['is_bookmarked'];
        $attemptQuestion->save();

        return response()->json([
            'message' => $attemptQuestion->is_bookmarked ? 'Question bookmarked.' : 'Bookmark removed.',
            ...$this->examAttemptService->buildAttemptPayload($attempt->refresh()),
        ]);
    }

    public function submit(
        Request $request,
            ExamAttempt $attempt,
    )
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ((int) $attempt->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attempt = $this->examAttemptService->autoSubmitExpiredAttempt($attempt);

        if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'Attempt already submitted.',
                ...$this->examAttemptService->buildAttemptPayload($attempt),
            ]);
        }

        $attempt = $this->examAttemptService->refreshMetrics($attempt, true);

        $this->recordAudit(
            $user,
            'exam.attempt.submit',
            'exam_attempt',
            $attempt->id,
            'Student submitted an exam attempt',
            [
                'exam_id' => $attempt->exam_id,
                'room_id' => $attempt->room_id,
                'score_percent' => $attempt->score_percent,
                'correct_answers' => $attempt->correct_answers,
                'total_items' => $attempt->total_items,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Attempt submitted successfully.',
            ...$this->examAttemptService->buildAttemptPayload($attempt),
        ]);
    }
}
