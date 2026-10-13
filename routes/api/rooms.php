<?php

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

Route::get('/rooms', function (Request $request) use ($canManageRooms, $ensureActive, $isAdmin) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        $roomsQuery = Room::query()
            ->with('creator:id,name')
            ->withCount([
                'members as members_count' => fn ($query) => $query
                    ->where('users.role', User::ROLE_STUDENT)
                    ->whereNull('users.archived_at'),
                'exams as exams_count' => fn ($query) => $query
                    ->whereNull('exam_room.archived_at'),
                'archivedStudentMembers as archived_members_count',
                'archivedExams as archived_exams_count',
            ])
            ->whereNull('rooms.archived_at')
            ->latest();

        if (!$isAdmin($user)) {
            $roomsQuery->where('created_by', $user->id);
        }

        $rooms = $roomsQuery->get();
    } else {
        $rooms = $user->rooms()
            ->with('creator:id,name')
            ->withCount([
                'members as members_count' => fn ($query) => $query
                    ->where('users.role', User::ROLE_STUDENT)
                    ->whereNull('users.archived_at'),
                'exams as exams_count' => fn ($query) => $query
                    ->whereNull('exam_room.archived_at'),
                'archivedStudentMembers as archived_members_count',
                'archivedExams as archived_exams_count',
            ])
            ->whereNull('rooms.archived_at')
            ->orderByDesc('rooms.created_at')
            ->get();
    }

    return response()->json(['rooms' => $rooms]);
});

Route::get('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $normalizeDeliveryMode) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    } else {
        $isMember = $user->rooms()->where('rooms.id', $room->id)->exists();
        if (!$isMember) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
    }

    $members = $room->members()
        ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.role', 'users.is_active', 'users.year_level', 'users.archived_at')
        ->where('users.role', User::ROLE_STUDENT)
        ->whereNull('users.archived_at')
        ->orderByRaw('CASE WHEN users.year_level IS NULL THEN 5 ELSE users.year_level END')
        ->orderBy('users.name')
        ->get();

    $archivedMembers = $room->members()
        ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.role', 'users.is_active', 'users.year_level', 'users.archived_at')
        ->where('users.role', User::ROLE_STUDENT)
        ->whereNotNull('users.archived_at')
        ->orderBy('users.name')
        ->get();

    // Everyone who may open the room sees its exams. Administrators were
    // left out, so the room they oversee showed its students and nothing
    // about what those students were being examined on.
    $loadRoomExams = static function ($relation, bool $archived) use ($normalizeDeliveryMode) {
        return $relation
            ->with([
                'questionBank:id,title,subject,total_items',
                'questionBanks:id,title,subject,total_items',
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
                'exam_room.created_at as assigned_at',
                'exam_room.archived_at as room_archived_at'
            )
            ->orderByDesc($archived ? 'exam_room.archived_at' : 'exam_room.created_at')
            ->get()
            ->map(function ($assignedExam) use ($normalizeDeliveryMode, $archived) {
                $assignedExam->delivery_mode = $normalizeDeliveryMode($assignedExam->delivery_mode);
                $assignedExam->question_bank_ids = $assignedExam->resolvedQuestionBankIds();
                $assignedExam->room_assignment_archived = $archived;

                return $assignedExam;
            })
            ->values();
    };

    $assignedExams = $loadRoomExams($room->activeExams(), false);
    $archivedExams = $loadRoomExams($room->archivedExams(), true);

    if (
        !$canManageRooms($user)
        && ($assignedExams->isNotEmpty() || $archivedExams->isNotEmpty())
    ) {
        $examIds = $assignedExams
            ->concat($archivedExams)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

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

        $appendStudentAttemptState = static function ($roomExams, bool $archivedAssignment) use ($attemptsByExamId, $room) {
            return $roomExams
                ->map(function ($assignedExam) use ($attemptsByExamId, $room, $archivedAssignment) {
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

                    $assignedExam->student_attempt_state = $attemptState;
                    $assignedExam->student_attempt_id = $latestAttemptId;
                    $assignedExam->student_submitted_at = $submittedAttempt?->submitted_at;
                    $assignedExam->student_submitted_attempts = $submittedAttemptCount;
                    $assignedExam->student_max_attempts = $maxAllowedAttempts;
                    $assignedExam->student_attempts_remaining = $remainingAttempts;
                    $assignedExam->student_can_start_attempt = $assignedExam->studentCanStartAttempt($remainingAttempts, $archivedAssignment);
                    $assignedExam->room_assignment_archived = $archivedAssignment;

                    return $assignedExam;
                })
                ->values();
        };

        $assignedExams = $appendStudentAttemptState($assignedExams, false);
        $archivedExams = $appendStudentAttemptState($archivedExams, true);
    }

    if (
        $canManageRooms($user)
        && ($assignedExams->isNotEmpty() || $archivedExams->isNotEmpty())
    ) {
        $examIds = $assignedExams
            ->concat($archivedExams)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $roomAttempts = ExamAttempt::query()
            ->whereIn('exam_id', $examIds)
            ->where('room_id', $room->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->selectRaw('exam_id, COUNT(DISTINCT user_id) as submitted_count')
            ->groupBy('exam_id')
            ->pluck('submitted_count', 'exam_id');

        $activeStudentCount = $members->count();

        $appendStaffAttemptState = static function ($roomExams) use ($roomAttempts, $activeStudentCount) {
            return $roomExams->map(function ($assignedExam) use ($roomAttempts, $activeStudentCount) {
                $submittedCount = (int) $roomAttempts->get((int) $assignedExam->id, 0);
                $assignedExam->progress = "{$submittedCount} / {$activeStudentCount} submitted";
                return $assignedExam;
            })->values();
        };

        $assignedExams = $appendStaffAttemptState($assignedExams);
        $archivedExams = $appendStaffAttemptState($archivedExams);
    }

    $roomData = [
        'id' => $room->id,
        'name' => $room->name,
        'code' => $room->code,
        'created_by' => $room->created_by,
        'created_at' => $room->created_at,
        'updated_at' => $room->updated_at,
        'members_count' => $members->count(),
        'archived_members_count' => $archivedMembers->count(),
        'members' => $members,
        'archived_members' => $archivedMembers,
        'assigned_exams' => $assignedExams,
        'archived_exams' => $archivedExams,
    ];

    return response()->json(['room' => $roomData]);
});

