<?php

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/student/dashboard', function (Request $request) use ($canManageRooms, $ensureActive, $normalizeDeliveryMode) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json([
            'message' => 'Only student accounts can access this dashboard.',
        ], 403);
    }

    $rooms = $user->rooms()
        ->select(
            'rooms.id',
            'rooms.name',
            'rooms.code',
            'rooms.created_by',
            'rooms.created_at',
            'rooms.updated_at',
            'room_user.created_at as joined_at'
        )
        ->with('creator:id,name')
        ->withCount(['members', 'exams'])
        ->orderByDesc('room_user.created_at')
        ->get();

    $rooms->load([
        'exams' => function ($query) {
            $query
                ->with([
                    'questionBank:id',
                    'questionBanks:id',
                ])
                ->select(
                    'exams.id',
                    'exams.title',
                    'exams.subject',
                    'exams.question_bank_id',
                    'exams.total_items',
                    'exams.duration_minutes',
                    'exams.scheduled_at',
                    'exams.schedule_start_at',
                    'exams.schedule_end_at',
                    'exams.delivery_mode',
                    'exams.one_take_only',
                    'exams.shuffle_questions',
                    'exam_room.created_at as assigned_at'
                )
                ->orderByDesc('exam_room.created_at');
        },
    ]);

    $examIds = $rooms
        ->flatMap(fn (Room $room) => $room->exams->pluck('id'))
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->unique()
        ->values()
        ->all();

    $attemptsByExamId = collect();

    if ($examIds !== []) {
        $attemptsByExamId = ExamAttempt::query()
            ->whereIn('exam_id', $examIds)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ExamAttempt::STATUS_IN_PROGRESS,
                ExamAttempt::STATUS_SUBMITTED,
            ])
            ->select('id', 'exam_id', 'room_id', 'status', 'submitted_at', 'started_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('exam_id');
    }

    $roomCards = $rooms
        ->map(function (Room $room) use ($attemptsByExamId, $normalizeDeliveryMode) {
            $assignedExams = $room->exams
                ->map(function (Exam $assignedExam) use ($attemptsByExamId, $room, $normalizeDeliveryMode) {
                    $attempts = $attemptsByExamId->get((int) $assignedExam->id, collect());
                    $submittedAttempts = $attempts->filter(
                        fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED
                    );

                    $inProgressAttempt = $attempts->first(
                        fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_IN_PROGRESS
                            && (int) $attempt->room_id === (int) $room->id
                    );

                    $submittedAttempt = $submittedAttempts->first();
                    $submittedAttemptCount = (int) $submittedAttempts->count();
                    $maxAllowedAttempts = (bool) $assignedExam->one_take_only ? 1 : 2;
                    $remainingAttempts = max(0, $maxAllowedAttempts - $submittedAttemptCount);

                    $attemptState = 'not_started';
                    $latestAttemptId = null;

                    if ($inProgressAttempt) {
                        $attemptState = 'in_progress';
                        $latestAttemptId = (int) $inProgressAttempt->id;
                    } elseif ($submittedAttempt) {
                        $attemptState = 'submitted';
                        $latestAttemptId = (int) $submittedAttempt->id;
                    }

                    return [
                        'id' => (int) $assignedExam->id,
                        'title' => $assignedExam->title,
                        'subject' => $assignedExam->subject,
                        'question_bank_id' => $assignedExam->question_bank_id,
                        'question_bank_ids' => $assignedExam->resolvedQuestionBankIds(),
                        'total_items' => (int) $assignedExam->total_items,
                        'duration_minutes' => (int) $assignedExam->duration_minutes,
                        'scheduled_at' => $assignedExam->scheduled_at,
                        'schedule_start_at' => $assignedExam->schedule_start_at,
                        'schedule_end_at' => $assignedExam->schedule_end_at,
                        'delivery_mode' => $normalizeDeliveryMode($assignedExam->delivery_mode),
                        'one_take_only' => (bool) $assignedExam->one_take_only,
                        'shuffle_questions' => (bool) $assignedExam->shuffle_questions,
                        'assigned_at' => $assignedExam->assigned_at,
                        'student_attempt_state' => $attemptState,
                        'student_attempt_id' => $latestAttemptId,
                        'student_submitted_at' => $submittedAttempt?->submitted_at,
                        'student_submitted_attempts' => $submittedAttemptCount,
                        'student_max_attempts' => $maxAllowedAttempts,
                        'student_attempts_remaining' => $remainingAttempts,
                        'student_can_start_attempt' => $remainingAttempts > 0,
                    ];
                })
                ->values();

            return [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
                'joined_at' => $room->joined_at,
                'created_at' => $room->created_at,
                'creator' => $room->creator,
                'members_count' => (int) $room->members_count,
                'exams_count' => (int) $room->exams_count,
                'assigned_exams' => $assignedExams,
            ];
        })
        ->values();

    $submittedAttemptsQuery = ExamAttempt::query()
        ->where('user_id', $user->id)
        ->where('status', ExamAttempt::STATUS_SUBMITTED);

    $submittedAttemptsCount = (clone $submittedAttemptsQuery)->count();
    $averageScorePercent = (clone $submittedAttemptsQuery)->avg('score_percent');

    $recentExams = ExamAttempt::query()
        ->with([
            'exam:id,title,subject,total_items,duration_minutes',
            'room:id,name,code',
        ])
        ->where('user_id', $user->id)
        ->where('status', ExamAttempt::STATUS_SUBMITTED)
        ->orderByDesc('submitted_at')
        ->orderByDesc('id')
        ->limit(6)
        ->get()
        ->map(function (ExamAttempt $attempt) {
            return [
                'attempt_id' => (int) $attempt->id,
                'exam_id' => (int) $attempt->exam_id,
                'room_id' => (int) $attempt->room_id,
                'title' => $attempt->exam?->title ?? 'Untitled Exam',
                'subject' => $attempt->exam?->subject,
                'total_items' => (int) $attempt->total_items,
                'duration_minutes' => (int) $attempt->duration_minutes,
                'answered_count' => (int) $attempt->answered_count,
                'correct_answers' => (int) $attempt->correct_answers,
                'score_percent' => (float) $attempt->score_percent,
                'submitted_at' => $attempt->submitted_at,
                'room' => [
                    'id' => (int) ($attempt->room?->id ?? 0),
                    'name' => $attempt->room?->name,
                    'code' => $attempt->room?->code,
                ],
            ];
        })
        ->values();

    return response()->json([
        'stats' => [
            'rooms_count' => $roomCards->count(),
            'assigned_exams_count' => $roomCards->sum(
                fn (array $roomCard) => count($roomCard['assigned_exams'] ?? [])
            ),
            'submitted_attempts_count' => $submittedAttemptsCount,
            'average_score_percent' => is_null($averageScorePercent)
                ? null
                : round((float) $averageScorePercent, 1),
        ],
        'recent_exams' => $recentExams,
        'rooms' => $roomCards,
    ]);
});

