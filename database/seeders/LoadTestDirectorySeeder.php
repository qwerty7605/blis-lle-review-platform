<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Manufactures a realistically sized student population so the staff Students
 * tab can be benchmarked against something other than a guess.
 *
 * The BLIS programme runs four year levels, so the enrolled population is a
 * steady-state pipeline: one batch in, one batch out. What actually grows
 * without bound is `exam_attempts` -- every term adds attempts for everyone
 * currently enrolled, and archived students keep theirs forever. That is the
 * axis this seeder is built to stress, which is why the batch count and the
 * per-student attempt count are separate knobs.
 *
 * Tune with env vars (all optional):
 *
 *   LOADTEST_STUDENTS_PER_YEAR    students per year level      (default 300)
 *   LOADTEST_ATTEMPTS_PER_STUDENT attempts each has sat        (default 16)
 *   LOADTEST_GRADUATED_BATCHES    archived batches to add      (default 0)
 *   LOADTEST_PURGE_ONLY           remove it all and stop       (default 0)
 *
 * So the default builds 4 x 300 = 1200 enrolled students with ~19k attempts;
 * LOADTEST_GRADUATED_BATCHES=10 adds ten archived batches on top to model
 * roughly a decade of accumulation.
 *
 * IMPORTANT -- what this deliberately does NOT create: `exam_attempt_questions`
 * and `exam_attempt_answers`. The directory endpoint never reads them (it only
 * touches exam_attempts columns), and generating them per attempt would make
 * seeding 20k attempts take minutes instead of seconds. That means this data is
 * fine for benchmarking the directory and the analytics aggregates, and useless
 * for anything that renders an individual attempt's answers.
 *
 * Everything it creates is namespaced to the `loadtest.local` email domain and
 * the LOADTEST- room/exam prefixes, and every run purges the previous one, so
 * it can never be confused with demo or real records.
 */
class LoadTestDirectorySeeder extends Seeder
{
    use WithoutModelEvents;

    private const EMAIL_DOMAIN = 'loadtest.local';
    private const TEACHER_EMAIL = 'teacher@loadtest.local';
    private const ROOM_CODE_PREFIX = 'LOADTEST-Y';
    private const EXAM_TITLE_PREFIX = '[LoadTest] ';

    /** Student IDs start here to stay clear of demo and real numbering. */
    private const STUDENT_ID_BASE = 9000000;

    private const YEAR_LEVELS = [1, 2, 3, 4];

    /** The six canonical BLIS subjects, so subject aggregates look real. */
    private const SUBJECTS = [
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Information Technology',
        'Reference Services',
        'Library Management',
        'Selection and Acquisition',
    ];

    public function run(): void
    {
        if ((bool) env('LOADTEST_PURGE_ONLY', false)) {
            $this->purge();
            $this->command?->info('Load test data purged.');

            return;
        }

        $studentsPerYear = max(1, (int) env('LOADTEST_STUDENTS_PER_YEAR', 300));
        $attemptsPerStudent = max(0, (int) env('LOADTEST_ATTEMPTS_PER_STUDENT', 16));
        $graduatedBatches = max(0, (int) env('LOADTEST_GRADUATED_BATCHES', 0));

        $enrolledCount = $studentsPerYear * count(self::YEAR_LEVELS);
        $archivedCount = $studentsPerYear * $graduatedBatches;

        $this->command?->info(sprintf(
            'Load test: %d enrolled + %d archived students, ~%d attempts each.',
            $enrolledCount,
            $archivedCount,
            $attemptsPerStudent,
        ));

        $this->purge();

        $teacher = $this->seedTeacher();
        $rooms = $this->seedRooms($teacher);
        $exams = $this->seedExams($teacher, $rooms);

        // Deterministic so two runs with the same knobs produce the same
        // numbers -- a benchmark you cannot reproduce is not a benchmark.
        mt_srand(20260906);

        $studentRows = $this->buildStudentRows($studentsPerYear, $graduatedBatches);
        $this->insertChunked('users', $studentRows);

        $students = DB::table('users')
            ->select('id', 'year_level', 'archived_at')
            ->where('email', 'like', '%@' . self::EMAIL_DOMAIN)
            ->where('role', User::ROLE_STUDENT)
            ->get();

        $this->command?->info('Students inserted: ' . $students->count());

        $this->seedRoomMembership($students, $rooms);
        $this->seedAttempts($students, $exams, $rooms, $attemptsPerStudent);

        $this->report();
    }

