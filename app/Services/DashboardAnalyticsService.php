<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\MasteryProfileBuilder;
use App\Support\Search;
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

    public function __construct(
        private readonly MasteryProfileBuilder $masteryProfileBuilder,
        private readonly StudyActionPlanner $studyActionPlanner,
        private readonly TeacherActionPlanner $teacherActionPlanner,
    ) {
    }

    /**
     * What the teacher should do next about their cohort, ranked by who is
     * blocked by what. See TeacherActionPlanner.
     *
     * @return list<array<string, mixed>>
     */
    public function buildTeacherActionPlan(User $staff): array
    {
        return $this->teacherActionPlanner->build($staff, $this->buildStaffAnalytics($staff));
    }


    /**
     * What the student should actually go and do next, worst-first, each one
     * sized and linked. See StudyActionPlanner for why this is separate from
     * the readings it is built on.
     *
     * Scans deeper than the study plan renders, so a slipping area sitting
     * fifth can still surface as the first thing to act on.
     *
     * @return list<array<string, mixed>>
     */
    public function buildActionPlan(User $student): array
    {
        return $this->studyActionPlanner->build(
            $this->buildStudyPlan($student, 8),
            $this->buildSubjectWeaknessPerformance($student),
        );
    }

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

        // One score per exam -- the latest sitting -- as on the Analytics
        // page and in the teacher's reports. A retake replaces the attempt
        // it follows rather than being averaged with it.
        $countingAttempts = $submittedAttempts->unique('exam_id')->values();

        $scoreValues = $this->extractScores($countingAttempts);
        $passingAttempts = $scoreValues
            ->filter(fn (float $score) => $score >= self::PASSING_SCORE)
            ->count();
        $completedExams = $submittedAttempts
            ->pluck('exam_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->count();
        $subjects = $this->buildSubjectPerformance($countingAttempts);
        $topicPerformance = $this->buildTopicWeaknessPerformance($student);
        $weakArea = $this->buildWeakAreaRecommendation($student);
        $latestSubmittedAttempt = $submittedAttempts->first();

        $scoreHistory = $countingAttempts
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
                'failing_attempts' => max(0, (int) $countingAttempts->count() - (int) $passingAttempts),
                'passing_threshold' => self::PASSING_SCORE,
                'average_score_percent' => $scoreValues->isNotEmpty()
                    ? round((float) $scoreValues->avg(), 2)
                    : null,
                'pass_rate_percent' => $countingAttempts->isNotEmpty()
                    ? round(($passingAttempts / $countingAttempts->count()) * 100, 2)
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
            // An object or null, never []: an empty PHP array serialises to a
            // JSON array, and [] is truthy in JavaScript, so a caller testing
            // `if (weak_area)` would render a recommendation block with
            // nothing in it.
            'weak_area' => $weakArea ?: null,
            'score_history' => $scoreHistory,
            'recent_activity' => $recentActivity,
            'generated_at' => now(),
        ];
    }

    /**
     * The same person, to be reported on as the owner of their own rooms and
     * exams only. An administrator's reports cover everyone's by default;
     * this is how one teacher's -- or the administrator's own -- are picked
     * out. The copy is never saved.
     */
    public function asOwner(User $owner): User
    {
        $copy = clone $owner;
        $copy->role = User::ROLE_STAFF_MASTER_EXAMINER;

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStaffReportOverview(User $staff): array
    {
        $managedRooms = Room::query()
            ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('created_by', $staff->id))
            ->get(['id', 'name', 'code', 'archived_at']);
        $managedExams = Exam::query()
            ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('created_by', $staff->id))
            ->get(['id', 'title', 'subject']);

        $roomIds = $managedRooms->pluck('id')->map(fn ($id) => (int) $id)->values();
        $examIds = $managedExams->pluck('id')->map(fn ($id) => (int) $id)->values();

        $studentsEnrolled = DB::table('room_user')
            ->join('rooms', 'rooms.id', '=', 'room_user.room_id')
            ->join('users', 'users.id', '=', 'room_user.user_id')
            ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('rooms.created_by', $staff->id))
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
                ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('exams.created_by', $staff->id))
                ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('rooms.created_by', $staff->id))
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

        $staffAnalytics = $this->buildStaffAnalytics($staff);

        return [
            'metrics' => [
                // The rooms this teacher has now, which are the ones the Rooms
                // page lists. Everything else in this report still reads every
                // room they ever ran: an archived room keeps its results, and
                // the results are what the report is about. Counted together,
                // a teacher with 6 rooms was told they managed 77.
                'managed_rooms' => (int) $managedRooms->whereNull('archived_at')->count(),
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
            // Distribution, trend, cohort topic accuracy, watchlist and room
            // comparison -- the parts of the picture the flat averages above
            // cannot show. Same scoping, so the numbers agree with each other.
            'analytics' => $staffAnalytics,
            // Those same readings turned into moves. Built from the analytics
            // already computed above rather than recomputing them, since
            // buildStaffAnalytics walks every attempt this teacher owns.
            'action_plan' => $this->teacherActionPlanner->build($staff, $staffAnalytics),
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
        'student_status' => 'users.student_status',
        'admission_year' => 'users.admission_year',
        'email' => 'users.email',
        'room_count' => 'current_rooms_count',
        'attempts_submitted' => 'stats.attempts_submitted',
        'average_score_percent' => 'stats.average_score_percent',
        'pass_rate_percent' => 'stats.pass_rate_percent',
        'latest_score_percent' => 'latest.latest_score_percent',
        'last_activity_at' => 'stats.last_activity_at',
    ];

    /** Sort keys where an empty string counts as blank, not just NULL. */
    private const DIRECTORY_TEXT_SORTS = ['name', 'student_id', 'email', 'student_status'];

    /**
     * Sort keys backed by a plain `users` column, which is what makes the
     * two-phase path in paginateStudentDirectory() possible: the page can be
     * chosen off an index before any attempt aggregation happens. Everything
     * outside this list is a derived value that has to exist before rows can
     * be ordered, so those sorts pay for a full rollup.
     */
    private const DIRECTORY_USER_SORTS = ['name', 'student_id', 'year_level', 'email', 'student_status', 'admission_year'];

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
                'users.student_status',
                'users.admission_year',
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
            ->when($search !== '', fn ($outer) => Search::whereAnyContains(
                $outer,
                ['users.name', 'users.email', 'users.student_id'],
                $search,
            ))
            ->when(
                filled($filters['year_level'] ?? null),
                fn ($q) => $q->where('users.year_level', (int) $filters['year_level']),
            )
            ->when(
                filled($filters['student_status'] ?? null),
                fn ($q) => $q->where('users.student_status', $filters['student_status']),
            )
            ->when(
                filled($filters['admission_year'] ?? null),
                fn ($q) => $q->where('users.admission_year', (int) $filters['admission_year']),
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
            'student_status' => $student->student_status,
            'admission_year' => $student->admission_year,
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

    /**
     * Below this many answered items an area is one unlucky guess, not a
     * finding worth sending a student to study. Matches
     * ExamResultFeedbackService::TOPIC_MIN_ITEMS so the dashboard headline
     * and the post-exam result page never name different weakest areas for
     * the same student.
     */
    private const STUDENT_AREA_MIN_ANSWERS = 3;

    /**
     * The student's weakest mapped area, most specific level that has data.
     *
     * Ranked by Bayesian Knowledge Tracing rather than percent correct, so the
     * area that surfaces is the one the student is least likely to actually
     * know -- which is not the same as the one with the lowest score. See
     * MasteryProfileBuilder and KnowledgeTracingService.
     *
     * Runs the subtopic -> topic -> subject cascade twice: first over areas
     * backed by enough answered items to mean something, then, only if the
     * student has not answered that many anywhere yet, over everything, so
     * someone a week into the term still gets a recommendation instead of a
     * blank panel. Without that first pass a single missed question outranks
     * a sustained weakness, because one wrong answer drops mastery below where
     * several mixed answers land.
     *
     * @return array<string, mixed>|array{}
     */
    public function buildWeakAreaRecommendation(User $student): array
    {
        $mastery = $this->masteryProfileBuilder->build($student);

        $subjectPerformance = $this->attachMastery($this->buildSubjectWeaknessPerformance($student), $mastery['subjects'], 'label');
        $topicPerformance = $this->attachMastery($this->buildTopicWeaknessPerformance($student), $mastery['topics'], 'topic_id');
        $subtopicPerformance = $this->attachMastery($this->buildSubtopicWeaknessPerformance($student), $mastery['subtopics'], 'subtopic_id');

        // Label-only, and deliberately unguarded: it just names a subject to
        // fall back to when the area picked sits at a deeper level.
        $fallbackSubject = $this->weakestArea($subjectPerformance, 1);
        $fallbackSubjectLabel = is_array($fallbackSubject)
            ? (string) ($fallbackSubject['label'] ?? '')
            : null;

        foreach ([self::STUDENT_AREA_MIN_ANSWERS, 1] as $minAnswers) {
            $weakSubtopic = $this->weakestArea($subtopicPerformance, $minAnswers);

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
                    'attempted_count' => (int) ($weakSubtopic['attempted_count'] ?? 0),
                    'correct_count' => (int) ($weakSubtopic['correct_count'] ?? 0),
                    'mastery' => $weakSubtopic['mastery'] ?? null,
                    'fallback_subject' => $fallbackSubjectLabel,
                ];
            }

            $weakTopic = $this->weakestArea($topicPerformance, $minAnswers);

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
                    'attempted_count' => (int) ($weakTopic['attempted_count'] ?? 0),
                    'correct_count' => (int) ($weakTopic['correct_count'] ?? 0),
                    'mastery' => $weakTopic['mastery'] ?? null,
                    'fallback_subject' => $fallbackSubjectLabel,
                ];
            }

            $weakSubject = $this->weakestArea($subjectPerformance, $minAnswers);

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
                    'attempted_count' => (int) ($weakSubject['attempted_count'] ?? 0),
                    'correct_count' => (int) ($weakSubject['correct_count'] ?? 0),
                    'mastery' => $weakSubject['mastery'] ?? null,
                    'fallback_subject' => null,
                ];
            }
        }

        return [];
    }

    /**
     * Weakest row in one performance list, or null when none of them carries
     * at least $minAnswers answered items.
     *
     * Ordered by traced mastery -- least likely to be known first -- falling
     * back to percent correct for any row the tracer produced no estimate for.
     * Ties break toward the area with more evidence behind it, since a weak
     * estimate built on six answers is a firmer finding than one built on a
     * single answer.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function weakestArea(array $rows, int $minAnswers): ?array
    {
        return collect($rows)
            ->filter(fn (array $row): bool => ($row['score'] ?? null) !== null
                && (int) ($row['attempted_count'] ?? 0) >= $minAnswers)
            ->sort(function (array $left, array $right): int {
                $byMastery = $this->rankingWeight($left) <=> $this->rankingWeight($right);

                return $byMastery !== 0
                    ? $byMastery
                    : (int) $right['attempted_count'] <=> (int) $left['attempted_count'];
            })
            ->first();
    }

    /**
     * What a row sorts on: its traced mastery when the model produced one,
     * otherwise its raw score expressed on the same 0-1 scale so the two kinds
     * of row can still be compared in one pass.
     *
     * @param  array<string, mixed>  $row
     */
    private function rankingWeight(array $row): float
    {
        $mastery = $row['mastery']['mastery'] ?? null;

        return $mastery !== null
            ? (float) $mastery
            : ((float) ($row['score'] ?? 100.0)) / 100.0;
    }

    /**
     * Join traced mastery onto performance rows, matching on whichever field
     * identifies the skill at that level (subject name, topic id, subtopic id).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<array-key, array<string, mixed>>  $masteryByKey
     * @return array<int, array<string, mixed>>
     */
    private function attachMastery(array $rows, array $masteryByKey, string $keyField): array
    {
        return array_map(function (array $row) use ($masteryByKey, $keyField): array {
            $key = $row[$keyField] ?? null;
            $row['mastery'] = $key !== null ? ($masteryByKey[$key] ?? null) : null;

            return $row;
        }, $rows);
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
     * Class-wide analytics for the teacher Reports page: the shape of the
     * scores, which way they are moving, which mapped topics the cohort keeps
     * missing, and which students to talk to next.
     *
     * Scoped exactly like buildStaffReportOverview() -- this teacher's own
     * exams and rooms, current (non-archived) students, and only the latest
     * attempt per student per session, so a retake never double-counts.
     *
     * @return array<string, mixed>
     */
    public function buildStaffAnalytics(User $staff): array
    {
        $latestAttemptIds = $this->latestStaffAttemptIds($staff);

        if ($latestAttemptIds->isEmpty()) {
            return $this->emptyStaffAnalytics();
        }

        $attempts = ExamAttempt::query()
            ->whereIn('exam_attempts.id', $latestAttemptIds->all())
            ->where('exam_attempts.status', ExamAttempt::STATUS_SUBMITTED)
            ->with([
                'exam:id,title,subject',
                'room:id,name,code',
                'student:id,name,student_id',
            ])
            ->get();

        if ($attempts->isEmpty()) {
            return $this->emptyStaffAnalytics();
        }

        $submittedIds = $attempts->pluck('id')->map(fn ($id): int => (int) $id)->values();

        return [
            'score_distribution' => $this->buildScoreDistribution($attempts),
            'trend' => $this->buildStaffScoreTrend($attempts),
            'topic_performance' => $this->buildCohortTopicPerformance($submittedIds),
            'students_needing_attention' => $this->buildStudentRanking($attempts, 'asc', 6),
            'top_performers' => $this->buildStudentRanking($attempts, 'desc', 3),
            'room_comparison' => $this->buildRoomComparison($attempts),
            'graded_attempts' => (int) $attempts->count(),
            'students_graded' => (int) $attempts->pluck('user_id')->unique()->count(),
        ];
    }

    /**
     * Every question an exam has served, ranked by how often the class missed
     * it -- the Item-Level Analytics list on the teacher Dashboard.
     *
     * Cut from the same attempts as buildStaffAnalytics(), so the counts agree
     * with the session figures shown beside them. A question left blank counts
     * as missed, as it does in the student's score: the denominator is every
     * attempt the question was served in, not every answer it received.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildItemDifficulty(User $staff, Exam $exam): array
    {
        $attemptIds = ExamAttempt::query()
            ->whereIn('id', $this->latestStaffAttemptIds($staff, $exam)->all())
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->pluck('id');

        if ($attemptIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('exam_attempt_questions')
            ->leftJoin('exam_attempt_answers', function ($join) {
                $join->on('exam_attempt_answers.exam_attempt_id', '=', 'exam_attempt_questions.exam_attempt_id')
                    ->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id');
            })
            ->whereIn('exam_attempt_questions.exam_attempt_id', $attemptIds->all())
            ->selectRaw('
                exam_attempt_questions.question_bank_question_id as question_id,
                COUNT(*) as total_attempts,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers
            ')
            ->groupBy('exam_attempt_questions.question_bank_question_id')
            ->get();

        $questionTexts = DB::table('question_bank_questions')
            ->whereIn('id', $rows->pluck('question_id')->all())
            ->pluck('question_text', 'id');

        return collect($rows)
            ->map(function ($row) use ($questionTexts): array {
                $total = (int) $row->total_attempts;
                $correct = (int) $row->correct_answers;

                return [
                    'question_id' => (int) $row->question_id,
                    'question_text' => (string) ($questionTexts[$row->question_id] ?? 'Unknown question'),
                    'total_attempts' => $total,
                    'correct_answers' => $correct,
                    'success_rate_percent' => round(($correct / $total) * 100, 2),
                    'failure_rate_percent' => round((($total - $correct) / $total) * 100, 2),
                ];
            })
            ->sortBy([
                ['failure_rate_percent', 'desc'],
                ['total_attempts', 'desc'],
                ['question_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * The latest attempt per student per session across this teacher's own
     * exams and rooms, current (non-archived) students only -- the scope every
     * class-wide figure on the teacher Dashboard is cut from. Not yet narrowed
     * to submitted attempts: a retake in progress stands in for the attempt
     * before it. $exam limits it to one exam.
     *
     * @return Collection<int, int>
     */
    private function latestStaffAttemptIds(User $staff, ?Exam $exam = null): Collection
    {
        return DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('rooms', 'rooms.id', '=', 'exam_attempts.room_id')
            ->join('users', 'users.id', '=', 'exam_attempts.user_id')
            ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('exams.created_by', $staff->id))
            ->when(!$staff->overseesAllTeaching(), fn ($query) => $query->where('rooms.created_by', $staff->id))
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->when($exam, fn ($query) => $query->where('exam_attempts.exam_id', $exam->id))
            ->groupBy('exam_attempts.exam_id', 'exam_attempts.room_id', 'exam_attempts.user_id')
            ->selectRaw('MAX(exam_attempts.id) as id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStaffAnalytics(): array
    {
        return [
            'score_distribution' => [],
            'trend' => [],
            'topic_performance' => [],
            'students_needing_attention' => [],
            'top_performers' => [],
            'room_comparison' => [],
            'graded_attempts' => 0,
            'students_graded' => 0,
        ];
    }

    /**
     * Score bands for the distribution histogram. Split at the passing mark so
     * "how many cleared it" is readable off the chart without doing sums.
     */
    private const SCORE_BANDS = [
        ['label' => '0-59', 'min' => 0.0, 'max' => 59.9999],
        ['label' => '60-74', 'min' => 60.0, 'max' => 74.9999],
        ['label' => '75-84', 'min' => 75.0, 'max' => 84.9999],
        ['label' => '85-100', 'min' => 85.0, 'max' => 100.0],
    ];

    /** Below this many answered items a topic is one student's bad day, not a class-wide signal. */
    private const COHORT_TOPIC_MIN_ANSWERS = 3;

    /**
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function buildScoreDistribution(Collection $attempts): array
    {
        $scores = $this->extractScores($attempts);
        $total = $scores->count();

        return collect(self::SCORE_BANDS)
            ->map(function (array $band) use ($scores, $total): array {
                $count = $scores
                    ->filter(fn (float $score): bool => $score >= $band['min'] && $score <= $band['max'])
                    ->count();

                return [
                    'label' => $band['label'],
                    'count' => $count,
                    'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                    'is_passing' => $band['min'] >= self::PASSING_SCORE,
                ];
            })
            ->all();
    }

    /**
     * Average score per exam session, oldest to newest -- answers "is the
     * cohort improving?", which a single overall average cannot.
     *
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function buildStaffScoreTrend(Collection $attempts): array
    {
        $sessions = $attempts
            ->groupBy(fn (ExamAttempt $attempt) => $this->sessionKey((int) $attempt->exam_id, (int) $attempt->room_id))
            ->map(function (Collection $sessionAttempts): array {
                $scores = $this->extractScores($sessionAttempts);
                $first = $sessionAttempts->first();

                return [
                    'exam_id' => (int) $first->exam_id,
                    'room_id' => (int) $first->room_id,
                    'exam_title' => (string) ($first->exam?->title ?? 'Exam'),
                    'room_name' => (string) ($first->room?->name ?? ''),
                    'average_score_percent' => $scores->isNotEmpty() ? round((float) $scores->avg(), 2) : null,
                    'graded_count' => $scores->count(),
                    'sort_key' => $sessionAttempts
                        ->map(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                        ->filter()
                        ->max(),
                ];
            })
            ->filter(fn (array $row): bool => $row['average_score_percent'] !== null)
            ->sortBy('sort_key')
            ->values();

        return $sessions
            ->slice(-8)
            ->values()
            ->map(function (array $row, int $index): array {
                unset($row['sort_key']);
                $row['label'] = 'S' . ($index + 1);

                return $row;
            })
            ->all();
    }

    /**
     * Per-topic accuracy across every graded answer the cohort submitted --
     * the class-wide counterpart to the student study plan, and the payoff
     * for the per-item topic mapping.
     *
     * @param  Collection<int, int>  $attemptIds
     * @return array<int, array<string, mixed>>
     */
    private function buildCohortTopicPerformance(Collection $attemptIds): array
    {
        $rows = DB::table('exam_attempt_answers')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_answers.question_bank_question_id')
            ->join('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->whereIn('exam_attempt_answers.exam_attempt_id', $attemptIds->all())
            ->selectRaw("
                topics.id as topic_id,
                topics.name as label,
                COALESCE(NULLIF(question_bank_questions.subject, ''), topics.subject, 'Uncategorized') as subject,
                COUNT(*) as attempted_count,
                SUM(CASE WHEN exam_attempt_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct_count
            ")
            ->groupBy('topics.id', 'topics.name', 'topics.subject', 'question_bank_questions.subject')
            ->get();

        return collect($rows)
            ->map(function ($row): array {
                $attempted = (int) ($row->attempted_count ?? 0);
                $correct = (int) ($row->correct_count ?? 0);

                return [
                    'topic_id' => (int) ($row->topic_id ?? 0),
                    'label' => (string) ($row->label ?? 'Untitled Topic'),
                    'subject' => (string) ($row->subject ?? 'Uncategorized'),
                    'attempted_count' => $attempted,
                    'correct_count' => $correct,
                    'score' => $attempted > 0 ? round(($correct / $attempted) * 100, 2) : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['score'] !== null
                && $row['attempted_count'] >= self::COHORT_TOPIC_MIN_ANSWERS)
            ->sortBy('score')
            ->values()
            ->all();
    }

    /**
     * Students ranked by their average across this teacher's sessions. `asc`
     * additionally keeps only those below the passing mark, so the watchlist
     * never pads itself with students who are doing fine.
     *
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function buildStudentRanking(Collection $attempts, string $direction, int $limit): array
    {
        $rows = $attempts
            ->groupBy('user_id')
            ->map(function (Collection $studentAttempts): array {
                $scores = $this->extractScores($studentAttempts);
                $student = $studentAttempts->first()?->student;
                $passing = $scores->filter(fn (float $score): bool => $score >= self::PASSING_SCORE)->count();

                $weakestSubject = $studentAttempts
                    ->groupBy(fn (ExamAttempt $attempt) => $this->normalizeSubjectLabel($attempt->exam?->subject))
                    ->map(fn (Collection $subjectAttempts) => $this->extractScores($subjectAttempts)->avg())
                    ->filter(fn ($average): bool => !is_null($average))
                    ->sort()
                    ->keys()
                    ->first();

                return [
                    'id' => (int) ($student?->id ?? 0),
                    'name' => (string) ($student?->name ?? 'Unknown student'),
                    'student_id' => $student?->student_id,
                    'average_score_percent' => $scores->isNotEmpty() ? round((float) $scores->avg(), 1) : null,
                    'attempts_submitted' => $scores->count(),
                    'pass_rate_percent' => $scores->isNotEmpty()
                        ? round(($passing / $scores->count()) * 100, 1)
                        : null,
                    'weakest_subject' => $weakestSubject,
                ];
            })
            ->filter(fn (array $row): bool => $row['average_score_percent'] !== null)
            ->values();

        // Both directions are gated on the passing mark, not just ranked: a
        // top-N of a two-student cohort would otherwise list a 37% average
        // under "top performers", and the bottom-N would pad itself with
        // students who are doing fine. An empty list is the honest answer.
        if ($direction === 'asc') {
            $rows = $rows
                ->filter(fn (array $row): bool => (float) $row['average_score_percent'] < self::PASSING_SCORE)
                ->sortBy('average_score_percent');
        } else {
            $rows = $rows
                ->filter(fn (array $row): bool => (float) $row['average_score_percent'] >= self::PASSING_SCORE)
                ->sortByDesc('average_score_percent');
        }

        return $rows->take($limit)->values()->all();
    }

    /**
     * Room-against-room comparison, strongest first -- the view that shows a
     * section falling behind the others rather than an average hiding it.
     *
     * @param  Collection<int, ExamAttempt>  $attempts
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomComparison(Collection $attempts): array
    {
        return $attempts
            ->groupBy('room_id')
            ->map(function (Collection $roomAttempts): array {
                $scores = $this->extractScores($roomAttempts);
                $room = $roomAttempts->first()?->room;
                $passing = $scores->filter(fn (float $score): bool => $score >= self::PASSING_SCORE)->count();

                return [
                    'room_id' => (int) ($room?->id ?? 0),
                    'room_name' => (string) ($room?->name ?? 'Room'),
                    'room_code' => $room?->code,
                    'students_graded' => (int) $roomAttempts->pluck('user_id')->unique()->count(),
                    'graded_attempts' => $scores->count(),
                    'average_score_percent' => $scores->isNotEmpty() ? round((float) $scores->avg(), 1) : null,
                    'pass_rate_percent' => $scores->isNotEmpty()
                        ? round(($passing / $scores->count()) * 100, 1)
                        : null,
                ];
            })
            ->filter(fn (array $row): bool => $row['average_score_percent'] !== null)
            ->sortByDesc('average_score_percent')
            ->values()
            ->all();
    }

    /**
     * Ranked "what should I study next" queue: the weakest mapped areas the
     * student has actually answered questions in, worst first.
     *
     * Each entry resolves to the most specific level that has data -- a weak
     * subtopic beats the topic it sits under, the same precedence
     * buildWeakAreaRecommendation() uses -- so one soft spot is never listed
     * twice at two different depths.
     *
     * topic_id/subtopic_id are the Review Bot link target, not just labels:
     * an area whose own subtopic has no published practice questions but
     * whose parent topic does is deliberately downgraded to a topic-level
     * link, so the button still lands on something answerable instead of an
     * empty session. review_ready is false only when neither level has any.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildStudyPlan(User $student, int $limit = 5): array
    {
        $mastery = $this->masteryProfileBuilder->build($student);

        $subtopicRows = collect($this->attachMastery($this->buildSubtopicWeaknessPerformance($student), $mastery['subtopics'], 'subtopic_id'));
        $topicRows = collect($this->attachMastery($this->buildTopicWeaknessPerformance($student), $mastery['topics'], 'topic_id'));

        $subtopicCoveredTopicIds = $subtopicRows
            ->pluck('topic_id')
            ->map(fn ($topicId): int => (int) $topicId)
            ->filter()
            ->unique();

        $entries = $subtopicRows
            ->map(fn (array $row): array => [
                'level' => 'subtopic',
                'label' => (string) $row['label'],
                'subject' => (string) $row['subject'],
                'topic' => (string) $row['topic'],
                'topic_id' => ((int) $row['topic_id']) ?: null,
                'subtopic_id' => ((int) $row['subtopic_id']) ?: null,
                'attempted_count' => (int) $row['attempted_count'],
                'correct_count' => (int) $row['correct_count'],
                'score' => $row['score'],
                'mastery' => $row['mastery'] ?? null,
            ])
            ->concat(
                $topicRows
                    ->reject(fn (array $row): bool => $subtopicCoveredTopicIds->contains((int) $row['topic_id']))
                    ->map(fn (array $row): array => [
                        'level' => 'topic',
                        'label' => (string) $row['label'],
                        'subject' => (string) $row['subject'],
                        'topic' => (string) $row['label'],
                        'topic_id' => ((int) $row['topic_id']) ?: null,
                        'subtopic_id' => null,
                        'attempted_count' => (int) $row['attempted_count'],
                        'correct_count' => (int) $row['correct_count'],
                        'score' => $row['score'],
                        'mastery' => $row['mastery'] ?? null,
                    ])
            )
            ->filter(fn (array $row): bool => $row['score'] !== null
                && (float) $row['score'] < self::PASSING_SCORE
                && $row['attempted_count'] > 0)
            // Least likely to be known first, and where two areas trace to the
            // same mastery the one with more answered items leads -- an
            // estimate built on six questions is a firmer finding than one
            // built on a single answer.
            ->sort(function (array $left, array $right): int {
                $byMastery = $this->rankingWeight($left) <=> $this->rankingWeight($right);

                return $byMastery !== 0
                    ? $byMastery
                    : $right['attempted_count'] <=> $left['attempted_count'];
            })
            ->values();

        // Areas with enough evidence to act on lead the queue; a thin one is
        // only worth listing when the student has nothing firmer yet.
        $wellEvidenced = $entries
            ->filter(fn (array $row): bool => (int) $row['attempted_count'] >= self::STUDENT_AREA_MIN_ANSWERS)
            ->values();

        $entries = ($wellEvidenced->isNotEmpty() ? $wellEvidenced : $entries)
            ->take($limit)
            ->values();

        if ($entries->isEmpty()) {
            return [];
        }

        $readyTopicIds = $this->publishedReviewBotIds('topic_id');
        $readySubtopicIds = $this->publishedReviewBotIds('subtopic_id');

        return $entries
            ->map(function (array $row) use ($readyTopicIds, $readySubtopicIds): array {
                $hasSubtopicSet = $row['subtopic_id'] !== null && $readySubtopicIds->contains($row['subtopic_id']);
                $hasTopicSet = $row['topic_id'] !== null && $readyTopicIds->contains($row['topic_id']);

                if (!$hasSubtopicSet) {
                    $row['subtopic_id'] = null;
                }

                $row['review_ready'] = $hasSubtopicSet || $hasTopicSet;

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Distinct topic/subtopic ids that already have at least one published
     * Review Bot question behind them.
     *
     * @return Collection<int, int>
     */
    private function publishedReviewBotIds(string $column): Collection
    {
        return DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(fn ($id): int => (int) $id);
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
