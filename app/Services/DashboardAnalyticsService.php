<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\MasteryProfileBuilder;
use App\Support\LleTaxonomy;
use App\Support\PassingMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardAnalyticsService
{
    private const STUDENT_HISTORY_LIMIT = 6;
    private const STUDENT_ACTIVITY_LIMIT = 8;
    private const STAFF_ACTIVITY_LIMIT = 14;
    private const STAFF_SESSION_LIMIT = 8;

    public function __construct(
        private readonly MasteryProfileBuilder $masteryProfileBuilder,
        private readonly StudyActionPlanner $studyActionPlanner,
        private readonly TeacherActionPlanner $teacherActionPlanner,
        private readonly ReviewBotEligibility $reviewBotEligibility,
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
            $student,
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
        $mark = PassingMark::current();
        $passingAttempts = $scoreValues
            ->filter(fn (float $score) => $mark->passes($score))
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
                // The students' passing mark: what this student is judged against.
                'passing_threshold' => (float) $mark->mark,
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
                ->filter(fn (array $subject) => !$mark->passes((float) ($subject['score'] ?? 0)))
                ->values()
                ->all(),
            'passing' => $mark->toArray(),
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
        $mark = PassingMark::current();
        $passingLatestAttempts = $submittedScoreValues
            ->filter(fn (float $score) => $mark->passes($score))
            ->count();
        $sessionEnrollments = (int) $sessionRows
            ->sum(fn ($session) => (int) ($studentCountsByRoomId[(int) $session->room_id] ?? 0));

        $sessionPerformance = collect($sessionRows)
            ->map(function ($session) use ($latestAttemptsBySession, $studentCountsByRoomId, $mark): array {
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
                    ->filter(fn (float $score) => $mark->passes($score))
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
            // Every figure above is a real percentage; the page shows teachers
            // grades from them when the students' mark is above 75.
            'passing' => $mark->toArray(),
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
     * @param  Collection<int, ExamAttempt>  $submittedAttempts
     * @return array<int, array<string, mixed>>
     */
    private function buildSubjectPerformance(Collection $submittedAttempts): array
    {
        $mark = PassingMark::current();

        return $submittedAttempts
            ->groupBy(fn (ExamAttempt $attempt) => $this->normalizeSubjectLabel($attempt->exam?->subject))
            ->map(function (Collection $attempts, string $label) use ($mark): array {
                $sortedAttempts = $attempts
                    ->sortByDesc(fn (ExamAttempt $attempt) => $this->attemptSubmissionTimestamp($attempt))
                    ->values();
                $scores = $this->extractScores($sortedAttempts);
                $latestAttempt = $sortedAttempts->first();
                $passingCount = $scores
                    ->filter(fn (float $score) => $mark->passes($score))
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
     * Below this many items an area is one unlucky guess, not a finding worth
     * sending a student to study. Matches
     * ExamResultFeedbackService::TOPIC_MIN_ITEMS so the dashboard headline
     * and the post-exam result page never name different weakest areas for
     * the same student.
     */
    private const STUDENT_AREA_MIN_ANSWERS = 3;

    /**
     * The student's weakest mapped area, most specific level that has data.
     *
     * Topics and sub-topics are ranked by Bayesian Knowledge Tracing (one
     * update per exam) rather than percent correct, so the area that surfaces
     * is the one the student is least likely to actually know -- which is not
     * the same as the one with the lowest score. See MasteryProfileBuilder
     * and KnowledgeTracingService. Subjects are not traced; a subject is
     * ranked by its percent correct.
     *
     * Runs the subtopic -> topic -> subject cascade twice: first over areas
     * backed by enough items to mean something, then, only if the student
     * has not met that many anywhere yet, over everything, so someone a week
     * into the term still gets a recommendation instead of a blank panel.
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
     * A student's percent correct per subject, over the same items mastery is
     * traced from (MasteryProfileBuilder::latestSittingItems()): every exam's
     * latest submitted sitting, every item the student was dealt in it, a
     * blank counted wrong -- the way the exam itself was scored.
     * `attempted_count` is the number of those items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildSubjectWeaknessPerformance(User $student): array
    {
        return $this->areaPerformance(
            $student,
            fn (array $item): ?string => $item['subject'],
            fn (array $item): array => ['label' => $item['subject']],
        );
    }

    /**
     * Percent correct per TOS topic, over the same items as
     * buildSubjectWeaknessPerformance(). "Other / Unclassified" counts toward
     * the subject, never as a topic.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildTopicWeaknessPerformance(User $student): array
    {
        return $this->areaPerformance(
            $student,
            fn (array $item): ?string => $item['topic_id'] !== null && !$item['other'] ? (string) $item['topic_id'] : null,
            fn (array $item): array => [
                'topic_id' => (int) $item['topic_id'],
                'label' => (string) ($item['topic'] ?? 'Untitled Topic'),
                'subject' => $item['subject'],
            ],
        );
    }

    /**
     * Percent correct per TOS sub-topic, over the same items as
     * buildSubjectWeaknessPerformance().
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildSubtopicWeaknessPerformance(User $student): array
    {
        return $this->areaPerformance(
            $student,
            fn (array $item): ?string => $item['subtopic_id'] !== null && $item['topic_id'] !== null && !$item['other'] ? (string) $item['subtopic_id'] : null,
            fn (array $item): array => [
                'subtopic_id' => (int) $item['subtopic_id'],
                'label' => (string) ($item['subtopic'] ?? 'Untitled Subtopic'),
                'topic_id' => (int) $item['topic_id'],
                'topic' => (string) ($item['topic'] ?? ''),
                'subject' => $item['subject'],
            ],
        );
    }

    /**
     * Percent correct per area at one level, sorted by name (natural order,
     * so "1.2" comes before "1.10").
     *
     * @param  callable(array<string, mixed>): ?string  $areaOf  an item's area, or null to leave it out
     * @param  callable(array<string, mixed>): array<string, mixed>  $describe  the area's identifying fields
     * @return array<int, array<string, mixed>>
     */
    private function areaPerformance(User $student, callable $areaOf, callable $describe): array
    {
        $areas = [];

        foreach ($this->masteryProfileBuilder->latestSittingItems($student) as $item) {
            $area = $areaOf($item);

            if ($area === null) {
                continue;
            }

            $areas[$area] ??= $describe($item) + ['attempted_count' => 0, 'correct_count' => 0, 'last_submitted_at' => null];
            $areas[$area]['attempted_count']++;
            $areas[$area]['correct_count'] += $item['correct'] ? 1 : 0;
            $areas[$area]['last_submitted_at'] = max((string) $areas[$area]['last_submitted_at'], $item['submitted_at']);
        }

        return collect($areas)
            ->map(fn (array $area): array => $area + [
                'score' => $area['attempted_count'] > 0
                    ? round(($area['correct_count'] / $area['attempted_count']) * 100, 2)
                    : null,
            ])
            ->sortBy(fn (array $area): string => Str::lower((string) $area['label']), SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * The student's topic and sub-topic mastery, grouped under their subject:
     * each subject with its percent correct (subjects are not traced), each
     * of its TOS topics with its traced mastery and percent correct, and each
     * topic's sub-topics the same way. Subjects in TOS order, then any other
     * subject; topics and sub-topics in TOS order.
     *
     * @return list<array<string, mixed>>
     */
    public function buildMasteryBySubject(User $student): array
    {
        $mastery = $this->masteryProfileBuilder->build($student);
        $subjects = collect($this->buildSubjectWeaknessPerformance($student))->keyBy('label');
        $topics = collect($this->attachMastery($this->buildTopicWeaknessPerformance($student), $mastery['topics'], 'topic_id'))->groupBy('subject');
        $subtopics = collect($this->attachMastery($this->buildSubtopicWeaknessPerformance($student), $mastery['subtopics'], 'subtopic_id'))->groupBy('topic_id');
        $tosOrder = array_flip(LleTaxonomy::subjectNames());

        return $subjects
            ->sortBy(fn (array $subject): string => sprintf('%02d %s', $tosOrder[$subject['label']] ?? 99, Str::lower($subject['label'])))
            ->map(fn (array $subject): array => [
                'subject' => $subject['label'],
                'score' => $subject['score'],
                'items' => $subject['attempted_count'],
                'correct' => $subject['correct_count'],
                'topics' => collect($topics->get($subject['label'], []))
                    ->map(fn (array $topic): array => [
                        'topic_id' => $topic['topic_id'],
                        'label' => $topic['label'],
                        'score' => $topic['score'],
                        'items' => $topic['attempted_count'],
                        'correct' => $topic['correct_count'],
                        'mastery' => $topic['mastery'],
                        'subtopics' => collect($subtopics->get($topic['topic_id'], []))
                            ->map(fn (array $subtopic): array => [
                                'subtopic_id' => $subtopic['subtopic_id'],
                                'label' => $subtopic['label'],
                                'score' => $subtopic['score'],
                                'items' => $subtopic['attempted_count'],
                                'correct' => $subtopic['correct_count'],
                                'mastery' => $subtopic['mastery'],
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
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
     * Teachers' scale: a score is banded by its grade (PassingMark), which is
     * the score itself while the students' mark is 75.
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
        $mark = PassingMark::current();
        $grades = $this->extractScores($attempts)->map(fn (float $score): float => (float) $mark->grade($score));
        $total = $grades->count();

        return collect(self::SCORE_BANDS)
            ->map(function (array $band) use ($grades, $total): array {
                $count = $grades
                    ->filter(fn (float $grade): bool => $grade >= $band['min'] && $grade <= $band['max'])
                    ->count();

                return [
                    'label' => $band['label'],
                    'count' => $count,
                    'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                    'is_passing' => $band['min'] >= PassingMark::STAFF_MARK,
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
            // "Other / Unclassified" counts toward the subject, never as a topic.
            ->where('topics.name', '!=', LleTaxonomy::OTHER_TOPIC)
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
        $mark = PassingMark::current();

        $rows = $attempts
            ->groupBy('user_id')
            ->map(function (Collection $studentAttempts) use ($mark): array {
                $scores = $this->extractScores($studentAttempts);
                $student = $studentAttempts->first()?->student;
                $passing = $scores->filter(fn (float $score): bool => $mark->passes($score))->count();

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
                ->filter(fn (array $row): bool => !$mark->passes((float) $row['average_score_percent']))
                ->sortBy('average_score_percent');
        } else {
            $rows = $rows
                ->filter(fn (array $row): bool => $mark->passes((float) $row['average_score_percent']))
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
        $mark = PassingMark::current();

        return $attempts
            ->groupBy('room_id')
            ->map(function (Collection $roomAttempts) use ($mark): array {
                $scores = $this->extractScores($roomAttempts);
                $room = $roomAttempts->first()?->room;
                $passing = $scores->filter(fn (float $score): bool => $mark->passes($score))->count();

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
     * empty session. review_ready is false only when neither level has any
     * the student may practise (ReviewBotEligibility).
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildStudyPlan(User $student, int $limit = 5): array
    {
        $mastery = $this->masteryProfileBuilder->build($student);
        $mark = PassingMark::current();

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
                && !$mark->passes((float) $row['score'])
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

        $sourceIds = $this->reviewBotEligibility->sourceIds($student);
        $readyTopicIds = $this->publishedReviewBotIds($sourceIds, 'topic_id');
        $readySubtopicIds = $this->publishedReviewBotIds($sourceIds, 'subtopic_id');

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
     * Review Bot question behind them that the student may practise.
     *
     * @param  list<int>  $sourceIds  ReviewBotEligibility::sourceIds()
     * @return Collection<int, int>
     */
    private function publishedReviewBotIds(array $sourceIds, string $column): Collection
    {
        return DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->tap(fn ($query) => $this->reviewBotEligibility->constrain($query, $sourceIds, 'source_question_id'))
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
