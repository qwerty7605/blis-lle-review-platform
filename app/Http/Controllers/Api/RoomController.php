<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\ExamDeliveryModeService;
use App\Services\ReportExportService;
use App\Services\Reports\RoomGradebookWorkbook;
use App\Support\Reports\ReportOutput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RoomController extends Controller
{
    public function __construct(
        private readonly ExamDeliveryModeService $deliveryModeService,
        private readonly ReportExportService $reportExportService,
    ) {
    }

    public function adminIndex(Request $request)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rooms = Room::query()
            ->with('creator:id,name,email,role')
            ->withCount(['members', 'exams'])
            ->latest()
            ->get();

        return response()->json([
            'rooms' => $rooms,
        ]);

    }

    public function index(Request $request)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
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
                // ?archived=1 is the other half of the list: rooms put away with
                // their results, which can be restored.
                ->when(
                    $request->boolean('archived'),
                    fn ($query) => $query->whereNotNull('rooms.archived_at'),
                    fn ($query) => $query->whereNull('rooms.archived_at'),
                )
                ->latest();

            if (!$this->isAdmin($user)) {
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
    }

    public function show(Request $request, Room $room)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            if (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } else {
            $isMember = $user->rooms()->where('rooms.id', $room->id)->exists();
            if (!$isMember) {
                return response()->json(['message' => 'Forbidden'], 403);
            }

            if ($room->isArchived()) {
                return response()->json(['message' => 'This room has been archived by your teacher.'], 403);
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
        $loadRoomExams = function ($relation, bool $archived) {
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
                    'exams.ends_at_close',
                    'exams.delivery_mode',
                    'exams.one_take_only',
                    'exams.shuffle_questions',
                    'exam_room.created_at as assigned_at',
                    'exam_room.archived_at as room_archived_at'
                )
                ->orderByDesc($archived ? 'exam_room.archived_at' : 'exam_room.created_at')
                ->get()
                ->map(function ($assignedExam) use ($archived) {
                    $assignedExam->delivery_mode = $this->deliveryModeService->normalize($assignedExam->delivery_mode);
                    $assignedExam->question_bank_ids = $assignedExam->resolvedQuestionBankIds();
                    $assignedExam->room_assignment_archived = $archived;

                    return $assignedExam;
                })
                ->values();
        };

        $assignedExams = $loadRoomExams($room->activeExams(), false);
        $archivedExams = $loadRoomExams($room->archivedExams(), true);

        if (
            !$this->canManageRooms($user)
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
                ->select('id', 'exam_id', 'room_id', 'status', 'submitted_at', 'started_at', 'expires_at', 'score_percent')
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->get()
                ->groupBy('exam_id');

            $appendStudentAttemptState = function ($roomExams, bool $archivedAssignment) use ($attemptsByExamId, $room) {
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
                        // Resume stays open after the exam closes until this passes.
                        $assignedExam->student_attempt_expires_at = $inProgressAttempt?->expires_at;
                        $assignedExam->student_submitted_at = $submittedAttempt?->submitted_at;
                        $assignedExam->student_submitted_attempts = $submittedAttemptCount;
                        // The latest sitting's, which is the score that counts.
                        $assignedExam->student_latest_score_percent = $submittedAttempt?->score_percent;
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
            $this->canManageRooms($user)
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

            $appendStaffAttemptState = function ($roomExams) use ($roomAttempts, $activeStudentCount) {
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
            'archived_at' => $room->archived_at,
            'joins_closed_at' => $room->joins_closed_at,
        ];

        // What stands in the way of deleting or archiving the room, so its page
        // can say so before the button is pressed rather than after.
        if ($this->canManageRooms($user)) {
            $roomData['removal'] = [
                'has_results' => $room->examAttempts()->exists(),
                'pending_exams' => $room->pendingExams()->orderBy('exams.title')->pluck('exams.title')->all(),
                'attempts_in_progress' => $room->attemptsInProgress()->count(),
            ];
        }

        return response()->json(['room' => $roomData]);
    }

    public function update(Request $request, Room $room)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
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

        $this->recordAudit(
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
    }

    public function destroy(Request $request, Room $room)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Same rule as deleting an exam: every attempt taken in a room hangs off
        // it, and deleting the room used to delete those results with it.
        if ($room->examAttempts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this room because students have already taken exams in it. Their results would be deleted with it.',
            ], 422);
        }

        // And nothing may still be ahead of the class in it: an exam that is open
        // or scheduled would vanish for every student in the room.
        $pendingExams = $room->pendingExams()->orderBy('exams.title')->pluck('exams.title');

        if ($pendingExams->isNotEmpty()) {
            return response()->json([
                'message' => sprintf(
                    'Cannot delete this room while %s still open or scheduled in it: %s. Archive %s from the room first.',
                    $pendingExams->count() === 1 ? 'an exam is' : 'exams are',
                    $pendingExams->implode(', '),
                    $pendingExams->count() === 1 ? 'it' : 'them',
                ),
                'pending_exams' => $pendingExams->all(),
            ], 422);
        }

        $roomId = $room->id;
        $roomName = $room->name;
        $roomCode = $room->code;

        try {
            $room->delete();
        } catch (\Illuminate\Database\QueryException) {
            // An attempt was started between the checks above and the delete; the
            // database refuses to drop a room that has one.
            return response()->json([
                'message' => 'Cannot delete this room because a student has just started an exam in it.',
            ], 422);
        }

        $this->recordAudit(
            $user,
            'room.delete',
            'room',
            $roomId,
            'Room deleted',
            ['name' => $roomName, 'code' => $roomCode],
            $request,
        );

        return response()->json(['message' => 'Room deleted']);
    }

    /**
     * Putting a room away without losing anything in it. An archived room leaves
     * the teacher's and its students' lists, takes no new students and starts no
     * new exams; its roster, exams and every result stay, and restoring it brings
     * all of that back. This is what a room with results gets instead of Delete.
     */
    public function archive(Request $request, Room $room)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user) || (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($room->isArchived()) {
            return response()->json(['message' => 'This room is already archived.'], 422);
        }

        // The room would disappear from under them with the clock still running.
        $inProgress = $room->attemptsInProgress()->count();

        if ($inProgress > 0) {
            return response()->json([
                'message' => sprintf(
                    'Cannot archive this room while %d student%s in the middle of an exam in it. Try again once %s submitted.',
                    $inProgress,
                    $inProgress === 1 ? ' is' : 's are',
                    $inProgress === 1 ? 'they have' : 'they have all',
                ),
            ], 422);
        }

        $room->forceFill(['archived_at' => now()])->save();

        $this->recordAudit($user, 'room.archive', 'room', $room->id, 'Room archived', ['name' => $room->name, 'code' => $room->code], $request);

        return response()->json(['message' => 'Room archived. Its students, exams and results are kept.']);
    }

    public function restore(Request $request, Room $room)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user) || (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$room->isArchived()) {
            return response()->json(['message' => 'This room is not archived.'], 422);
        }

        $room->forceFill(['archived_at' => null])->save();

        $this->recordAudit($user, 'room.restore', 'room', $room->id, 'Room restored', ['name' => $room->name, 'code' => $room->code], $request);

        return response()->json(['message' => 'Room restored.']);
    }

    /**
     * "Accepting new students": closing it stops joins by code and keeps the
     * class already in the room. The code stays the same, so reopening it lets
     * the same code work again.
     */
    public function setJoining(Request $request, Room $room)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user) || (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'accepting_students' => ['required', 'boolean'],
        ]);

        $accepting = (bool) $validated['accepting_students'];

        if ($accepting === $room->isAcceptingStudents()) {
            return response()->json([
                'message' => $accepting ? 'This room is already accepting new students.' : 'This room is already closed to new students.',
                'joins_closed_at' => $room->joins_closed_at,
            ]);
        }

        $room->forceFill(['joins_closed_at' => $accepting ? null : now()])->save();

        $this->recordAudit(
            $user,
            $accepting ? 'room.joins.open' : 'room.joins.close',
            'room',
            $room->id,
            $accepting ? 'Room opened to new students' : 'Room closed to new students',
            ['name' => $room->name, 'code' => $room->code],
            $request,
        );

        return response()->json([
            'message' => $accepting ? 'Students with the code can join this room again.' : 'This room is no longer accepting new students.',
            'joins_closed_at' => $room->joins_closed_at,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->canManageRooms($user)) {
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

        $this->recordAudit(
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
    }

    public function join(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $room = Room::where('code', Str::upper(trim($validated['code'])))->first();
        if (!$room) {
            return response()->json(['message' => 'Room code not found'], 404);
        }

        if ($room->isArchived()) {
            return response()->json(['message' => 'This room has been archived and is no longer taking students.'], 422);
        }

        $user = $request->user();

        if (!$room->isAcceptingStudents() && !$room->members()->whereKey($user->id)->exists()) {
            return response()->json(['message' => 'This room is not accepting new students. Ask your teacher to open it.'], 422);
        }

        // Anyone with the code may join, whatever their year level. A rule that
        // let the first student to join set the room's level turned away the
        // rest of a mixed class (and an irregular student from the class they
        // were retaking), with nothing on the teacher's side to say why.
        $user->rooms()->syncWithoutDetaching([$room->id]);

        $this->recordAudit(
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
    }

    public function removeMember(
        Request $request,
            Room $room,
            User $member,
    )
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($member->role !== User::ROLE_STUDENT) {
            return response()->json([
                'message' => 'Only student members can be removed from rooms.',
            ], 422);
        }

        // Taking a student out while they are sitting an exam here would cut
        // them off from it with the clock still running.
        if ($room->attemptsInProgress()->where('user_id', $member->id)->exists()) {
            return response()->json([
                'message' => "{$member->name} is in the middle of an exam in this room. Remove them once they have submitted it.",
            ], 422);
        }

        $detached = $room->members()->detach($member->id);
        if ($detached === 0) {
            return response()->json([
                'message' => 'Student is not enrolled in this room.',
            ], 404);
        }

        $this->recordAudit(
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
    }

    public function leave(Request $request, Room $room)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($room->attemptsInProgress()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'You are in the middle of an exam in this room. Submit it before leaving the room.',
            ], 422);
        }

        $detached = $user->rooms()->detach($room->id);
        if ($detached === 0) {
            return response()->json(['message' => 'You are not enrolled in this room'], 404);
        }

        $this->recordAudit(
            $user,
            'room.leave',
            'room',
            $room->id,
            'Left room',
            ['code' => $room->code],
            $request,
        );

        return response()->json(['message' => 'Left room successfully']);
    }

    public function exportGrades(
        Request $request,
            Room $room,
    )
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && (int) $room->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'format' => ['nullable', Rule::in(['xlsx', 'csv', 'pdf'])],
        ]);

        $format = strtolower((string) ($validated['format'] ?? 'xlsx'));
        $gradebook = $this->reportExportService->buildRoomGradebook($room);
        $table = $this->reportExportService->buildRoomGradebookTable($gradebook);
        $filename = fn (string $extension): string => ReportOutput::filename([$room->name, 'Gradebook'], $extension);

        $this->recordAudit(
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
            return ReportOutput::csv($table['headers'], $table['rows'], $filename('csv'));
        }

        if ($format === 'pdf') {
            return ReportOutput::pdfDownload('reports.room_grades_pdf', [
                'gradebook' => $gradebook,
            ], $filename('pdf'), 'landscape');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'blis_room_xlsx_');

        if ($tempFile === false) {
            return response()->json(['message' => 'Unable to generate export file.'], 500);
        }

        $spreadsheet = app(RoomGradebookWorkbook::class)->build($gradebook);
        (new Xlsx($spreadsheet))->save($tempFile);
        $spreadsheet->disconnectWorksheets();

        return response()->download($tempFile, $filename('xlsx'))->deleteFileAfterSend(true);
    }

    public function archiveExam(
        Request $request,
            Room $room,
            Exam $exam,
    )
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && ((int) $room->created_by !== (int) $user->id || (int) $exam->created_by !== (int) $user->id)) {
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

        $this->recordAudit(
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
    }

    public function restoreExam(
        Request $request,
            Room $room,
            Exam $exam,
    )
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->isAdmin($user) && ((int) $room->created_by !== (int) $user->id || (int) $exam->created_by !== (int) $user->id)) {
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

        $this->recordAudit(
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
    }
}