Route::patch('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
    ]);

    $oldName = $room->name;

    $room->update([
        'name' => $validated['name'],
    ]);

    $room->load('creator:id,name')->loadCount(['members', 'exams']);

    $recordAudit(
        $user,
        'room.update',
        'room',
        $room->id,
        'Room updated',
        ['from' => $oldName, 'to' => $room->name],
        $request,
    );

    return response()->json([
        'message' => 'Room updated',
        'room' => $room,
    ]);
});

Route::delete('/rooms/{room}', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    // Same rule as deleting an exam: every attempt taken in a room hangs off
    // it, and deleting the room used to delete those results with it.
    if ($room->examAttempts()->exists()) {
        return response()->json([
            'message' => 'Cannot delete this room because students have already taken exams in it. Their results would be deleted with it.',
        ], 422);
    }

    $roomId = $room->id;
    $roomName = $room->name;
    $roomCode = $room->code;

    $room->delete();

    $recordAudit(
        $user,
        'room.delete',
        'room',
        $roomId,
        'Room deleted',
        ['name' => $roomName, 'code' => $roomCode],
        $request,
    );

    return response()->json(['message' => 'Room deleted']);
});

Route::post('/rooms', function (Request $request) use ($isStaffMasterExaminer, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();
    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
    ]);

    do {
        $code = Str::upper(Str::random(6));
    } while (Room::where('code', $code)->exists());

    $room = Room::create([
        'name' => $validated['name'],
        'code' => $code,
        'created_by' => $user->id,
    ]);

    $recordAudit(
        $user,
        'room.create',
        'room',
        $room->id,
        'Room created',
        ['name' => $room->name, 'code' => $room->code],
        $request,
    );

    return response()->json([
        'message' => 'Room created',
        'room' => $room,
    ], 201);
});