Route::get('/student/analytics', function (Request $request) use ($canManageRooms, $ensureActive, $dashboardAnalyticsService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json([
            'message' => 'Only student accounts can access analytics.',
        ], 403);
    }

    $canonicalSubjects = [
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Information Technology',
        'Reference Services',
        'Library Management',
        'Selection and Acquisition',
    ];

    $subjectAliases = [
        'cataloging and classification' => 'Cataloging and Classification',
        'cataloguing and classification' => 'Cataloging and Classification',
        'cataloging' => 'Cataloging and Classification',
        'indexing and abstracting' => 'Indexing and Abstracting',
        'indexing' => 'Indexing and Abstracting',
        'information technology' => 'Information Technology',
        'it' => 'Information Technology',
        'reference' => 'Reference Services',
        'reference services' => 'Reference Services',
        'library management' => 'Library Management',
        'selection and acquisition' => 'Selection and Acquisition',
        'selection' => 'Selection and Acquisition',
    ];

    $normalizeSubject = static function (?string $subject) use ($subjectAliases): ?string {
        $normalized = Str::of((string) $subject)
            ->lower()
            ->replace('&', 'and')
            ->replace('/', ' ')
            ->replace('-', ' ')
            ->squish()
            ->toString();

        if ($normalized === '') {
            return null;
        }

        return $subjectAliases[$normalized] ?? trim((string) $subject);
    };

    $attempts = ExamAttempt::query()
        ->with([
            'exam:id,title,subject,question_bank_id,total_items,duration_minutes',
            'exam.questionBank:id,subject',
            'exam.questionBanks:id,subject',
            'room:id,name,code',
        ])
        ->where('user_id', $user->id)
        ->where('status', ExamAttempt::STATUS_SUBMITTED)
        ->orderByDesc('submitted_at')
        ->orderByDesc('id')
        ->get()
        ->map(function (ExamAttempt $attempt) use ($normalizeSubject) {
            $exam = $attempt->exam;

            $rawSubject = trim((string) ($exam?->subject ?? ''));
            if ($rawSubject === '' && $exam) {
                $rawSubject = (string) (Exam::summarizeQuestionBankSubject($exam->resolvedQuestionBanks()) ?? '');
            }

            $resolvedSubject = $normalizeSubject($rawSubject);

            return [
                'attempt_id' => (int) $attempt->id,
                'exam_id' => (int) $attempt->exam_id,
                'title' => $exam?->title ?? 'Untitled Exam',
                'subject' => $resolvedSubject ?? ($rawSubject !== '' ? $rawSubject : 'Uncategorized'),
                'score_percent' => is_null($attempt->score_percent)
                    ? null
                    : round((float) $attempt->score_percent, 2),
                'answered_count' => (int) $attempt->answered_count,
                'correct_answers' => (int) $attempt->correct_answers,
                'total_items' => (int) $attempt->total_items,
                'duration_minutes' => (int) $attempt->duration_minutes,
                'submitted_at' => $attempt->submitted_at,
                'room' => [
                    'id' => (int) ($attempt->room?->id ?? 0),
                    'name' => $attempt->room?->name,
                    'code' => $attempt->room?->code,
                ],
            ];
        })
        ->values();

    $scoreValues = $attempts
        ->pluck('score_percent')
        ->filter(fn ($score) => !is_null($score))
        ->values();

    $subjectProfiles = collect($canonicalSubjects)
        ->map(function (string $subjectLabel) use ($attempts) {
            $subjectAttempts = $attempts
                ->filter(fn (array $attempt) => $attempt['subject'] === $subjectLabel)
                ->values();

            $scores = $subjectAttempts
                ->pluck('score_percent')
                ->filter(fn ($score) => !is_null($score))
                ->values();

            $latestScore = $scores->first();
            $previousScore = $scores->skip(1)->first();
            $trendDelta = !is_null($latestScore) && !is_null($previousScore)
                ? round((float) $latestScore - (float) $previousScore, 1)
                : null;

            if ($scores->isEmpty()) {
                $performanceBand = 'not_started';
                $trend = 'none';
            } else {
                $referenceScore = !is_null($latestScore)
                    ? (float) $latestScore
                    : (float) $scores->avg();

                if ($referenceScore >= 85) {
                    $performanceBand = 'strong';
                } elseif ($referenceScore >= 75) {
                    $performanceBand = 'developing';
                } else {
                    $performanceBand = 'focus';
                }

                if (is_null($trendDelta) || abs($trendDelta) < 1) {
                    $trend = 'steady';
                } elseif ($trendDelta > 0) {
                    $trend = 'up';
                } else {
                    $trend = 'down';
                }
            }

            return [
                'label' => $subjectLabel,
                'attempts_count' => $subjectAttempts->count(),
                'average_score_percent' => $scores->isNotEmpty()
                    ? round((float) $scores->avg(), 1)
                    : null,
                'latest_score_percent' => !is_null($latestScore)
                    ? round((float) $latestScore, 1)
                    : null,
                'best_score_percent' => $scores->isNotEmpty()
                    ? round((float) $scores->max(), 1)
                    : null,
                'trend' => $trend,
                'trend_delta' => $trendDelta,
                'performance_band' => $performanceBand,
            ];
        })
        ->values();

    $latestAttempt = $attempts->first();
    $previousAttempt = $attempts->skip(1)->first();
    $latestScorePercent = $latestAttempt['score_percent'] ?? null;
    $latestDelta = !is_null($latestScorePercent) && !is_null($previousAttempt['score_percent'] ?? null)
        ? round((float) $latestScorePercent - (float) $previousAttempt['score_percent'], 1)
        : null;

    $focusSubjectsCount = $subjectProfiles
        ->filter(fn (array $subject) => $subject['performance_band'] === 'focus')
        ->count();

    $history = $attempts
        ->take(8)
        ->reverse()
        ->values()
        ->map(function (array $attempt, int $index) {
            return [
                ...$attempt,
                'label' => 'A' . ($index + 1),
            ];
        });

    $letFeatureBuilder = new \App\Services\MachineLearning\LetReadinessFeatureBuilder();
    $letFeatureResult = $letFeatureBuilder->build(
        $attempts,
        $subjectProfiles,
        $scoreValues->isNotEmpty() ? (float) $scoreValues->avg() : null,
    );

    if ($letFeatureResult['ready']) {
        $letPrediction = (new \App\Services\MachineLearning\LetPredictionService())->predict($letFeatureResult['features']);

        $predictiveModel = [
            'status' => 'connected',
            'title' => 'Predictive Readiness Model',
            'description' => 'Estimated with a J48 decision tree trained on a small sample of past students\' mock exam history and LET outcomes. Treat this as a rough signal, not a certainty.',
            'ml_api_endpoint' => '/api/predictions/let',
            'expected_inputs' => array_values(\App\Services\MachineLearning\LetReadinessFeatureBuilder::SUBJECT_FEATURE_LABELS),
            'prediction' => $letPrediction['prediction'],
            'probability_percent' => $letPrediction['confidence'],
            'risk_band' => $letPrediction['prediction'] === \App\Services\MachineLearning\LetPredictionService::RESULT_PASS
                ? ($letPrediction['confidence'] >= 80 ? 'strong' : 'developing')
                : 'focus',
            'decision_rules' => $letPrediction['path'],
            'support' => $letPrediction['support'],
        ];
    } else {
        $predictiveModel = [
            'status' => 'insufficient_history',
            'title' => 'Predictive Readiness Model',
            'description' => $letFeatureResult['reason'],
            'ml_api_endpoint' => '/api/predictions/let',
            'expected_inputs' => array_values(\App\Services\MachineLearning\LetReadinessFeatureBuilder::SUBJECT_FEATURE_LABELS),
            'prediction' => null,
            'probability_percent' => null,
            'risk_band' => null,
            'decision_rules' => [],
            'support' => null,
        ];
    }

    return response()->json([
        'stats' => [
            'completed_attempts' => $attempts->count(),
            'average_score_percent' => $scoreValues->isNotEmpty()
                ? round((float) $scoreValues->avg(), 1)
                : null,
            'latest_score_percent' => !is_null($latestScorePercent)
                ? round((float) $latestScorePercent, 1)
                : null,
            'latest_score_delta' => $latestDelta,
            'focus_subjects_count' => $focusSubjectsCount,
            'passing_threshold' => 75,
        ],
        'subjects' => $subjectProfiles,
        'history' => $history,
        'recent_attempts' => $attempts->take(6)->values(),
        'predictive_model' => $predictiveModel,
        // Same subject -> topic -> subtopic weak-area resolution "Review Your
        // Weak Areas" already uses (see DashboardAnalyticsService), reused
        // here so "What To Review Next" can name the actual weakest
        // subtopic, not just the subject it lives under.
        'weak_area' => $dashboardAnalyticsService->buildWeakAreaRecommendation($user),
    ]);
});

