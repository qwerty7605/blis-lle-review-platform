<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Room;
use App\Models\User;
use App\Support\PassingMark;
use App\Support\Search;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Students page: the directory of students with their year, status,
 * average, last activity and strongest subject, sorted, filtered and paged in
 * the database (so a sort by a worked-out figure still pages correctly), plus
 * the totals at the top of the page, cached for a minute.
 */
class StudentDirectoryService
{
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
            // Real percentages; the page shows them as grades when the
            // students' mark is above 75.
            'passing' => PassingMark::current()->toArray(),
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
            'passing' => PassingMark::current()->toArray(),
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
     * only non-null scores at or above the students' passing mark in the
     * numerator, which is what the PHP it replaces did: NULL >= 75 yields NULL
     * and SUM skips it.
     */
    private function attemptStatsSubquery(?array $userIds = null): \Illuminate\Database\Query\Builder
    {
        // An integer from a validated setting, never request input.
        $passing = (int) PassingMark::current()->mark;

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
        // Keyed by the mark: a new one shows on the next load, not a minute later.
        return Cache::remember(
            self::DIRECTORY_SUMMARY_CACHE_KEY . ':' . PassingMark::current()->mark,
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
}
