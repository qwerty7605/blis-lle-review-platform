<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Services\DashboardAnalyticsService;
use App\Services\ExamDeliveryModeService;
use App\Services\StudentAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class StudentController extends Controller
{
    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
        private readonly ExamDeliveryModeService $deliveryModeService,
        private readonly StudentAnalyticsService $studentAnalytics,
    ) {
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
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
            // An archived room's exams are no longer the student's to take.
            ->whereNull('rooms.archived_at')
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
                    // These become the Exam Notices. One the teacher archived
                    // from the room can no longer be started, so it is not due.
                    ->wherePivotNull('archived_at')
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
            ->map(function (Room $room) use ($attemptsByExamId) {
                $assignedExams = $room->exams
                    ->map(function (Exam $assignedExam) use ($attemptsByExamId, $room) {
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
                            'delivery_mode' => $this->deliveryModeService->normalize($assignedExam->delivery_mode),
                            'one_take_only' => (bool) $assignedExam->one_take_only,
                            'shuffle_questions' => (bool) $assignedExam->shuffle_questions,
                            'assigned_at' => $assignedExam->assigned_at,
                            'student_attempt_state' => $attemptState,
                            'student_attempt_id' => $latestAttemptId,
                            'student_submitted_at' => $submittedAttempt?->submitted_at,
                            'student_submitted_attempts' => $submittedAttemptCount,
                            'student_max_attempts' => $maxAllowedAttempts,
                            'student_attempts_remaining' => $remainingAttempts,
                            'student_can_start_attempt' => $assignedExam->studentCanStartAttempt($remainingAttempts),
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
        // One score per exam: the latest sitting, as everywhere else. A retake
        // replaces the attempt it follows rather than being averaged with it.
        $averageScorePercent = (clone $submittedAttemptsQuery)
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get(['exam_id', 'score_percent'])
            ->unique('exam_id')
            ->avg('score_percent');

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
    }

    /**
     * Summary counters plus recent attempt history, from DashboardAnalyticsService.
     * The service method backing this existed and was covered by tests but had
     * never been wired to a route, so the frontend client pointing here returned
     * 404. The richer subject/topic breakdown lives on /student/analytics below.
     */
    public function analyticsOverview(Request $request)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json([
                'message' => 'Only student accounts can access analytics.',
            ], 403);
        }

        return response()->json($this->dashboardAnalyticsService->buildStudentOverview($user));
    }

    public function analytics(Request $request)
    {
        $user = $request->user();

        if ($this->canManageRooms($user)) {
            return response()->json([
                'message' => 'Only student accounts can access analytics.',
            ], 403);
        }

        return response()->json($this->studentAnalytics->build($user));
    }
}