Route::post('/student/exams/{exam}/start', function (
    Request $request,
    Exam $exam
) use (
    $canManageRooms,
    $ensureActive,
    $recordAudit,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
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

    $isAssigned = $exam->rooms()
        ->where('rooms.id', $roomId)
        ->exists();

    if (!$isAssigned) {
        return response()->json([
            'message' => 'This exam is not assigned to the selected room.',
        ], 422);
    }

    $scheduleStartAt = $exam->schedule_start_at ?? $exam->scheduled_at;
    $scheduleEndAt = $exam->schedule_end_at;

    if ($scheduleStartAt && now()->lt($scheduleStartAt)) {
        return response()->json([
            'message' => 'This exam is not yet available based on schedule.',
            'available_at' => $scheduleStartAt,
        ], 422);
    }

    if ($scheduleEndAt && now()->gt($scheduleEndAt)) {
        return response()->json([
            'message' => 'This exam is no longer available because the schedule window has ended.',
            'available_until' => $scheduleEndAt,
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

    $existingAttempt = ExamAttempt::query()
        ->where('exam_id', $exam->id)
        ->where('room_id', $roomId)
        ->where('user_id', $user->id)
        ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
        ->latest('started_at')
        ->first();

    if ($existingAttempt) {
        $existingAttempt = $autoSubmitExpiredAttempt($existingAttempt);

        if ($existingAttempt->status === ExamAttempt::STATUS_IN_PROGRESS) {
            return response()->json([
                'message' => 'Resuming your active attempt.',
                'resumed' => true,
                ...$buildAttemptPayload($existingAttempt),
            ]);
        }
    }

    $submittedAttemptCount = ExamAttempt::query()
        ->where('exam_id', $exam->id)
        ->where('user_id', $user->id)
        ->where('status', ExamAttempt::STATUS_SUBMITTED)
        ->count();

    $maxAllowedAttempts = (bool) $exam->one_take_only ? 1 : 2;

    if ($submittedAttemptCount >= $maxAllowedAttempts) {
        return response()->json([
            'message' => (bool) $exam->one_take_only
                ? 'This exam allows one attempt only and your attempt is already submitted.'
                : 'Retake limit reached. You can only take this exam twice (1 initial attempt + 1 retake).',
        ], 422);
    }

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
    $actualTotalItems = count($questionIds);

    if ($actualTotalItems < 1) {
        return response()->json([
            'message' => 'No questions are available for this exam.',
        ], 422);
    }

    $durationMinutes = max(1, (int) $exam->duration_minutes);
    $startedAt = now();

    $attempt = DB::transaction(function () use (
        $exam,
        $roomId,
        $user,
        $questionIds,
        $actualTotalItems,
        $durationMinutes,
        $startedAt
    ) {
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
            'expires_at' => $startedAt->copy()->addMinutes($durationMinutes),
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

        return $createdAttempt->refresh();
    });

    $recordAudit(
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
        ...$buildAttemptPayload($attempt),
    ], 201);
});

Route::get('/student/exam-attempts/{attempt}', function (
    Request $request,
    ExamAttempt $attempt
) use (
    $canManageRooms,
    $ensureActive,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $attempt->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $attempt = $autoSubmitExpiredAttempt($attempt);

    return response()->json($buildAttemptPayload($attempt));
});

Route::patch('/student/exam-attempts/{attempt}/answers', function (
    Request $request,
    ExamAttempt $attempt
) use (
    $canManageRooms,
    $ensureActive,
    $normalizeAnswerText,
    $refreshAttemptMetrics,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $attempt->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $attempt = $autoSubmitExpiredAttempt($attempt);

    if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
        return response()->json([
            'message' => 'This attempt is already submitted.',
            ...$buildAttemptPayload($attempt),
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
            $normalizedSubmitted = $normalizeAnswerText($answerText);
            $normalizedExpected = $normalizeAnswerText($question->answer_text);
            $normalizedAnswerLabel = $normalizeAnswerText($question->answer_label);

            $isCorrect = false;

            if ($normalizedExpected !== '' && $normalizedSubmitted === $normalizedExpected) {
                $isCorrect = true;
            } elseif ($normalizedAnswerLabel !== '' && $normalizedSubmitted === $normalizedAnswerLabel) {
                $isCorrect = true;
            }

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

    $attempt = $refreshAttemptMetrics($attempt->refresh(), false);
    $attempt = $autoSubmitExpiredAttempt($attempt);

    return response()->json([
        'message' => 'Answer saved.',
        ...$buildAttemptPayload($attempt),
    ]);
});

Route::patch('/student/exam-attempts/{attempt}/questions/{questionId}/bookmark', function (
    Request $request,
    ExamAttempt $attempt,
    int $questionId
) use (
    $canManageRooms,
    $ensureActive,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $attempt->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $attempt = $autoSubmitExpiredAttempt($attempt);

    if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
        return response()->json([
            'message' => 'This attempt is already submitted.',
            ...$buildAttemptPayload($attempt),
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
        ...$buildAttemptPayload($attempt->refresh()),
    ]);
});

Route::post('/student/exam-attempts/{attempt}/submit', function (
    Request $request,
    ExamAttempt $attempt
) use (
    $canManageRooms,
    $ensureActive,
    $recordAudit,
    $refreshAttemptMetrics,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $attempt->user_id !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $attempt = $autoSubmitExpiredAttempt($attempt);

    if ($attempt->status !== ExamAttempt::STATUS_IN_PROGRESS) {
        return response()->json([
            'message' => 'Attempt already submitted.',
            ...$buildAttemptPayload($attempt),
        ]);
    }

    $attempt = $refreshAttemptMetrics($attempt, true);

    $recordAudit(
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
        ...$buildAttemptPayload($attempt),
    ]);
});

Route::get('/students/directory', function (Request $request) use ($canManageRooms, $ensureActive, $dashboardAnalyticsService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    return response()->json($dashboardAnalyticsService->buildStudentDirectory());
});