Route::post('/rooms/join', function (Request $request) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $validated = $request->validate([
        'code' => ['required', 'string', 'max:12'],
    ]);

    $room = Room::where('code', Str::upper(trim($validated['code'])))->first();
    if (!$room) {
        return response()->json(['message' => 'Room code not found'], 404);
    }

    $user = $request->user();

    // Rooms are year-level sections: a room's existing student roster defines
    // the level, and joiners have to match it. This guard existed before the
    // routes were split out of the monolithic api.php and was lost in that
    // move, leaving the rule enforced nowhere.
    if ($user?->role === User::ROLE_STUDENT) {
        $studentYearLevel = !is_null($user->year_level)
            ? (int) $user->year_level
            : null;

        $roomYearLevels = $room->members()
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->where('users.id', '!=', $user->id)
            ->pluck('users.year_level')
            ->filter(fn ($yearLevel) => !is_null($yearLevel))
            ->map(fn ($yearLevel) => (int) $yearLevel)
            ->unique()
            ->values();

        if ($roomYearLevels->count() > 1) {
            return response()->json([
                'message' => 'This room already mixes year levels. Clean up the roster before allowing more student joins.',
            ], 422);
        }

        if ($roomYearLevels->isNotEmpty() && (!is_int($studentYearLevel) || (int) $roomYearLevels->first() !== $studentYearLevel)) {
            return response()->json([
                'message' => 'You can only join rooms that match your year level section.',
            ], 422);
        }
    }

    $user->rooms()->syncWithoutDetaching([$room->id]);

    $recordAudit(
        $request->user(),
        'room.join',
        'room',
        $room->id,
        'Joined room',
        ['code' => $room->code],
        $request,
    );

    return response()->json([
        'message' => 'Joined room successfully',
        'room' => $room,
    ]);
});

Route::delete('/rooms/{room}/members/{member}', function (
    Request $request,
    Room $room,
    User $member
) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ($member->role !== User::ROLE_STUDENT) {
        return response()->json([
            'message' => 'Only student members can be removed from rooms.',
        ], 422);
    }

    $detached = $room->members()->detach($member->id);
    if ($detached === 0) {
        return response()->json([
            'message' => 'Student is not enrolled in this room.',
        ], 404);
    }

    $recordAudit(
        $user,
        'room.member.remove',
        'room',
        $room->id,
        'Removed student from room',
        [
            'room_id' => $room->id,
            'room_code' => $room->code,
            'student_id' => $member->id,
            'student_email' => $member->email,
        ],
        $request,
    );

    return response()->json([
        'message' => 'Student removed from room.',
    ]);
});

Route::delete('/rooms/{room}/leave', function (Request $request, Room $room) use ($canManageRooms, $ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $detached = $user->rooms()->detach($room->id);
    if ($detached === 0) {
        return response()->json(['message' => 'You are not enrolled in this room'], 404);
    }

    $recordAudit(
        $user,
        'room.leave',
        'room',
        $room->id,
        'Left room',
        ['code' => $room->code],
        $request,
    );

    return response()->json(['message' => 'Left room successfully']);
});