    /**
     * Remove everything a previous run created. exam_attempts and room_user
     * both cascade from users, so deleting the students clears them; the rooms
     * and exams are dropped explicitly since nothing cascades to them.
     */
    private function purge(): void
    {
        $userIds = DB::table('users')
            ->where('email', 'like', '%@' . self::EMAIL_DOMAIN)
            ->pluck('id');

        if ($userIds->isNotEmpty()) {
            // Explicit rather than relying on the FK cascade, so this still
            // works if those constraints are ever tightened to restrictOnDelete
            // (which they should be -- attempt history is training data).
            DB::table('exam_attempts')->whereIn('user_id', $userIds->all())->delete();
            DB::table('room_user')->whereIn('user_id', $userIds->all())->delete();
            DB::table('users')->whereIn('id', $userIds->all())->delete();
        }

        $roomIds = DB::table('rooms')
            ->where('code', 'like', self::ROOM_CODE_PREFIX . '%')
            ->pluck('id');

        if ($roomIds->isNotEmpty()) {
            DB::table('exam_room')->whereIn('room_id', $roomIds->all())->delete();
            DB::table('room_user')->whereIn('room_id', $roomIds->all())->delete();
            DB::table('exam_attempts')->whereIn('room_id', $roomIds->all())->delete();
            DB::table('rooms')->whereIn('id', $roomIds->all())->delete();
        }

        $examIds = DB::table('exams')
            ->where('title', 'like', self::EXAM_TITLE_PREFIX . '%')
            ->pluck('id');

        if ($examIds->isNotEmpty()) {
            DB::table('exam_room')->whereIn('exam_id', $examIds->all())->delete();
            DB::table('exam_attempts')->whereIn('exam_id', $examIds->all())->delete();
            DB::table('exams')->whereIn('id', $examIds->all())->delete();
        }
    }

    private function seedTeacher(): User
    {
        return User::query()->updateOrCreate([
            'email' => self::TEACHER_EMAIL,
        ], [
            'name' => 'Load Test Examiner',
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
            'password' => Hash::make('pass'),
        ]);
    }

