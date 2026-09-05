<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DashboardAnalyticsService
{
    private const PASSING_SCORE = 75.0;
    private const STUDENT_HISTORY_LIMIT = 6;
    private const STUDENT_ACTIVITY_LIMIT = 8;
    private const STAFF_ACTIVITY_LIMIT = 14;
    private const STAFF_SESSION_LIMIT = 8;

    /**
     * @return array<string, mixed>
     */
    public function buildStudentOverview(User $student): array
    {
        $roomsJoined = (int) $student->rooms()->count();

        $availableExamIds = DB::table('room_user')
            ->join('exam_room', 'exam_room.room_id', '=', 'room_user.room_id')
            ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
            ->where('room_user.user_id', $student->id)
            ->whereNull('exam_room.archived_at')
            ->distinct()
            ->pluck('exam_room.exam_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $attempts = ExamAttempt::query()
            ->where('user_id', $student->id)
            ->with([
                'exam:id,title,subject',
                'room:id,name,code',
            ])
            ->get()
            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
            ->values();

        $submittedAttempts = $attempts
            ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
            ->values();

        $scoreValues = $this->extractScores($submittedAttempts);
        $passingAttempts = $scoreValues
            ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
            ->count();
        $completedExams = $submittedAttempts
            ->pluck('exam_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();
        $subjects = $this->buildSubjectPerformance($submittedAttempts);
        $topicPerformance = $this->buildTopicWeaknessPerformance($student);
        $weakArea = $this->buildWeakAreaRecommendation($student);
        $latestSubmittedAttempt = $submittedAttempts->first();

        $scoreHistory = $submittedAttempts
            ->take(self::STUDENT_HISTORY_LIMIT)
            ->sortBy(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
            ->values()
            ->map(fn (ExamAttempt $attempt, int $index) => [
                'attempt_id' => (int) $attempt->id,
                'label' => (string) ($attempt->exam?->title ?: ('Attempt ' . ($index + 1))),
                'short_label' => 'A' . ($index + 1),
                'subject' => $this->normalizeSubjectLabel($attempt->exam?->subject),
                'room_name' => $attempt->room?->name,
                'score_percent' => $this->scoreValue($attempt),
                'submitted_at' => $attempt->submitted_at,
            ])
            ->all();

        $recentActivity = $attempts
            ->take(self::STUDENT_ACTIVITY_LIMIT)
            ->map(fn (ExamAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'title' => (string) ($attempt->exam?->title ?: 'Exam Attempt'),
                'subject' => $this->normalizeSubjectLabel($attempt->exam?->subject),
                'room_name' => $attempt->room?->name,
                'room_code' => $attempt->room?->code,
                'status' => $attempt->status,
                'score_percent' => $this->scoreValue($attempt),
                'occurred_at' => $attempt->status === ExamAttempt::STATUS_SUBMITTED
                    ? ($attempt->submitted_at ?? $attempt->started_at ?? $attempt->created_at)
                    : ($attempt->started_at ?? $attempt->created_at),
            ])
            ->values()
            ->all();

        return [
            'summary' => [
                'rooms_joined' => $roomsJoined,
                'available_exams' => (int) $availableExamIds->count(),
                'pending_exams' => max(0, (int) $availableExamIds->count() - $completedExams),
                'attempts_started' => (int) $attempts->count(),
                'attempts_submitted' => (int) $submittedAttempts->count(),
                'completed_exams' => $completedExams,
                'in_progress_attempts' => (int) $attempts
                    ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
                    ->count(),
                'passing_attempts' => (int) $passingAttempts,
                'failing_attempts' => max(0, (int) $submittedAttempts->count() - (int) $passingAttempts),
                'passing_threshold' => self::PASSING_SCORE,
                'average_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->avg(), 2)
                    : null,
                'pass_rate_percent' => $submittedAttempts->isNotEmpty()
                    ? round(($passingAttempts / $submittedAttempts->count()) * 100, 2)
                    : null,
                'best_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->max(), 2)
                    : null,
                'latest_score_percent' => $latestSubmittedAttempt
                    ? $this->scoreValue($latestSubmittedAttempt)
                    : null,
            ],
            'subjects' => $subjects,
            'topic_performance' => $topicPerformance,
            'focus_subjects' => collect($subjects)
                ->filter(fn (array $subject) => (float) ($subject['score'] ?? 0) < self::PASSING_SCORE)
                ->values()
                ->all(),
            'weak_area' => $weakArea,
            'score_history' => $scoreHistory,
            'recent_activity' => $recentActivity,
            'generated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStaffReportOverview(User $staff): array
    {
        $managedRooms = Room::query()
            ->where('created_by', $staff->id)
            ->get(['id', 'name', 'code']);
        $managedExams = Exam::query()
            ->where('created_by', $staff->id)
            ->get(['id', 'title', 'subject']);

        $roomIds = $managedRooms->pluck('id')->map(fn ($id) => (int) $id)->values();
        $examIds = $managedExams->pluck('id')->map(fn ($id) => (int) $id)->values();

        $studentsEnrolled = DB::table('room_user')
            ->join('rooms', 'rooms.id', '=', 'room_user.room_id')
            ->join('users', 'users.id', '=', 'room_user.user_id')
            ->where('rooms.created_by', $staff->id)
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->distinct('room_user.user_id')
            ->count('room_user.user_id');

        $studentCountsByRoomId = $roomIds->isNotEmpty()
            ? DB::table('room_user')
                ->join('users', 'users.id', '=', 'room_user.user_id')
                ->whereIn('room_user.room_id', $roomIds->all())
                ->where('users.role', User::ROLE_STUDENT)
                ->whereNull('users.archived_at')
                ->groupBy('room_user.room_id')
                ->selectRaw('room_user.room_id, COUNT(DISTINCT room_user.user_id) as students_count')
                ->pluck('students_count', 'room_user.room_id')
            : collect();

        $currentStudentIds = $roomIds->isNotEmpty()
            ? DB::table('room_user')
                ->join('users', 'users.id', '=', 'room_user.user_id')
                ->whereIn('room_user.room_id', $roomIds->all())
                ->where('users.role', User::ROLE_STUDENT)
                ->whereNull('users.archived_at')
                ->distinct()
                ->pluck('room_user.user_id')
                ->map(fn ($id) => (int) $id)
                ->values()
            : collect();

        $sessionRows = ($examIds->isNotEmpty() && $roomIds->isNotEmpty())
            ? DB::table('exam_room')
                ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
                ->join('rooms', 'rooms.id', '=', 'exam_room.room_id')
                ->where('exams.created_by', $staff->id)
                ->where('rooms.created_by', $staff->id)
                ->whereNull('exam_room.archived_at')
                ->select(
                    'exam_room.exam_id',
                    'exam_room.room_id',
                    'exam_room.created_at as assigned_at',
                    'exams.title as exam_title',
                    'exams.subject as exam_subject',
                    'rooms.name as room_name',
                    'rooms.code as room_code',
                )
                ->orderByDesc('exam_room.created_at')
                ->get()
            : collect();

        $attemptIdsQuery = DB::table('exam_attempts')
            ->whereIn('exam_id', $examIds->all())
            ->whereIn('room_id', $roomIds->all())
            ->whereIn('user_id', $currentStudentIds->all())
            ->groupBy('exam_id', 'room_id', 'user_id')
            ->selectRaw('MAX(id) as id');

        $attempts = ($examIds->isNotEmpty() && $roomIds->isNotEmpty() && $currentStudentIds->isNotEmpty())
            ? ExamAttempt::query()
                ->joinSub($attemptIdsQuery, 'latest_ids', function ($join) {
                    $join->on('exam_attempts.id', '=', 'latest_ids.id');
                })
                ->with([
                    'exam:id,title,subject',
                    'room:id,name,code',
                ])
                ->get()
            : collect();

        $activeSessionKeys = collect($sessionRows)
            ->map(fn ($session) => $this->sessionKey((int) $session->exam_id, (int) $session->room_id))
            ->values();

        $latestAttemptsBySession = collect($attempts)
            ->groupBy(fn (ExamAttempt $attempt) => $this->sessionKey((int) $attempt->exam_id, (int) $attempt->room_id))
            ->map(function (Collection $sessionAttempts): Collection {
                return $sessionAttempts
                    ->groupBy('user_id')
                    ->map(function (Collection $studentAttempts): ?ExamAttempt {
                        return $studentAttempts
                            ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptActivityTimestamp($attempt))
                            ->first();
                    })
                    ->filter()
                    ->values();
            });

        $latestSessionAttempts = $latestAttemptsBySession
            ->only($activeSessionKeys->all())
            ->flatten(1)
            ->values();
        $submittedLatestAttempts = $latestSessionAttempts
            ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
            ->values();

        $submittedScoreValues = $this->extractScores($submittedLatestAttempts);
        $passingLatestAttempts = $submittedScoreValues
            ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
            ->count();
        $sessionEnrollments = (int) $sessionRows
            ->sum(fn ($session) => (int) ($studentCountsByRoomId[(int) $session->room_id] ?? 0));

        $sessionPerformance = collect($sessionRows)
            ->map(function ($session) use ($latestAttemptsBySession, $studentCountsByRoomId): array {
                $sessionAttempts = $latestAttemptsBySession->get(
                    $this->sessionKey((int) $session->exam_id, (int) $session->room_id),
                    collect(),
                );
                $submittedAttempts = $sessionAttempts
                    ->filter(fn (ExamAttempt $attempt) => $attempt->status === ExamAttempt::STATUS_SUBMITTED)
                    ->values();
                $submittedScores = $this->extractScores($submittedAttempts);
                $studentsTotal = (int) ($studentCountsByRoomId[(int) $session->room_id] ?? 0);
                $studentsStarted = (int) $sessionAttempts->count();
                $studentsSubmitted = (int) $submittedAttempts->count();
                $passingCount = $submittedScores
                    ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
                    ->count();
                $latestSubmission = $submittedAttempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->first();

                return [
                    'exam_id' => (int) $session->exam_id,
                    'room_id' => (int) $session->room_id,
                    'exam_title' => (string) $session->exam_title,
                    'subject' => $this->normalizeSubjectLabel($session->exam_subject),
                    'room_name' => (string) $session->room_name,
                    'room_code' => $session->room_code,
                    'students_total' => $studentsTotal,
                    'students_started' => $studentsStarted,
                    'students_submitted' => $studentsSubmitted,
                    'start_rate_percent' => $studentsTotal > 0
                        ? round(($studentsStarted / $studentsTotal) * 100, 2)
                        : null,
                    'completion_rate_percent' => $studentsTotal > 0
                        ? round(($studentsSubmitted / $studentsTotal) * 100, 2)
                        : null,
                    'average_score_percent' => $submittedScores->isNotEmpty()
                        ? round((float) $submittedScores->avg(), 2)
                        : null,
                    'pass_rate_percent' => $submittedAttempts->isNotEmpty()
                        ? round(($passingCount / $submittedAttempts->count()) * 100, 2)
                        : null,
                    'latest_submission_at' => $latestSubmission?->submitted_at,
                    'assigned_at' => $session->assigned_at,
                ];
            })
            ->sortByDesc(fn (array $session) => $this->dateValueTimestamp($session['latest_submission_at'] ?? $session['assigned_at'] ?? null))
            ->take(self::STAFF_SESSION_LIMIT)
            ->values()
            ->all();

        return [
            'metrics' => [
                'managed_rooms' => (int) $managedRooms->count(),
                'managed_exams' => (int) $managedExams->count(),
                'students_enrolled' => (int) $studentsEnrolled,
                'exam_assignments' => (int) collect($sessionRows)->count(),
                'session_enrollments' => $sessionEnrollments,
                'attempts_started' => (int) $latestSessionAttempts->count(),
                'attempts_submitted' => (int) $submittedLatestAttempts->count(),
                'completion_rate_percent' => $sessionEnrollments > 0
                    ? round(($submittedLatestAttempts->count() / $sessionEnrollments) * 100, 2)
                    : null,
                'average_score_percent' => $submittedScoreValues->isNotEmpty()
                    ? round((float) $submittedScoreValues->avg(), 2)
                    : null,
                'pass_rate_percent' => $submittedLatestAttempts->isNotEmpty()
                    ? round(($passingLatestAttempts / $submittedLatestAttempts->count()) * 100, 2)
                    : null,
            ],
            'subject_performance' => $this->buildSubjectPerformance($submittedLatestAttempts),
            'session_performance' => $sessionPerformance,
            'recent_activity' => AuditLog::query()
                ->with('actor:id,name,role')
                ->where('actor_id', $staff->id)
                ->latest()
                ->limit(self::STAFF_ACTIVITY_LIMIT)
                ->get(),
            'generated_at' => now(),
        ];
    }

    /**
     * Sort keys the staff directory table exposes, mapped to the SQL that backs
     * each. This doubles as the whitelist: ORDER BY cannot be parameterised, so
     * an unrecognised key falls back to `name` rather than being interpolated.
     *
     * @var array<string, string>
     */
    private const DIRECTORY_SORTS = [
        'name' => 'users.name',
        'student_id' => 'users.student_id',
        'year_level' => 'users.year_level',
        'email' => 'users.email',
        'room_count' => 'current_rooms_count',
        'attempts_submitted' => 'stats.attempts_submitted',
        'average_score_percent' => 'stats.average_score_percent',
        'pass_rate_percent' => 'stats.pass_rate_percent',
        'latest_score_percent' => 'latest.latest_score_percent',
        'last_activity_at' => 'stats.last_activity_at',
    ];

    /** Sort keys where an empty string counts as blank, not just NULL. */
    private const DIRECTORY_TEXT_SORTS = ['name', 'student_id', 'email'];

    /**
     * Sort keys backed by a plain `users` column, which is what makes the
     * two-phase path in paginateStudentDirectory() possible: the page can be
     * chosen off an index before any attempt aggregation happens. Everything
     * outside this list is a derived value that has to exist before rows can
     * be ordered, so those sorts pay for a full rollup.
     */
    private const DIRECTORY_USER_SORTS = ['name', 'student_id', 'year_level', 'email'];

    private const DIRECTORY_PAGE_SIZE = 30;
    private const DIRECTORY_MAX_PAGE_SIZE = 100;

    /**
     * A tripwire, not a cap. The enrolled population is bounded by the length
     * of the programme -- four year levels in, one batch out a year -- so
     * crossing this means lifecycle statuses have stopped being maintained
     * (a graduating batch never archived, say) rather than that the school
     * suddenly grew. Surfaced in the response and logged so it is noticed
     * instead of the page quietly getting slower every term.
     */
    private const DIRECTORY_EXPECTED_MAX = 3000;

    private const DIRECTORY_SUMMARY_CACHE_KEY = 'student_directory_summary';
    private const DIRECTORY_SUMMARY_CACHE_SECONDS = 60;

    /**
     * Paginated staff student directory.
     *
     * Filtering, sorting and paging all resolve in SQL, and every per-student
     * aggregate arrives as a column off a GROUP BY instead of being folded in
     * PHP. The previous implementation loaded every student (archived included)
     * and every one of their attempts as Eloquent models with `exam` and `room`
     * hydrated, purely to derive five numbers a row: measured at 3.0s / 70MB
     * for 1.2k students, and a fatal memory exhaustion at 4.2k. See
     * LoadTestDirectorySeeder for the harness those numbers came from.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginateStudentDirectory(array $filters = []): array
    {
        $sortKey = (string) ($filters['sort'] ?? 'name');
        if (!array_key_exists($sortKey, self::DIRECTORY_SORTS)) {
            $sortKey = 'name';
        }

        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = min(
            max((int) ($filters['per_page'] ?? self::DIRECTORY_PAGE_SIZE), 1),
            self::DIRECTORY_MAX_PAGE_SIZE,
        );

        // Counting on `users` alone is only safe because every filter targets a
        // users column -- none of them touch the joined aggregates. Running the
        // count through the joins instead would materialise the whole rollup
        // just to produce a number.
        $total = (int) $this->applyDirectoryFilters(User::query(), $filters)->count();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max((int) ($filters['page'] ?? 1), 1), $lastPage);

        $column = self::DIRECTORY_SORTS[$sortKey];
        $blank = in_array($sortKey, self::DIRECTORY_TEXT_SORTS, true)
            ? "({$column} IS NULL OR {$column} = '')"
            : "({$column} IS NULL)";

        // Two-phase where we can. Sorting by a users column means the page can
        // be chosen off an index first, so the aggregates only ever roll up the
        // 30 students actually being shown -- measured at 856ms -> 15ms on 4.2k
        // students. Sorting by a derived column (average score, say) has no
        // such shortcut: the value must exist before rows can be ordered, so
        // that path still aggregates everyone and pays for it.
        $pageIds = null;

        if (in_array($sortKey, self::DIRECTORY_USER_SORTS, true)) {
            $pageIds = $this->applyDirectoryFilters(User::query(), $filters)
                ->orderByRaw($blank . ' asc')
                ->orderByRaw($column . ' ' . $direction)
                ->orderBy('users.name')
                ->orderBy('users.id')
                ->forPage($page, $perPage)
                ->pluck('users.id')
                ->all();

            if ($pageIds === []) {
                return $this->emptyDirectoryPage($page, $perPage, $total, $sortKey, $direction);
            }
        }

        $query = $this->applyDirectoryFilters(User::query(), $filters)
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.student_id',
                'users.year_level',
                'users.is_active',
                'users.archived_at',
            ])
            ->leftJoinSub($this->attemptStatsSubquery($pageIds), 'stats', 'stats.user_id', '=', 'users.id')
            ->leftJoinSub($this->latestSubmittedSubquery($pageIds), 'latest', 'latest.user_id', '=', 'users.id')
            ->leftJoinSub($this->strongestSubjectSubquery($pageIds), 'best', 'best.user_id', '=', 'users.id')
            ->when($pageIds !== null, fn ($q) => $q->whereIn('users.id', $pageIds))
            ->withCount('currentRooms')
            ->addSelect([
                'stats.attempts_started',
                'stats.attempts_submitted',
                'stats.average_score_percent',
                'stats.pass_rate_percent',
                'stats.last_activity_at',
                'latest.latest_score_percent',
                'latest.latest_exam_title',
                'best.strongest_subject',
            ])
            // Blanks sink to the bottom in BOTH directions, matching what the
            // table did client-side (compareEmptiness in useStudentsModule.js
            // returns before the direction factor is applied). Left to itself
            // MySQL would surface NULLs first on an ascending sort.
            ->orderByRaw($blank . ' asc')
            ->orderByRaw($column . ' ' . $direction)
            ->orderBy('users.name')
            ->orderBy('users.id');

        if ($pageIds === null) {
            $query->forPage($page, $perPage);
        }

        $students = $query->get();
        $students->load(['currentRooms:id,name']);

        if ($total > self::DIRECTORY_EXPECTED_MAX) {
            Log::warning('Student directory population exceeds the expected maximum.', [
                'total' => $total,
                'expected_max' => self::DIRECTORY_EXPECTED_MAX,
            ]);
        }

        return [
            'students' => $students
                ->map(fn (User $student): array => $this->directoryRecord($student))
                ->all(),
            'summary' => $this->directorySummary(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
                'sort' => $sortKey,
                'direction' => $direction,
                'exceeds_expected_population' => $total > self::DIRECTORY_EXPECTED_MAX,
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * A page past the end of the result set, or a filter that matched nothing.
     * Returned without touching exam_attempts at all.
     *
     * @return array<string, mixed>
     */
    private function emptyDirectoryPage(
        int $page,
        int $perPage,
        int $total,
        string $sortKey,
        string $direction,
    ): array {
        return [
            'students' => [],
            'summary' => $this->directorySummary(),
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'from' => 0,
                'to' => 0,
                'sort' => $sortKey,
                'direction' => $direction,
                'exceeds_expected_population' => $total > self::DIRECTORY_EXPECTED_MAX,
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * Directory filters. All of them target `users` columns, which is what lets
     * the row count skip the aggregate joins entirely.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $query
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function applyDirectoryFilters($query, array $filters)
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $archiveState = (string) ($filters['archive_state'] ?? 'current');

        return $query
            ->where('users.role', User::ROLE_STUDENT)
            ->when($search !== '', fn ($outer) => $outer->where(function ($inner) use ($search) {
                $inner->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('users.student_id', 'like', "%{$search}%");
            }))
            ->when(
                filled($filters['year_level'] ?? null),
                fn ($q) => $q->where('users.year_level', (int) $filters['year_level']),
            )
            // Defaulting to `current` is the change that bounds this endpoint:
            // graduates and their whole attempt history used to be loaded on
            // every page view even though the table filtered them out in JS.
            ->when($archiveState === 'current', fn ($q) => $q->whereNull('users.archived_at'))
            ->when($archiveState === 'archived', fn ($q) => $q->whereNotNull('users.archived_at'));
    }

    /**
     * One row per (user, exam, room): the student's most recent attempt at that
     * exam in that room. MAX(id) stands in for "most recent" because attempt
     * ids are monotonic, which is what the old PHP path effectively relied on
     * too -- it re-sorted each group by activity timestamp, but the group only
     * ever held a single row.
     */
    private function latestSessionAttemptIds(?array $userIds = null): \Illuminate\Database\Query\Builder
    {
        return DB::table('exam_attempts')
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', $userIds))
            ->groupBy('user_id', 'exam_id', 'room_id')
            ->selectRaw('MAX(id) as id');
    }

    /**
     * Per-student rollup over the deduplicated session attempts.
     *
     * `pass_rate_percent` counts every submitted attempt in the denominator but
     * only non-null scores at or above the threshold in the numerator, which is
     * what the PHP it replaces did: NULL >= 75 yields NULL and SUM skips it.
     */
    private function attemptStatsSubquery(?array $userIds = null): \Illuminate\Database\Query\Builder
    {
        $passing = self::PASSING_SCORE;

        return DB::table('exam_attempts as ea')
            ->joinSub($this->latestSessionAttemptIds($userIds), 'latest_ids', 'ea.id', '=', 'latest_ids.id')
            ->when($userIds !== null, fn ($q) => $q->whereIn('ea.user_id', $userIds))
            ->groupBy('ea.user_id')
            ->selectRaw("
                ea.user_id,
                COUNT(*) as attempts_started,
                SUM(ea.status = 'submitted') as attempts_submitted,
                AVG(CASE WHEN ea.status = 'submitted' THEN ea.score_percent END) as average_score_percent,
                SUM(ea.status = 'submitted' AND ea.score_percent >= {$passing})
                    / NULLIF(SUM(ea.status = 'submitted'), 0) * 100 as pass_rate_percent,
                MAX(COALESCE(ea.submitted_at, ea.started_at, ea.created_at)) as last_activity_at
            ");
    }

    /** The most recently submitted attempt per student, and the exam behind it. */
    private function latestSubmittedSubquery(?array $userIds = null): \Illuminate\Database\Query\Builder
    {
        $ranked = DB::table('exam_attempts as ea')
            ->joinSub($this->latestSessionAttemptIds($userIds), 'latest_ids', 'ea.id', '=', 'latest_ids.id')
            ->leftJoin('exams as e', 'e.id', '=', 'ea.exam_id')
            ->when($userIds !== null, fn ($q) => $q->whereIn('ea.user_id', $userIds))
            ->where('ea.status', 'submitted')
            ->selectRaw('
                ea.user_id,
                ea.score_percent,
                e.title as exam_title,
                ROW_NUMBER() OVER (
                    PARTITION BY ea.user_id
                    ORDER BY ea.submitted_at DESC, ea.id DESC
                ) as rn
            ');

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('ranked.rn', 1)
            ->selectRaw('
                ranked.user_id,
                ranked.score_percent as latest_score_percent,
                ranked.exam_title as latest_exam_title
            ');
    }

    /**
     * The subject a student averages highest in -- the same "sort subjects by
     * average, take the first" the Analytics page uses, expressed as a window
     * function so it costs one pass instead of a per-student PHP grouping.
     * Blank subjects collapse to 'Uncategorized' exactly as
     * normalizeSubjectLabel() does.
     */
    private function strongestSubjectSubquery(?array $userIds = null): \Illuminate\Database\Query\Builder
    {
        $ranked = DB::table('exam_attempts as ea')
            ->joinSub($this->latestSessionAttemptIds($userIds), 'latest_ids', 'ea.id', '=', 'latest_ids.id')
            ->leftJoin('exams as e', 'e.id', '=', 'ea.exam_id')
            ->when($userIds !== null, fn ($q) => $q->whereIn('ea.user_id', $userIds))
            ->where('ea.status', 'submitted')
            ->groupBy('ea.user_id', DB::raw("COALESCE(NULLIF(TRIM(e.subject), ''), 'Uncategorized')"))
            ->selectRaw("
                ea.user_id,
                COALESCE(NULLIF(TRIM(e.subject), ''), 'Uncategorized') as subject,
                ROW_NUMBER() OVER (
                    PARTITION BY ea.user_id
                    ORDER BY AVG(ea.score_percent) DESC, COALESCE(NULLIF(TRIM(e.subject), ''), 'Uncategorized') ASC
                ) as rn
            ");

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('ranked.rn', 1)
            ->selectRaw('ranked.user_id, ranked.subject as strongest_subject');
    }

    /**
     * Headline counts for the summary cards.
     *
     * The two averages stay a mean of per-student means rather than a mean of
     * every attempt, because that is what the cards have always displayed
     * ("Across students with graded attempts") -- an attempt-weighted mean
     * would let a prolific student outweigh a quiet one and silently change
     * numbers staff have been reading.
     *
     * @return array<string, mixed>
     */
    private function directorySummary(): array
    {
        // Unlike the row query, this cannot be narrowed to the current page --
        // it is a rollup over everyone by definition, measured at ~200ms on
        // 4.2k students. Cached briefly so it is paid once a minute rather
        // than once a keystroke; the trade is that the cards can lag a newly
        // archived student by up to the TTL.
        return Cache::remember(
            self::DIRECTORY_SUMMARY_CACHE_KEY,
            self::DIRECTORY_SUMMARY_CACHE_SECONDS,
            fn (): array => $this->computeDirectorySummary(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function computeDirectorySummary(): array
    {
        $counts = DB::table('users')
            ->where('role', User::ROLE_STUDENT)
            ->selectRaw('
                SUM(archived_at IS NULL) as current_students,
                SUM(archived_at IS NOT NULL) as archived_students
            ')
            ->first();

        $aggregate = DB::query()
            ->fromSub($this->attemptStatsSubquery(), 'stats')
            ->join('users', 'users.id', '=', 'stats.user_id')
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->selectRaw('
                SUM(stats.attempts_submitted > 0) as students_with_results,
                AVG(stats.average_score_percent) as average_score_percent,
                AVG(stats.pass_rate_percent) as average_pass_rate_percent
            ')
            ->first();

        return [
            'current_students' => (int) ($counts->current_students ?? 0),
            'archived_students' => (int) ($counts->archived_students ?? 0),
            'students_with_results' => (int) ($aggregate->students_with_results ?? 0),
            'average_score_percent' => $this->roundOrNull($aggregate->average_score_percent ?? null),
            'average_pass_rate_percent' => $this->roundOrNull($aggregate->average_pass_rate_percent ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function directoryRecord(User $student): array
    {
        $roomNames = $student->currentRooms
            ->sortBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return [
            'id' => (int) $student->id,
            'name' => (string) $student->name,
            'email' => (string) $student->email,
            'student_id' => $student->student_id,
            'year_level' => $student->year_level,
            'is_active' => (bool) $student->is_active,
            'archived_at' => $student->archived_at,
            'room_count' => (int) ($student->current_rooms_count ?? 0),
            'room_names' => $roomNames,
            'attempts_started' => (int) ($student->attempts_started ?? 0),
            'attempts_submitted' => (int) ($student->attempts_submitted ?? 0),
            'average_score_percent' => $this->roundOrNull($student->average_score_percent),
            'pass_rate_percent' => $this->roundOrNull($student->pass_rate_percent),
            'latest_score_percent' => $this->roundOrNull($student->latest_score_percent),
            'latest_exam_title' => $student->latest_exam_title,
            'last_activity_at' => $student->last_activity_at,
            'strongest_subject' => $student->strongest_subject,
        ];
    }

    private function roundOrNull(mixed $value): ?float
    {
        return is_null($value) ? null : round((float) $value, 2);
    }

    /**
     * @param  Collection<int, ExamAttempt>  $submittedAttempts
     * @return array<int, array<string, mixed>>
     */
    private function buildSubjectPerformance(Collection $submittedAttempts): array
    {
        return $submittedAttempts
            ->groupBy(fn (ExamAttempt $attempt) => $this->normalizeSubjectLabel($attempt->exam?->subject))
            ->map(function (Collection $attempts, string $label): array {
                $sortedAttempts = $attempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->values();
                $scores = $this->extractScores($sortedAttempts);
                $latestAttempt = $sortedAttempts->first();
                $passingCount = $scores
                    ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
                    ->count();

                return [
                    'label' => $label,
                    'score' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 2)
                        : null,
                    'average_score_percent' => $scores->isNotEmpty()
                        ? round((float) $scores->avg(), 2)
                        : null,
                    'latest_score_percent' => $latestAttempt
                        ? $this->scoreValue($latestAttempt)
                        : null,
                    'attempts_submitted' => (int) $sortedAttempts->count(),
                    'pass_rate_percent' => $sortedAttempts->isNotEmpty()
                        ? round(($passingCount / $sortedAttempts->count()) * 100, 2)
                        : null,
                    'last_submitted_at' => $latestAttempt?->submitted_at,
                ];
            })
            ->sortByDesc(fn (array $subject) => (float) ($subject['average_score_percent'] ?? -1))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return Collection<int, float>
     */
    private function extractScores(Collection $attempts): Collection
    {
        return $attempts
            ->map(fn (ExamAttempt $attempt) => $this->scoreValue($attempt))
            ->filter(fn ($score) => !is_null($score))
            ->values();
    }

    private function scoreValue(ExamAttempt $attempt): ?float
    {
        return is_null($attempt->score_percent)
            ? null
            : round((float) $attempt->score_percent, 2);
    }

    private function normalizeSubjectLabel(?string $subject): string
    {
        $label = trim((string) ($subject ?? ''));

        return $label !== '' ? $label : 'Uncategorized';
    }

    private function sessionKey(int $examId, int $roomId): string
    {
        return $examId . ':' . $roomId;
    }

    private function attemptActivityTimestamp(ExamAttempt $attempt): int
    {
        return $attempt->submitted_at?->getTimestamp()
            ?? $attempt->started_at?->getTimestamp()
            ?? $attempt->created_at?->getTimestamp()
            ?? 0;
    }

    private function attemptSubmissionTimestamp(ExamAttempt $attempt): int
    {
        return $attempt->submitted_at?->getTimestamp()
            ?? $attempt->started_at?->getTimestamp()
            ?? $attempt->created_at?->getTimestamp()
            ?? 0;
    }

    private function dateValueTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        $timestamp = strtotime((string) ($value ?? ''));

        return $timestamp === false ? 0 : $timestamp;
    }

    public function buildItemDifficulty(Exam $exam): array
    {
        $attemptIds = ExamAttempt::where('exam_id', $exam->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->pluck('id');

        if ($attemptIds->isEmpty()) {
            return [];
        }

        $stats = DB::table('exam_attempt_answers')
            ->whereIn('exam_attempt_id', $attemptIds)
            ->select('question_bank_question_id', DB::raw('count(*) as total_attempts'), DB::raw('sum(is_correct) as correct_answers'))
            ->groupBy('question_bank_question_id')
            ->get();

        $questionIds = $stats->pluck('question_bank_question_id');
        $questions = DB::table('question_bank_questions')
            ->whereIn('id', $questionIds)
            ->get(['id', 'question_text', 'item_number', 'question_bank_id']);

        $results = [];
        foreach ($stats as $stat) {
            $question = $questions->firstWhere('id', $stat->question_bank_question_id);
            $total = (int) $stat->total_attempts;
            $correct = (int) $stat->correct_answers;
            $successRate = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
            $failureRate = 100 - $successRate;

            $results[] = [
                'question_id' => $stat->question_bank_question_id,
                'question_text' => $question ? $question->question_text : 'Unknown Question',
                'total_attempts' => $total,
                'correct_answers' => $correct,
                'success_rate_percent' => $successRate,
                'failure_rate_percent' => $failureRate,
            ];
        }

        usort($results, fn($a, $b) => $b['failure_rate_percent'] <=> $a['failure_rate_percent']);

        return $results;
    }

    /**
     * @return array<string, mixed>|array{}
     */
    public function buildWeakAreaRecommendation(User $student): array
    {
        $subjectPerformance = $this->buildSubjectWeaknessPerformance($student);
        $topicPerformance = $this->buildTopicWeaknessPerformance($student);
        $subtopicPerformance = $this->buildSubtopicWeaknessPerformance($student);

        $weakSubject = collect($subjectPerformance)
            ->sortBy(fn (array $subject): float => (float) ($subject['score'] ?? 100.0))
            ->first();

        $weakTopic = collect($topicPerformance)
            ->sortBy(fn (array $topic): float => (float) ($topic['score'] ?? 100.0))
            ->first();

        $weakSubtopic = collect($subtopicPerformance)
            ->sortBy(fn (array $subtopic): float => (float) ($subtopic['score'] ?? 100.0))
            ->first();

        if (is_array($weakSubtopic)) {
            return [
                'basis' => 'subtopic',
                'subject' => (string) ($weakSubtopic['subject'] ?? ''),
                'subject_score' => $this->findSubjectScore($subjectPerformance, (string) ($weakSubtopic['subject'] ?? '')),
                'topic_id' => (int) ($weakSubtopic['topic_id'] ?? 0),
                'topic' => (string) ($weakSubtopic['topic'] ?? ''),
                'topic_score' => null,
                'subtopic_id' => (int) ($weakSubtopic['subtopic_id'] ?? 0),
                'subtopic' => (string) ($weakSubtopic['label'] ?? ''),
                'subtopic_score' => isset($weakSubtopic['score']) ? (float) $weakSubtopic['score'] : null,
                'fallback_subject' => is_array($weakSubject) ? (string) ($weakSubject['label'] ?? '') : null,
            ];
        }

        if (is_array($weakTopic)) {
            return [
                'basis' => 'topic',
                'subject' => (string) ($weakTopic['subject'] ?? ''),
                'subject_score' => $this->findSubjectScore($subjectPerformance, (string) ($weakTopic['subject'] ?? '')),
                'topic_id' => (int) ($weakTopic['topic_id'] ?? 0),
                'topic' => (string) ($weakTopic['label'] ?? ''),
                'topic_score' => isset($weakTopic['score']) ? (float) $weakTopic['score'] : null,
                'subtopic_id' => null,
                'subtopic' => null,
                'subtopic_score' => null,
                'fallback_subject' => is_array($weakSubject) ? (string) ($weakSubject['label'] ?? '') : null,
            ];
        }

        if (is_array($weakSubject)) {
            return [
                'basis' => 'subject',
                'subject' => (string) ($weakSubject['label'] ?? ''),
                'subject_score' => isset($weakSubject['score']) ? (float) $weakSubject['score'] : null,
                'topic_id' => null,
                'topic' => null,
                'topic_score' => null,
                'subtopic_id' => null,
                'subtopic' => null,
                'subtopic_score' => null,
                'fallback_subject' => null,
            ];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSubjectWeaknessPerformance(User $student): array
    {
        $rows = DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->where('exam_attempts.user_id', $student->id)
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->selectRaw("
                COALESCE(NULLIF(question_bank_questions.subject, ''), 'Uncategorized') as label,
                COUNT(*) as attempted_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                MAX(exam_attempts.submitted_at) as last_submitted_at
            ")
            ->groupBy('label')
            ->get();

        return collect($rows)
            ->map(function ($row): array {
                $attemptedCount = (int) ($row->attempted_count ?? 0);
                $correctCount = (int) ($row->correct_count ?? 0);

                return [
                    'label' => (string) ($row->label ?? 'Uncategorized'),
                    'attempted_count' => $attemptedCount,
                    'correct_count' => $correctCount,
                    'score' => $attemptedCount > 0
                        ? round(($correctCount / $attemptedCount) * 100, 2)
                        : null,
                    'last_submitted_at' => $row->last_submitted_at,
                ];
            })
            ->sortBy(fn (array $subject): string => Str::lower((string) $subject['label']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildTopicWeaknessPerformance(User $student): array
    {
        $rows = DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->join('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->where('exam_attempts.user_id', $student->id)
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->selectRaw("
                topics.id as topic_id,
                topics.name as label,
                COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject, 'Uncategorized') as subject,
                COUNT(*) as attempted_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                MAX(exam_attempts.submitted_at) as last_submitted_at
            ")
            ->groupBy('topics.id', 'topics.name', 'topics.subject', 'question_bank_questions.subject')
            ->get();

        return collect($rows)
            ->map(function ($row): array {
                $attemptedCount = (int) ($row->attempted_count ?? 0);
                $correctCount = (int) ($row->correct_count ?? 0);

                return [
                    'topic_id' => (int) ($row->topic_id ?? 0),
                    'label' => (string) ($row->label ?? 'Untitled Topic'),
                    'subject' => (string) ($row->subject ?? 'Uncategorized'),
                    'attempted_count' => $attemptedCount,
                    'correct_count' => $correctCount,
                    'score' => $attemptedCount > 0
                        ? round(($correctCount / $attemptedCount) * 100, 2)
                        : null,
                    'last_submitted_at' => $row->last_submitted_at,
                ];
            })
            ->sortBy(fn (array $topic): string => Str::lower((string) $topic['label']))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSubtopicWeaknessPerformance(User $student): array
    {
        $rows = DB::table('exam_attempt_answers')
            ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_answers.exam_attempt_id')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->join('subtopics', 'subtopics.id', '=', 'question_bank_questions.subtopic_id')
            ->join('topics', 'topics.id', '=', 'subtopics.topic_id')
            ->where('exam_attempts.user_id', $student->id)
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->selectRaw("
                subtopics.id as subtopic_id,
                subtopics.name as label,
                topics.id as topic_id,
                topics.name as topic,
                COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject, 'Uncategorized') as subject,
                COUNT(*) as attempted_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
                MAX(exam_attempts.submitted_at) as last_submitted_at
            ")
            ->groupBy('subtopics.id', 'subtopics.name', 'topics.id', 'topics.name', 'topics.subject', 'question_bank_questions.subject')
            ->get();

        return collect($rows)
            ->map(function ($row): array {
                $attemptedCount = (int) ($row->attempted_count ?? 0);
                $correctCount = (int) ($row->correct_count ?? 0);

                return [
                    'subtopic_id' => (int) ($row->subtopic_id ?? 0),
                    'label' => (string) ($row->label ?? 'Untitled Subtopic'),
                    'topic_id' => (int) ($row->topic_id ?? 0),
                    'topic' => (string) ($row->topic ?? ''),
                    'subject' => (string) ($row->subject ?? 'Uncategorized'),
                    'attempted_count' => $attemptedCount,
                    'correct_count' => $correctCount,
                    'score' => $attemptedCount > 0
                        ? round(($correctCount / $attemptedCount) * 100, 2)
                        : null,
                    'last_submitted_at' => $row->last_submitted_at,
                ];
            })
            ->sortBy(fn (array $subtopic): string => Str::lower((string) $subtopic['label']))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $subjectPerformance
     */
    private function findSubjectScore(array $subjectPerformance, string $subjectLabel): ?float
    {
        $match = collect($subjectPerformance)->first(
            fn (array $subject): bool => (string) ($subject['label'] ?? '') === $subjectLabel
        );

        return is_array($match) && isset($match['score'])
            ? (float) $match['score']
            : null;
    }
}