Route::get('/rooms/{room}/export-grades', function (
    Request $request,
    Room $room
) use (
    $canManageRooms,
    $ensureActive,
    $isAdmin,
    $recordAudit,
    $reportExportService
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $validated = $request->validate([
        'format' => ['nullable', Rule::in(['xlsx', 'csv', 'pdf'])],
    ]);

    $format = strtolower((string) ($validated['format'] ?? 'xlsx'));
    $gradebook = $reportExportService->buildRoomGradebook($room);
    $table = $reportExportService->buildRoomGradebookTable($gradebook);
    $fileLabel = Str::slug(trim((string) ($room->code ?: $room->name ?: 'room')));
    $timestamp = now()->format('Ymd_His');
    $baseFilename = ($fileLabel !== '' ? $fileLabel : ('room-' . $room->id)) . '-grades-' . $timestamp;

    $recordAudit(
        $user,
        'room.export.grades',
        'room',
        $room->id,
        'Exported room gradebook',
        [
            'format' => $format,
            'students_total' => $gradebook['summary']['students_total'] ?? 0,
            'exams_total' => $gradebook['summary']['exams_total'] ?? 0,
        ],
        $request,
    );

    if ($format === 'csv') {
        return response()->streamDownload(function () use ($table): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            fputcsv($stream, $table['headers']);

            foreach ($table['rows'] as $row) {
                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $baseFilename . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    if ($format === 'pdf') {
        return Pdf::loadView('reports.room_grades_pdf', [
            'gradebook' => $gradebook,
        ])
            ->setPaper('a4', 'landscape')
            ->download($baseFilename . '.pdf');
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Room Grades');
    $sheet->fromArray($table['headers'], null, 'A1');

    if (!empty($table['rows'])) {
        $sheet->fromArray($table['rows'], null, 'A2');
    }

    $highestColumn = $sheet->getHighestColumn();
    $sheet->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true);

    for ($columnIndex = 1; $columnIndex <= count($table['headers']); $columnIndex++) {
        $columnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex);
        $sheet->getColumnDimension($columnName)->setAutoSize(true);
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'blis_room_xlsx_');

    if ($tempFile === false) {
        return response()->json(['message' => 'Unable to generate export file.'], 500);
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    return response()->download($tempFile, $baseFilename . '.xlsx')->deleteFileAfterSend(true);
});

Route::post('/rooms/{room}/exams/{exam}/archive', function (
    Request $request,
    Room $room,
    Exam $exam
) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && ((int) $room->created_by !== (int) $user->id || (int) $exam->created_by !== (int) $user->id)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $assignment = DB::table('exam_room')
        ->where('exam_id', $exam->id)
        ->where('room_id', $room->id)
        ->first();

    if (!$assignment) {
        return response()->json(['message' => 'Exam assignment not found for this room.'], 404);
    }

    if (!is_null($assignment->archived_at)) {
        return response()->json(['message' => 'This exam is already archived in the selected room.'], 422);
    }

    $archivedAt = now();

    DB::table('exam_room')
        ->where('exam_id', $exam->id)
        ->where('room_id', $room->id)
        ->update([
            'archived_at' => $archivedAt,
            'archived_by' => $user->id,
            'updated_at' => $archivedAt,
        ]);

    $recordAudit(
        $user,
        'room.exam.archive',
        'room',
        $room->id,
        'Archived exam from room',
        [
            'room_id' => $room->id,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
        ],
        $request,
    );

    return response()->json([
        'message' => 'Exam archived from this room.',
    ]);
});

Route::post('/rooms/{room}/exams/{exam}/restore', function (
    Request $request,
    Room $room,
    Exam $exam
) use ($canManageRooms, $ensureActive, $isAdmin, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if (!$isAdmin($user) && ((int) $room->created_by !== (int) $user->id || (int) $exam->created_by !== (int) $user->id)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $assignment = DB::table('exam_room')
        ->where('exam_id', $exam->id)
        ->where('room_id', $room->id)
        ->first();

    if (!$assignment) {
        return response()->json(['message' => 'Exam assignment not found for this room.'], 404);
    }

    if (is_null($assignment->archived_at)) {
        return response()->json(['message' => 'This exam is already active in the selected room.'], 422);
    }

    $restoredAt = now();

    DB::table('exam_room')
        ->where('exam_id', $exam->id)
        ->where('room_id', $room->id)
        ->update([
            'archived_at' => null,
            'archived_by' => null,
            'updated_at' => $restoredAt,
        ]);

    $recordAudit(
        $user,
        'room.exam.restore',
        'room',
        $room->id,
        'Restored exam to room',
        [
            'room_id' => $room->id,
            'exam_id' => $exam->id,
            'exam_title' => $exam->title,
        ],
        $request,
    );

    return response()->json([
        'message' => 'Exam restored to this room.',
    ]);
});