    /**
     * One room per year level -- mirrors how sections are actually organised,
     * and keeps room_count realistic at 1 rather than an accidental fan-out.
     *
     * @return array<int, int> year level => room id
     */
    private function seedRooms(User $teacher): array
    {
        $rooms = [];
        $createdAt = CarbonImmutable::now()->subYears(4);

        foreach (self::YEAR_LEVELS as $yearLevel) {
            $room = Room::query()->updateOrCreate([
                'code' => self::ROOM_CODE_PREFIX . $yearLevel,
            ], [
                'name' => "Load Test Year {$yearLevel}",
                'created_by' => $teacher->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            $rooms[$yearLevel] = (int) $room->id;
        }

        return $rooms;
    }

    /**
     * One exam per subject, assigned to every room, so a student's attempts
     * spread across subjects the way the analytics page expects.
     *
     * @param  array<int, int>  $rooms
     * @return list<int>
     */
    private function seedExams(User $teacher, array $rooms): array
    {
        $examIds = [];
        $scheduledAt = CarbonImmutable::now()->subYears(4);

        foreach (self::SUBJECTS as $index => $subject) {
            $exam = Exam::query()->updateOrCreate([
                'created_by' => $teacher->id,
                'title' => self::EXAM_TITLE_PREFIX . $subject,
            ], [
                'subject' => $subject,
                'description' => 'Synthetic exam for directory load testing.',
                'total_items' => 50,
                'duration_minutes' => 60,
                'scheduled_at' => $scheduledAt->addMonths($index),
                'schedule_start_at' => $scheduledAt->addMonths($index),
                'schedule_end_at' => $scheduledAt->addMonths($index)->addDays(7),
            ]);

            $examIds[] = (int) $exam->id;

            foreach ($rooms as $roomId) {
                DB::table('exam_room')->updateOrInsert([
                    'exam_id' => $exam->id,
                    'room_id' => $roomId,
                ], [
                    'assigned_by' => $teacher->id,
                    'created_at' => $scheduledAt,
                    'updated_at' => $scheduledAt,
                ]);
            }
        }

        return $examIds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStudentRows(int $studentsPerYear, int $graduatedBatches): array
    {
        $password = Hash::make('pass');
        $now = CarbonImmutable::now();
        $rows = [];
        $sequence = 0;

        foreach (self::YEAR_LEVELS as $yearLevel) {
            for ($i = 0; $i < $studentsPerYear; $i++) {
                // Enrolled: admitted (yearLevel - 1) years ago.
                $createdAt = $now->subYears($yearLevel - 1)->subMonths(mt_rand(0, 11));
                $rows[] = $this->studentRow($sequence++, $yearLevel, null, $createdAt, $password);
            }
        }

        for ($batch = 1; $batch <= $graduatedBatches; $batch++) {
            for ($i = 0; $i < $studentsPerYear; $i++) {
                // Archived: graduated `batch` years ago, so they were admitted
                // four years before that.
                $archivedAt = $now->subYears($batch);
                $createdAt = $archivedAt->subYears(4);
                $rows[] = $this->studentRow($sequence++, 4, $archivedAt, $createdAt, $password);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function studentRow(
        int $sequence,
        int $yearLevel,
        ?CarbonImmutable $archivedAt,
        CarbonImmutable $createdAt,
        string $password,
    ): array {
        return [
            'name' => 'Load Test Student ' . $sequence,
            'email' => 'student' . $sequence . '@' . self::EMAIL_DOMAIN,
            'student_id' => (string) (self::STUDENT_ID_BASE + $sequence),
            'role' => User::ROLE_STUDENT,
            'year_level' => $yearLevel,
            'is_active' => true,
            'archived_at' => $archivedAt,
            'password' => $password,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $students
     * @param  array<int, int>  $rooms
     */
    private function seedRoomMembership($students, array $rooms): void
    {
        $rows = [];

        foreach ($students as $student) {
            $roomId = $rooms[(int) $student->year_level] ?? $rooms[4];

            $rows[] = [
                'room_id' => $roomId,
                'user_id' => $student->id,
                'created_at' => $student->archived_at ?? CarbonImmutable::now(),
                'updated_at' => $student->archived_at ?? CarbonImmutable::now(),
            ];
        }

        $this->insertChunked('room_user', $rows);
    }

    /**
     * Attempts are spread backwards from "now" across the student's time in the
     * programme, so the chronological bucketing in LetReadinessFeatureBuilder
     * has something plausibly shaped to work with.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $students
     * @param  list<int>  $exams
     * @param  array<int, int>  $rooms
     */
    private function seedAttempts($students, array $exams, array $rooms, int $attemptsPerStudent): void
    {
        if ($attemptsPerStudent < 1 || $exams === []) {
            return;
        }

        $rows = [];
        $examCount = count($exams);

        foreach ($students as $student) {
            $roomId = $rooms[(int) $student->year_level] ?? $rooms[4];
            $anchor = $student->archived_at
                ? CarbonImmutable::parse($student->archived_at)
                : CarbonImmutable::now();

            // A student's ability, so their scores cluster instead of being
            // uniform noise -- otherwise every average lands near 50 and the
            // sortable score columns have nothing to distinguish.
            $ability = mt_rand(45, 92);

            for ($i = 0; $i < $attemptsPerStudent; $i++) {
                $examId = $exams[$i % $examCount];

                // Oldest first, roughly one attempt every three weeks.
                $startedAt = $anchor->subWeeks(3 * ($attemptsPerStudent - $i));
                $submittedAt = $startedAt->addMinutes(mt_rand(20, 55));

                // A small upward drift over time, so later attempts trend
                // better and the "improving" narrative has real data.
                $drift = (int) round(($i / max(1, $attemptsPerStudent - 1)) * 8);
                $score = max(0, min(100, $ability + $drift + mt_rand(-9, 9)));

                $correct = (int) round(50 * ($score / 100));

                // Leave the newest attempt of every twelfth student open, so
                // in_progress rows exist and attempts_started != submitted.
                $isOpen = $i === $attemptsPerStudent - 1
                    && ((int) $student->id % 12 === 0)
                    && !$student->archived_at;

                $rows[] = [
                    'exam_id' => $examId,
                    'room_id' => $roomId,
                    'user_id' => $student->id,
                    'status' => $isOpen ? 'in_progress' : 'submitted',
                    'total_items' => 50,
                    'duration_minutes' => 60,
                    'answered_count' => $isOpen ? mt_rand(1, 49) : 50,
                    'correct_answers' => $isOpen ? 0 : $correct,
                    'score_percent' => $isOpen ? null : $score,
                    'started_at' => $startedAt,
                    'expires_at' => $startedAt->addHour(),
                    'submitted_at' => $isOpen ? null : $submittedAt,
                    'created_at' => $startedAt,
                    'updated_at' => $isOpen ? $startedAt : $submittedAt,
                ];
            }

            if (count($rows) >= 5000) {
                $this->insertChunked('exam_attempts', $rows);
                $rows = [];
            }
        }

        $this->insertChunked('exam_attempts', $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows, int $chunkSize = 1000): void
    {
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function report(): void
    {
        $students = DB::table('users')
            ->where('email', 'like', '%@' . self::EMAIL_DOMAIN)
            ->where('role', User::ROLE_STUDENT);

        $enrolled = (clone $students)->whereNull('archived_at')->count();
        $archived = (clone $students)->whereNotNull('archived_at')->count();
        $attempts = DB::table('exam_attempts')
            ->join('users', 'users.id', '=', 'exam_attempts.user_id')
            ->where('users.email', 'like', '%@' . self::EMAIL_DOMAIN)
            ->count();

        $this->command?->info(sprintf(
            'Seeded: %d enrolled, %d archived, %d attempts (%d total students).',
            $enrolled,
            $archived,
            $attempts,
            $enrolled + $archived,
        ));
    }
}
