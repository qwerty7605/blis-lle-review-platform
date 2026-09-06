<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LleResult;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\Room;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Services\QuestionMappingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * A walk-through of the whole system with every state populated.
 *
 * Where MockAnalyticsDemoSeeder builds two hand-tuned students and
 * LoadTestDirectorySeeder builds volume, this one is for showing the system
 * working: every screen has something on it, and every branch a screen can take
 * has a record that reaches it. Concretely that means the awkward states as
 * well as the happy ones -- a Library upload whose mapping failed, an exam item
 * with a flagged answer key, a graduate whose board result was never recorded,
 * a review question a teacher declined, a student sitting an exam right now.
 *
 * Everything is namespaced to the `showcase.local` email domain and the
 * `SHOWCASE` code/title prefixes, and each run purges the last, so it cannot be
 * confused with demo or load-test records.
 *
 *   php artisan db:seed --class=ShowcaseSeeder
 *   SHOWCASE_PURGE_ONLY=1 php artisan db:seed --class=ShowcaseSeeder
 *
 * SHOWCASE_HISTORY_YEARS (default 10) controls how many graduating cohorts of
 * back-history to build. Set it to 0 for just the current intake.
 *
 * Sign in as any account below with the password `password`.
 */
class ShowcaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DOMAIN = 'showcase.local';
    private const PREFIX = 'SHOWCASE';
    private const STUDENT_ID_BASE = 8100000;

    /** Historical accounts number from here, well clear of the current intake. */
    private const HISTORY_STUDENT_ID_BASE = 8200000;

    /**
     * Board pass rates by cohort, oldest first, cycled if more years are asked
     * for. Real programmes do not hold a flat rate -- a curriculum change or a
     * strong batch moves it -- and a flat one would make the year-on-year
     * outcome charts look synthetic.
     *
     * @var list<float>
     */
    private const HISTORIC_PASS_RATES = [0.58, 0.64, 0.61, 0.72, 0.69, 0.78, 0.74, 0.81, 0.77, 0.85];

    /** Cohort sizes, cycled the same way. */
    private const HISTORIC_COHORT_SIZES = [22, 26, 24, 31, 28, 33, 30, 36, 34, 38];

    /** Every subject the Review Bot and the predictor know about. */
    private const SUBJECTS = [
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Information Technology',
        'Reference Services',
        'Library Management',
        'Selection and Acquisition',
    ];

    private string $password;
    private CarbonImmutable $now;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<int, Room> year level => room */
    private array $rooms = [];

    /** @var array<string, array{topic: Topic, subtopics: list<Subtopic>}> */
    private array $taxonomy = [];

    /** @var array<string, QuestionBank> subject => uploaded library file */
    private array $libraryBanks = [];

    /** @var array<string, Exam> subject => exam */
    private array $exams = [];

    /** @var list<User> */
    private array $predictionReady = [];

    public function run(): void
    {
        $this->password = Hash::make('password');
        $this->now = CarbonImmutable::now();

        $this->purge();

        if ((bool) env('SHOWCASE_PURGE_ONLY', false)) {
            $this->command?->info('Showcase data purged.');

            return;
        }

        // Deterministic: the same run produces the same numbers, so a demo can
        // be rehearsed and a screenshot stays accurate.
        mt_srand(20260906);

        $this->seedStaff();
        $this->seedTaxonomy();
        $this->seedRooms();
        $this->seedLibrary();
        $this->seedStudents();
        $this->seedLicensureResults();
        $this->seedExams();
        $this->seedExamAttempts();
        $this->seedHistory();
        $this->seedReviewBotBank();
        $this->seedReviewBotSessions();
        $this->seedAuditTrail();

        $this->report();
    }

    /**
     * Order matters: exam_attempts, review_bot_attempts and lle_results all
     * restrict on delete now, so the users row cannot go until they have.
     */
    private function purge(): void
    {
        $userIds = DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->pluck('id');
        $roomIds = DB::table('rooms')->where('code', 'like', self::PREFIX . '%')->pluck('id');
        $bankIds = DB::table('question_banks')
            ->where('title', 'like', '[' . self::PREFIX . ']%')
            // Matched by owner as well: the Review Bot banks this seeder makes
            // carry the production title prefix, so matching on title alone
            // would either miss them or delete a real teacher's bank.
            ->orWhereIn('created_by', $userIds)
            ->pluck('id');
        $examIds = DB::table('exams')
            ->where('title', 'like', '[' . self::PREFIX . ']%')
            ->orWhereIn('created_by', $userIds)
            ->pluck('id');

        if ($userIds->isNotEmpty()) {
            $attemptIds = DB::table('exam_attempts')->whereIn('user_id', $userIds)->pluck('id');
            DB::table('exam_attempt_answers')->whereIn('exam_attempt_id', $attemptIds)->delete();
            DB::table('exam_attempt_questions')->whereIn('exam_attempt_id', $attemptIds)->delete();
            DB::table('exam_attempts')->whereIn('id', $attemptIds)->delete();

            $reviewIds = DB::table('review_bot_attempts')->whereIn('user_id', $userIds)->pluck('id');
            DB::table('review_bot_attempt_answers')->whereIn('review_bot_attempt_id', $reviewIds)->delete();
            DB::table('review_bot_attempt_questions')->whereIn('review_bot_attempt_id', $reviewIds)->delete();
            DB::table('review_bot_attempts')->whereIn('id', $reviewIds)->delete();

            DB::table('review_bot_item_states')->whereIn('user_id', $userIds)->delete();
            DB::table('lle_results')->whereIn('user_id', $userIds)->delete();
            DB::table('audit_logs')->whereIn('actor_id', $userIds)->delete();
            DB::table('room_user')->whereIn('user_id', $userIds)->delete();
        }

        if ($examIds->isNotEmpty()) {
            DB::table('exam_room_pacing_states')->whereIn('exam_id', $examIds)->delete();
            DB::table('exam_room')->whereIn('exam_id', $examIds)->delete();
            DB::table('exam_question_bank')->whereIn('exam_id', $examIds)->delete();
            DB::table('exams')->whereIn('id', $examIds)->delete();
        }

        if ($roomIds->isNotEmpty()) {
            DB::table('exam_room')->whereIn('room_id', $roomIds)->delete();
            DB::table('room_user')->whereIn('room_id', $roomIds)->delete();
            DB::table('rooms')->whereIn('id', $roomIds)->delete();
        }

        if ($bankIds->isNotEmpty()) {
            $questionIds = DB::table('question_bank_questions')->whereIn('question_bank_id', $bankIds)->pluck('id');
            // Review Bot variants point back at their source item, so they have
            // to go before the items they descend from.
            DB::table('question_bank_questions')->whereIn('source_question_id', $questionIds)->update(['source_question_id' => null]);
            DB::table('question_bank_options')->whereIn('question_bank_question_id', $questionIds)->delete();
            DB::table('question_bank_questions')->whereIn('id', $questionIds)->delete();
            DB::table('question_banks')->whereIn('id', $bankIds)->delete();
        }

        if ($userIds->isNotEmpty()) {
            DB::table('users')->whereIn('id', $userIds)->delete();
        }

        DB::table('topics')->where('name', 'like', '%')->whereIn('subject', self::SUBJECTS)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('question_bank_questions')
                ->whereColumn('question_bank_questions.topic_id', 'topics.id'))
            ->delete();
    }

    // ------------------------------------------------------------------ staff

    private function seedStaff(): void
    {
        $this->staff['admin'] = $this->makeUser('admin', 'Showcase Admin', User::ROLE_ADMIN);

        // Two examiners so the Library shows files owned by different people
        // and "my uploads" is a meaningful distinction.
        $this->staff['examiner'] = $this->makeUser('examiner', 'Divine Reyes', User::ROLE_STAFF_MASTER_EXAMINER);
        $this->staff['examiner2'] = $this->makeUser('examiner2', 'Marco Villanueva', User::ROLE_STAFF_MASTER_EXAMINER);

        // An account someone has disabled -- the login guard has a branch for it.
        $this->makeUser('inactive-staff', 'Suspended Examiner', User::ROLE_STAFF_MASTER_EXAMINER, ['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUser(string $handle, string $name, string $role, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'name' => $name,
            'email' => $handle . '@' . self::DOMAIN,
            'role' => $role,
            'is_active' => true,
            'password' => $this->password,
        ], $attributes));
    }

    // --------------------------------------------------------------- taxonomy

    private function seedTaxonomy(): void
    {
        $mapping = app(QuestionMappingService::class);

        foreach (self::SUBJECTS as $subject) {
            foreach ($mapping->curatedTopicsForSubject($subject) as $topicName) {
                $topic = Topic::query()->firstOrCreate(
                    ['subject' => $subject, 'name' => $topicName],
                    ['created_by' => $this->staff['examiner']->id],
                );

                $subtopics = [];

                foreach ($mapping->curatedSubtopicsForTopic($subject, $topicName) as $subtopicName) {
                    $subtopics[] = Subtopic::query()->firstOrCreate(
                        ['topic_id' => $topic->id, 'name' => $subtopicName],
                        ['created_by' => $this->staff['examiner']->id],
                    );
                }

                // Keyed by subject so a question can be given a matching pair.
                $this->taxonomy[$subject] ??= [];
                $this->taxonomy[$subject][] = ['topic' => $topic, 'subtopics' => $subtopics];
            }
        }
    }

    // ------------------------------------------------------------------ rooms

    private function seedRooms(): void
    {
        foreach ([1, 2, 3, 4] as $yearLevel) {
            $this->rooms[$yearLevel] = Room::query()->create([
                'name' => "BLIS {$yearLevel}A",
                'code' => self::PREFIX . 'R' . $yearLevel,
                'created_by' => $this->staff['examiner']->id,
                'created_at' => $this->now->subYears(4 - $yearLevel + 1),
                'updated_at' => $this->now,
            ]);
        }

        // An archived section from a previous cycle: currentStudentMembers()
        // and the currentRooms relation both have to exclude it.
        Room::query()->create([
            'name' => 'BLIS 4A (AY 2024-2025)',
            'code' => self::PREFIX . 'RARC',
            'created_by' => $this->staff['examiner']->id,
            'archived_at' => $this->now->subMonths(8),
            'created_at' => $this->now->subYears(2),
            'updated_at' => $this->now->subMonths(8),
        ]);
    }

    // ---------------------------------------------------------------- library

    /**
     * One uploaded file per subject, plus files parked in every mapping state
     * the Library can display.
     */
    private function seedLibrary(): void
    {
        $states = [
            QuestionBank::MAPPING_STATUS_COMPLETED,
            QuestionBank::MAPPING_STATUS_COMPLETED,
            QuestionBank::MAPPING_STATUS_COMPLETED,
            QuestionBank::MAPPING_STATUS_QUEUED,
            QuestionBank::MAPPING_STATUS_PROCESSING,
            QuestionBank::MAPPING_STATUS_FAILED,
        ];

        foreach (self::SUBJECTS as $index => $subject) {
            $status = $states[$index] ?? QuestionBank::MAPPING_STATUS_COMPLETED;
            $owner = $index % 3 === 2 ? $this->staff['examiner2'] : $this->staff['examiner'];

            $bank = QuestionBank::query()->create([
                'title' => '[' . self::PREFIX . '] ' . $subject . ' Mock Set',
                'subject' => $subject,
                'source_filename' => strtoupper(str_replace(' ', '-', $subject)) . '.docx',
                'total_items' => 10,
                'mapping_status' => $status,
                'mapping_mode' => QuestionBank::MAPPING_MODE_AUTO,
                'mapping_message' => $status === QuestionBank::MAPPING_STATUS_FAILED
                    ? 'The mapping run stopped after repeated timeouts from the provider.'
                    : null,
                'mapping_requested_at' => $this->now->subDays(6),
                'mapping_completed_at' => $status === QuestionBank::MAPPING_STATUS_COMPLETED
                    ? $this->now->subDays(6)->addMinutes(4)
                    : null,
                'created_by' => $owner->id,
                'created_at' => $this->now->subDays(7 - $index),
                'updated_at' => $this->now->subDays(6),
            ]);

            $this->libraryBanks[$subject] = $bank;
            $this->seedAssessmentItems($bank, $subject, $status);
        }

        // A file nobody has mapped yet -- the "Map Items with AI" call to action.
        $idle = QuestionBank::query()->create([
            'title' => '[' . self::PREFIX . '] Unmapped Archival Studies Set',
            'subject' => 'Archival Studies',
            'source_filename' => 'ARCHIVAL-STUDIES.docx',
            'total_items' => 4,
            'mapping_status' => QuestionBank::MAPPING_STATUS_IDLE,
            'created_by' => $this->staff['examiner']->id,
            'created_at' => $this->now->subDay(),
            'updated_at' => $this->now->subDay(),
        ]);

        $this->seedAssessmentItems($idle, 'Archival Studies', QuestionBank::MAPPING_STATUS_IDLE, 4);
    }

    private function seedAssessmentItems(QuestionBank $bank, string $subject, string $mappingStatus, int $count = 10): void
    {
        $mapped = $mappingStatus === QuestionBank::MAPPING_STATUS_COMPLETED;
        $pairs = $this->taxonomy[$subject] ?? [];

        for ($item = 1; $item <= $count; $item++) {
            $pair = $pairs === [] ? null : $pairs[($item - 1) % count($pairs)];
            $subtopics = $pair['subtopics'] ?? [];

            // Item 4 of every mapped file is left untagged on purpose: the
            // Library flags partially mapped files, and the Review Bot's
            // coverage report marks untagged items separately.
            $isTagged = $mapped && $item !== 4;

            // Item 7 carries a flagged answer key -- the state the Library
            // surfaces so a teacher can correct it before it seeds review
            // questions from a wrong answer.
            $flagged = $mapped && $item === 7;

            $question = QuestionBankQuestion::query()->create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => $subject,
                'topic_id' => $isTagged ? $pair['topic']->id : null,
                'subtopic_id' => $isTagged && $subtopics !== [] ? $subtopics[0]->id : null,
                'difficulty' => [
                    QuestionBankQuestion::DIFFICULTY_EASY,
                    QuestionBankQuestion::DIFFICULTY_MEDIUM,
                    QuestionBankQuestion::DIFFICULTY_HARD,
                ][$item % 3],
                'tag_status' => $isTagged
                    ? QuestionBankQuestion::TAG_STATUS_CONFIRMED
                    : QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW,
                'tag_source' => $isTagged ? QuestionBankQuestion::TAG_SOURCE_AI : null,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'item_number' => $item,
                'question_text' => sprintf('%s — exam item %d: which statement is correct?', $subject, $item),
                'question_type' => $item === 9
                    ? QuestionBankQuestion::TYPE_TRUE_FALSE
                    : QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_key_flagged' => $flagged,
                'answer_key_note' => $flagged
                    ? 'Students overwhelmingly chose C. The marked answer A may be wrong — please review.'
                    : null,
                'answer_key_checked_at' => $flagged ? $this->now->subDays(3) : null,
                'created_at' => $bank->created_at,
                'updated_at' => $bank->updated_at,
            ]);

            $this->attachOptions($question, $question->question_type);
        }
    }

    private function attachOptions(QuestionBankQuestion $question, string $type): void
    {
        $labels = $type === QuestionBankQuestion::TYPE_TRUE_FALSE ? ['A', 'B'] : ['A', 'B', 'C', 'D'];
        $texts = $type === QuestionBankQuestion::TYPE_TRUE_FALSE
            ? ['True', 'False']
            : ['The first option', 'The second option', 'The third option', 'The fourth option'];

        foreach ($labels as $index => $label) {
            DB::table('question_bank_options')->insert([
                'question_bank_question_id' => $question->id,
                'sort_order' => $index,
                'option_label' => $label,
                'option_text' => $texts[$index],
                'is_correct' => $label === 'A',
                'created_at' => $this->now,
                'updated_at' => $this->now,
            ]);
        }
    }

    // --------------------------------------------------------------- students

    private function seedStudents(): void
    {
        $currentYear = (int) $this->now->format('Y');
        $sequence = 0;

        // Enrolled, one cohort per year level.
        foreach ([1 => 8, 2 => 8, 3 => 8, 4 => 10] as $yearLevel => $count) {
            for ($i = 0; $i < $count; $i++) {
                $student = $this->makeStudent(
                    $sequence++,
                    $yearLevel,
                    $currentYear - ($yearLevel - 1),
                    User::STUDENT_STATUS_ENROLLED,
                );

                $student->rooms()->syncWithoutDetaching([$this->rooms[$yearLevel]->id]);

                // The fourth years are the ones with a full exam history, so
                // they are the ones the LET predictor can score.
                if ($yearLevel === 4 && $i < 4) {
                    $this->predictionReady[] = $student;
                }
            }
        }

        // Irregular students sitting beyond the standard ladder -- the case the
        // old 1-4 year level enum could not represent at all.
        foreach ([5, 6] as $index => $yearLevel) {
            $student = $this->makeStudent(
                $sequence++,
                $yearLevel,
                $currentYear - 5,
                User::STUDENT_STATUS_ENROLLED,
                ['name' => 'Irregular Student ' . ($index + 1)],
            );
            $student->rooms()->syncWithoutDetaching([$this->rooms[4]->id]);
        }

        // Graduates: the cohort the predictor is eventually retrained on.
        for ($i = 0; $i < 8; $i++) {
            $this->makeStudent($sequence++, null, $currentYear - 4, User::STUDENT_STATUS_GRADUATED, [
                'name' => 'Graduate ' . ($i + 1),
            ]);
        }

        // Everyone who left for a reason that is NOT a board-exam failure.
        // Keeping these apart is the whole point of the status field.
        for ($i = 0; $i < 3; $i++) {
            $this->makeStudent($sequence++, null, $currentYear - 3, User::STUDENT_STATUS_DROPPED, [
                'name' => 'Dropped Student ' . ($i + 1),
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->makeStudent($sequence++, null, $currentYear - 2, User::STUDENT_STATUS_TRANSFERRED, [
                'name' => 'Transferred Student ' . ($i + 1),
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            $this->makeStudent($sequence++, 3, $currentYear - 2, User::STUDENT_STATUS_ON_LEAVE, [
                'name' => 'On Leave Student ' . ($i + 1),
            ]);
        }

        // Archived before anyone recorded why -- the backlog the Students tab
        // surfaces as "Reason not recorded" for a human to classify.
        for ($i = 0; $i < 3; $i++) {
            $this->makeStudent($sequence++, null, $currentYear - 5, User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED, [
                'name' => 'Unclassified Leaver ' . ($i + 1),
            ]);
        }

        // A disabled account: still enrolled, but blocked at sign-in.
        $this->makeStudent($sequence++, 2, $currentYear - 1, User::STUDENT_STATUS_ENROLLED, [
            'name' => 'Deactivated Student',
            'is_active' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeStudent(
        int $sequence,
        ?int $yearLevel,
        int $admissionYear,
        string $status,
        array $attributes = [],
    ): User {
        $student = new User(array_merge([
            'name' => 'Student ' . str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
            'email' => 'student' . $sequence . '@' . self::DOMAIN,
            'student_id' => (string) (self::STUDENT_ID_BASE + $sequence),
            'role' => User::ROLE_STUDENT,
            'year_level' => $yearLevel,
            'admission_year' => $admissionYear,
            'is_active' => true,
            'password' => $this->password,
        ], $attributes));

        // Sets archived_at alongside the status so the two cannot disagree.
        $student->applyStudentStatus($status);
        $student->created_at = $this->now->subYears((int) $this->now->format('Y') - $admissionYear);
        $student->save();

        return $student;
    }

    // -------------------------------------------------------------- licensure

    /**
     * Every shape a board result comes in, including the absence of one.
     */
    private function seedLicensureResults(): void
    {
        $graduates = User::query()
            ->where('email', 'like', '%@' . self::DOMAIN)
            ->where('student_status', User::STUDENT_STATUS_GRADUATED)
            ->orderBy('id')
            ->get();

        $examYear = (int) $this->now->format('Y') - 1;
        $recorder = $this->staff['examiner']->id;

        foreach ($graduates as $index => $graduate) {
            // The last two are left with no result at all: a graduate whose
            // outcome was never recorded is unusable as training data, and the
            // Licensure screen exists to close exactly that gap.
            if ($index >= count($graduates) - 2) {
                continue;
            }

            $passed = $index % 3 !== 2;

            LleResult::query()->create([
                'user_id' => $graduate->id,
                'exam_year' => $examYear,
                'taken_at' => CarbonImmutable::create($examYear, 9, 15),
                'result' => $passed ? LleResult::RESULT_PASS : LleResult::RESULT_FAIL,
                // One passer has no published general average, which has to
                // read as "not recorded" rather than as a zero.
                'rating' => $passed && $index !== 1 ? round(mt_rand(7550, 8900) / 100, 2) : null,
                'remarks' => $passed ? null : 'Below the 75% general average.',
                'recorded_by' => $recorder,
            ]);

            // A retake the following year: the same student, a second sitting,
            // which is why the unique key is (user, exam_year) and not (user).
            if (!$passed) {
                LleResult::query()->create([
                    'user_id' => $graduate->id,
                    'exam_year' => $examYear + 1,
                    'taken_at' => CarbonImmutable::create($examYear + 1, 9, 14),
                    'result' => LleResult::RESULT_PASS,
                    'rating' => 78.25,
                    'remarks' => 'Passed on retake.',
                    'recorded_by' => $recorder,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ exams

    private function seedExams(): void
    {
        foreach (self::SUBJECTS as $index => $subject) {
            $bank = $this->libraryBanks[$subject];

            // A spread of schedules so the exam list shows a closed exam, one
            // open right now, and one still upcoming.
            // All six are open or already closed: LetReadinessFeatureBuilder
            // needs an average in every subject before it will score a student,
            // so a subject with no sittings silently disables the predictor.
            // The upcoming-exam state gets its own extra exam below instead.
            $window = $index === 4
                ? ['start' => $this->now->subHours(2), 'end' => $this->now->addHours(6)]
                : ['start' => $this->now->subDays(20 - $index), 'end' => $this->now->subDays(13 - $index)];

            $exam = Exam::query()->create([
                'title' => '[' . self::PREFIX . '] ' . $subject . ' Mock Exam',
                'subject' => $subject,
                'description' => 'Mock board exam covering ' . $subject . '.',
                'question_bank_id' => $bank->id,
                'total_items' => 10,
                'duration_minutes' => 60,
                'scheduled_at' => $window['start'],
                'schedule_start_at' => $window['start'],
                'schedule_end_at' => $window['end'],
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                // Results hidden on half of them: the student result page and
                // the live board footer both branch on this.
                'results_visibility_mode' => $index % 2 === 0 ? 'visible' : 'hidden',
                'one_take_only' => $index === 3,
                'shuffle_questions' => $index % 2 === 1,
                'created_by' => $bank->created_by,
                'created_at' => $window['start']->subDays(3),
                'updated_at' => $window['start']->subDays(3),
            ]);

            $exam->syncQuestionBanks([$bank->id]);
            $exam->rooms()->syncWithoutDetaching([
                $this->rooms[4]->id => ['assigned_by' => $bank->created_by],
            ]);

            $this->exams[$subject] = $exam;
        }

        // A scheduled-but-not-yet-open exam, so the student exam list shows the
        // upcoming state. Kept as a separate exam rather than by holding one
        // subject back, which would starve the predictor.
        $upcomingSubject = self::SUBJECTS[5];
        $upcomingBank = $this->libraryBanks[$upcomingSubject];

        $upcoming = Exam::query()->create([
            'title' => '[' . self::PREFIX . '] ' . $upcomingSubject . ' Final Mock (upcoming)',
            'subject' => $upcomingSubject,
            'description' => 'Scheduled mock exam that has not opened yet.',
            'question_bank_id' => $upcomingBank->id,
            'total_items' => 10,
            'duration_minutes' => 90,
            'scheduled_at' => $this->now->addDays(5),
            'schedule_start_at' => $this->now->addDays(5),
            'schedule_end_at' => $this->now->addDays(7),
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'results_visibility_mode' => 'hidden',
            'one_take_only' => true,
            'shuffle_questions' => true,
            'created_by' => $upcomingBank->created_by,
            'created_at' => $this->now->subDay(),
            'updated_at' => $this->now->subDay(),
        ]);

        $upcoming->syncQuestionBanks([$upcomingBank->id]);
        $upcoming->rooms()->syncWithoutDetaching([
            $this->rooms[4]->id => ['assigned_by' => $upcomingBank->created_by],
        ]);

        // An assignment a teacher has since archived, and a live pacing state
        // for the exam that is currently open.
        $archivedTarget = $this->exams[self::SUBJECTS[0]];
        $archivedTarget->rooms()->syncWithoutDetaching([
            $this->rooms[3]->id => [
                'assigned_by' => $this->staff['examiner']->id,
                'archived_at' => $this->now->subDays(5),
                'archived_by' => $this->staff['examiner']->id,
            ],
        ]);

        DB::table('exam_room_pacing_states')->insert([
            'exam_id' => $this->exams[self::SUBJECTS[4]]->id,
            'room_id' => $this->rooms[4]->id,
            'is_active' => true,
            'current_item_number' => 4,
            'started_at' => $this->now->subHours(1),
            'updated_by' => $this->staff['examiner']->id,
            'created_at' => $this->now->subHours(1),
            'updated_at' => $this->now->subMinutes(10),
        ]);
    }

    // ----------------------------------------------------------- exam history

    private function seedExamAttempts(): void
    {
        $fourthYears = User::query()
            ->where('email', 'like', '%@' . self::DOMAIN)
            ->where('student_status', User::STUDENT_STATUS_ENROLLED)
            ->where('year_level', '>=', 4)
            ->orderBy('id')
            ->get();

        $predictionIds = collect($this->predictionReady)->pluck('id')->all();

        foreach ($fourthYears as $position => $student) {
            $isPredictionReady = in_array($student->id, $predictionIds, true);

            // Prediction needs at least 8 scored attempts AND an average in
            // every one of the six subjects, so these students sit all six --
            // twice over, which also exercises the latest-attempt-per-session
            // rule that the analytics rely on.
            $subjects = $isPredictionReady ? array_merge(self::SUBJECTS, self::SUBJECTS) : array_slice(self::SUBJECTS, 0, 2);

            // A distinct ability per student so scores cluster rather than
            // being uniform noise, and the pass/fail split is meaningful.
            $ability = 52 + (($position * 7) % 40);

            foreach ($subjects as $round => $subject) {
                $exam = $this->exams[$subject];

                $opensAt = CarbonImmutable::instance(\Carbon\Carbon::instance($exam->schedule_start_at));

                if ($opensAt->greaterThan($this->now)) {
                    continue;
                }

                $isRetake = $round >= count(self::SUBJECTS);
                $drift = $isRetake ? 6 : 0;
                $score = max(5, min(100, $ability + $drift + mt_rand(-8, 8)));

                $this->seedAttempt(
                    $student,
                    $exam,
                    ExamAttempt::STATUS_SUBMITTED,
                    $score,
                    $opensAt->addHours($isRetake ? 30 : 1),
                );
            }
        }

        // One student sitting the open exam right now, so the live board has
        // something in progress to show.
        $liveExam = $this->exams[self::SUBJECTS[4]];
        $liveStudent = $fourthYears->first();

        if ($liveStudent) {
            $this->seedAttempt($liveStudent, $liveExam, ExamAttempt::STATUS_IN_PROGRESS, null, $this->now->subMinutes(25));
        }
    }

    private function seedAttempt(User $student, Exam $exam, string $status, ?int $score, \DateTimeInterface $startedAt): void
    {
        // Model date casts hand back a mutable Carbon, and every ->addMinutes()
        // below would shift the shared instance rather than derive a new one.
        $startedAt = CarbonImmutable::instance(\Carbon\Carbon::instance($startedAt));

        $questions = QuestionBankQuestion::query()
            ->where('question_bank_id', $exam->question_bank_id)
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->orderBy('item_number')
            ->with('options')
            ->get();

        if ($questions->isEmpty()) {
            return;
        }

        $submitted = $status === ExamAttempt::STATUS_SUBMITTED;
        $total = $questions->count();
        $correctTarget = $submitted ? (int) round($total * ($score / 100)) : 0;
        // Half answered on a live attempt, so the board shows real progress.
        $answerCount = $submitted ? $total : (int) ceil($total / 2);

        $attempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'room_id' => $this->rooms[4]->id,
            'user_id' => $student->id,
            'status' => $status,
            'total_items' => $total,
            'duration_minutes' => (int) $exam->duration_minutes,
            'answered_count' => $answerCount,
            'correct_answers' => $correctTarget,
            'score_percent' => $submitted ? $score : null,
            'started_at' => $startedAt,
            'expires_at' => $startedAt->addMinutes((int) $exam->duration_minutes),
            'submitted_at' => $submitted ? $startedAt->addMinutes(mt_rand(18, 50)) : null,
        ]);

        foreach ($questions->values() as $index => $question) {
            DB::table('exam_attempt_questions')->insert([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $index + 1,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            if ($index >= $answerCount) {
                continue;
            }

            $isCorrect = $index < $correctTarget;
            $option = $question->options->first(fn ($o) => (bool) $o->is_correct === $isCorrect)
                ?? $question->options->first();

            DB::table('exam_attempt_answers')->insert([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'question_bank_option_id' => $option?->id,
                'answer_text' => null,
                'is_correct' => $isCorrect,
                'answered_at' => $startedAt->addMinutes($index + 1),
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
        }
    }

    // -------------------------------------------------------- review bot bank

    /**
     * Review questions in every state a teacher can put them in, with the
     * cognitive ladder deliberately uneven so the coverage report has complete,
     * partly covered and empty items to show.
     */
    private function seedReviewBotBank(): void
    {
        foreach (self::SUBJECTS as $subjectIndex => $subject) {
            $bank = QuestionBank::query()->create([
                'title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . $subject,
                'subject' => $subject,
                'total_items' => 0,
                'mapping_status' => QuestionBank::MAPPING_STATUS_COMPLETED,
                'created_by' => $this->staff['examiner']->id,
                'created_at' => $this->now->subDays(5),
                'updated_at' => $this->now->subDays(2),
            ]);

            $sources = QuestionBankQuestion::query()
                ->where('question_bank_id', $this->libraryBanks[$subject]->id)
                ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
                ->orderBy('item_number')
                ->get();

            $itemNumber = 0;

            foreach ($sources as $sourceIndex => $source) {
                // Items 8 and 10 are left with nothing at all, so the coverage
                // report shows the "produced nothing yet" case.
                if (in_array($source->item_number, [8, 10], true)) {
                    continue;
                }

                // A full ladder on the first item, thinning out after, so
                // "complete" and "partly covered" both appear.
                $levels = match (true) {
                    $source->item_number === 1 => QuestionBankQuestion::COGNITIVE_LEVELS,
                    $source->item_number <= 3 => ['recall', 'comprehension', 'application'],
                    $source->item_number <= 6 => ['recall', 'comprehension'],
                    default => ['recall'],
                };

                foreach ($levels as $levelIndex => $level) {
                    $itemNumber++;

                    // The first three subjects are published so students have a
                    // working Review Bot; the rest sit as drafts awaiting review.
                    $status = match (true) {
                        $source->item_number === 5 && $levelIndex === 1 => QuestionBankQuestion::STATUS_REJECTED,
                        $subjectIndex < 3 => QuestionBankQuestion::STATUS_PUBLISHED,
                        default => QuestionBankQuestion::STATUS_DRAFT,
                    };

                    $flags = [];
                    $verification = QuestionBankQuestion::VERIFICATION_AGREED;

                    // One question per subject carries a screening flag and a
                    // disagreeing verifier -- the states the review queue
                    // renders as warnings.
                    if ($source->item_number === 2 && $levelIndex === 0) {
                        $flags = ['near_duplicate'];
                        $verification = QuestionBankQuestion::VERIFICATION_DISAGREED;
                    }

                    $question = QuestionBankQuestion::query()->create([
                        'question_bank_id' => $bank->id,
                        'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                        'source_question_id' => $source->id,
                        'subject' => $subject,
                        'topic_id' => $source->topic_id,
                        'subtopic_id' => $source->subtopic_id,
                        'difficulty' => [
                            QuestionBankQuestion::DIFFICULTY_EASY,
                            QuestionBankQuestion::DIFFICULTY_MEDIUM,
                            QuestionBankQuestion::DIFFICULTY_HARD,
                        ][$levelIndex % 3],
                        'cognitive_level' => $level,
                        'explanation' => 'The first option is correct because it matches the definition given in the source material.',
                        'status' => $status,
                        'verification_status' => $verification,
                        'verifier_answer_label' => $verification === QuestionBankQuestion::VERIFICATION_DISAGREED ? 'C' : 'A',
                        'validation_flags' => $flags === [] ? null : $flags,
                        'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
                        'tag_source' => QuestionBankQuestion::TAG_SOURCE_AI,
                        'item_number' => $itemNumber,
                        'question_text' => sprintf(
                            '%s (%s): review question generated from exam item %d.',
                            $subject,
                            $level,
                            $source->item_number,
                        ),
                        'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                        'answer_label' => 'A',
                        'created_at' => $this->now->subDays(5)->addMinutes($itemNumber),
                        'updated_at' => $this->now->subDays(2),
                    ]);

                    $this->attachOptions($question, QuestionBankQuestion::TYPE_MULTIPLE_CHOICE);
                }
            }

            $bank->update(['total_items' => $itemNumber]);
        }
    }

    // ---------------------------------------------------- review bot sessions

    /**
     * Student-side Review Bot history, with mastery states spread across every
     * bucket the adaptive queue reads: weak, learning and mastered. Items with
     * no row at all are the fourth bucket, unseen.
     */
    private function seedReviewBotSessions(): void
    {
        $students = User::query()
            ->where('email', 'like', '%@' . self::DOMAIN)
            ->where('student_status', User::STUDENT_STATUS_ENROLLED)
            ->whereNotNull('year_level')
            ->orderBy('id')
            ->limit(12)
            ->get();

        $published = QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->with('options')
            ->orderBy('id')
            ->get();

        if ($published->isEmpty()) {
            return;
        }

        foreach ($students as $position => $student) {
            $sessionCount = $position % 4;

            for ($session = 0; $session < $sessionCount; $session++) {
                $pool = $published->slice(($session * 5) % max(1, $published->count() - 5), 5)->values();

                if ($pool->isEmpty()) {
                    continue;
                }

                $startedAt = $this->now->subDays(($sessionCount - $session) * 3);
                $correct = mt_rand(1, $pool->count());

                $attempt = ReviewBotAttempt::query()->create([
                    'user_id' => $student->id,
                    'subject' => $pool->first()->subject,
                    'topic_id' => $pool->first()->topic_id,
                    'subtopic_id' => $pool->first()->subtopic_id,
                    'difficulty' => null,
                    'source' => $session % 2 === 0
                        ? ReviewBotAttempt::SOURCE_MANUAL
                        : ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA,
                    'status' => ReviewBotAttempt::STATUS_SUBMITTED,
                    'total_items' => $pool->count(),
                    'answered_count' => $pool->count(),
                    'correct_answers' => $correct,
                    'score_percent' => round(($correct / $pool->count()) * 100, 2),
                    'started_at' => $startedAt,
                    'submitted_at' => $startedAt->addMinutes(9),
                ]);

                foreach ($pool as $index => $question) {
                    $isCorrect = $index < $correct;

                    DB::table('review_bot_attempt_questions')->insert([
                        'review_bot_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $question->id,
                        'item_number' => $index + 1,
                        'created_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ]);

                    $option = $question->options->first(fn ($o) => (bool) $o->is_correct === $isCorrect)
                        ?? $question->options->first();

                    DB::table('review_bot_attempt_answers')->insert([
                        'review_bot_attempt_id' => $attempt->id,
                        'question_bank_question_id' => $question->id,
                        'question_bank_option_id' => $option?->id,
                        'answer_text' => null,
                        'is_correct' => $isCorrect,
                        'answered_at' => $startedAt->addMinutes($index + 1),
                        'created_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ]);

                    // consecutive_correct is what buckets the item: 0 is weak,
                    // 1 is learning, 2 or more is mastered and drops out of
                    // rotation. Spread across all three on purpose.
                    $streak = $isCorrect ? ($index % 3) + 1 : 0;

                    DB::table('review_bot_item_states')->updateOrInsert(
                        [
                            'user_id' => $student->id,
                            'question_bank_question_id' => $question->id,
                        ],
                        [
                            'times_seen' => $session + 1,
                            'times_correct' => $isCorrect ? $session + 1 : 0,
                            'consecutive_correct' => $streak,
                            'last_seen_at' => $startedAt,
                            'created_at' => $startedAt,
                            'updated_at' => $startedAt,
                        ],
                    );
                }
            }
        }

        // One session left open, so the "resume where you left off" path has a
        // record behind it.
        $resumeStudent = $students->first();

        if ($resumeStudent) {
            $pool = $published->take(5);

            $attempt = ReviewBotAttempt::query()->create([
                'user_id' => $resumeStudent->id,
                'subject' => $pool->first()->subject,
                'topic_id' => $pool->first()->topic_id,
                'subtopic_id' => $pool->first()->subtopic_id,
                'source' => ReviewBotAttempt::SOURCE_MANUAL,
                'status' => ReviewBotAttempt::STATUS_IN_PROGRESS,
                'total_items' => $pool->count(),
                'answered_count' => 2,
                'correct_answers' => 0,
                'score_percent' => null,
                'started_at' => $this->now->subMinutes(12),
            ]);

            foreach ($pool->values() as $index => $question) {
                DB::table('review_bot_attempt_questions')->insert([
                    'review_bot_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                    'item_number' => $index + 1,
                    'created_at' => $this->now->subMinutes(12),
                    'updated_at' => $this->now->subMinutes(12),
                ]);
            }
        }
    }

    // ---------------------------------------------------------------- history

    /**
     * Back-history: one graduating cohort per year, each with the exam record
     * they built over four years and the board result they eventually got.
     *
     * This is what makes the retraining story real. LetPredictionService was
     * trained on a single hand-labelled batch and its docblock asks for more;
     * a decade of cohorts, each with a full feature history and a recorded
     * outcome, is the shape that data has to arrive in. It is also the only way
     * the archive views have anything in them -- rooms from closed academic
     * years, exams nobody will sit again, alumni who left years ago.
     *
     * Written with bulk inserts rather than Eloquent: at ten cohorts this is
     * tens of thousands of answer rows, and per-model saves would turn a
     * seconds-long seed into a minutes-long one.
     */
    private function seedHistory(): void
    {
        $years = max(0, (int) env('SHOWCASE_HISTORY_YEARS', 10));

        if ($years === 0) {
            return;
        }

        $currentYear = (int) $this->now->format('Y');
        $sequence = 0;

        $this->command?->info("Building {$years} year(s) of cohort history...");

        for ($offset = $years; $offset >= 1; $offset--) {
            $graduationYear = $currentYear - $offset;
            $admissionYear = $graduationYear - 4;
            $index = ($years - $offset) % count(self::HISTORIC_COHORT_SIZES);

            $size = self::HISTORIC_COHORT_SIZES[$index];
            $passRate = self::HISTORIC_PASS_RATES[$index];

            $room = $this->seedHistoricRoom($graduationYear);
            $exams = $this->seedHistoricExams($graduationYear, $room);
            $cohort = $this->seedHistoricCohort($graduationYear, $admissionYear, $size, $sequence, $room);
            $sequence += $size;

            $this->seedHistoricAttempts($cohort, $exams, $admissionYear);
            $this->seedHistoricResults($cohort, $graduationYear, $passRate);
        }
    }

    private function seedHistoricRoom(int $graduationYear): Room
    {
        $closedAt = CarbonImmutable::create($graduationYear, 6, 30);

        return Room::query()->create([
            'name' => sprintf('BLIS 4A (AY %d-%d)', $graduationYear - 1, $graduationYear),
            // rooms.code is capped at 12 characters, and the 8-character
            // prefix plus a 4-digit year uses exactly that.
            'code' => self::PREFIX . $graduationYear,
            'created_by' => $this->staff['examiner']->id,
            // Archived the moment the cohort graduated, which is what keeps
            // them out of currentStudentMembers() and the room_count column.
            'archived_at' => $closedAt,
            'created_at' => $closedAt->subYears(4),
            'updated_at' => $closedAt,
        ]);
    }

    /**
     * @return array<string, int> subject => exam id
     */
    private function seedHistoricExams(int $graduationYear, Room $room): array
    {
        $rows = [];
        $pivot = [];
        $exams = [];

        foreach (self::SUBJECTS as $index => $subject) {
            // Spread across the cohort's four years, so a student's attempt
            // history has a real chronology for the semester bucketing to
            // split rather than everything landing on one date.
            $sat = CarbonImmutable::create($graduationYear - 3, 1, 1)->addMonths($index * 8);

            $exam = Exam::query()->create([
                'title' => sprintf('[%s] %s Mock (AY %d)', self::PREFIX, $subject, $graduationYear),
                'subject' => $subject,
                'description' => 'Historical mock exam retained for records.',
                'question_bank_id' => $this->libraryBanks[$subject]->id,
                'total_items' => 10,
                'duration_minutes' => 60,
                'scheduled_at' => $sat,
                'schedule_start_at' => $sat,
                'schedule_end_at' => $sat->addDays(2),
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                'results_visibility_mode' => 'visible',
                'one_take_only' => false,
                'shuffle_questions' => false,
                'created_by' => $this->staff['examiner']->id,
                'created_at' => $sat->subWeek(),
                'updated_at' => $sat,
            ]);

            $pivot[] = [
                'exam_id' => $exam->id,
                'room_id' => $room->id,
                'assigned_by' => $this->staff['examiner']->id,
                'archived_at' => CarbonImmutable::create($graduationYear, 6, 30),
                'archived_by' => $this->staff['examiner']->id,
                'created_at' => $sat->subWeek(),
                'updated_at' => $sat,
            ];

            $exams[$subject] = ['id' => (int) $exam->id, 'sat_at' => $sat];
        }

        DB::table('exam_room')->insert($pivot);

        return $exams;
    }

    /**
     * @return \Illuminate\Support\Collection<int, \stdClass>
     */
    private function seedHistoricCohort(int $graduationYear, int $admissionYear, int $size, int $sequence, Room $room)
    {
        $enrolledAt = CarbonImmutable::create($admissionYear, 8, 1);
        $leftAt = CarbonImmutable::create($graduationYear, 6, 30);
        $rows = [];

        for ($i = 0; $i < $size; $i++) {
            $n = $sequence + $i;

            // Roughly one in nine leaves before finishing. They are dropouts,
            // not board-exam failures -- keeping the two apart is the whole
            // reason student_status exists, and mixing them would poison any
            // model trained on this data.
            $dropped = $i % 9 === 8;

            $rows[] = [
                'name' => sprintf('Alumnus %d-%02d', $graduationYear, $i + 1),
                'email' => sprintf('alum%d-%d@%s', $graduationYear, $n, self::DOMAIN),
                'student_id' => (string) (self::HISTORY_STUDENT_ID_BASE + $n),
                'role' => User::ROLE_STUDENT,
                'year_level' => null,
                'student_status' => $dropped
                    ? User::STUDENT_STATUS_DROPPED
                    : User::STUDENT_STATUS_GRADUATED,
                'admission_year' => $admissionYear,
                'is_active' => false,
                'archived_at' => $dropped ? $enrolledAt->addYears(2) : $leftAt,
                'password' => $this->password,
                'created_at' => $enrolledAt,
                'updated_at' => $leftAt,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $cohort = DB::table('users')
            ->select('id', 'student_status', 'created_at')
            ->where('email', 'like', sprintf('alum%d-%%@%s', $graduationYear, self::DOMAIN))
            ->orderBy('id')
            ->get();

        DB::table('room_user')->insert($cohort->map(fn ($student): array => [
            'room_id' => $room->id,
            'user_id' => $student->id,
            'created_at' => $enrolledAt,
            'updated_at' => $enrolledAt,
        ])->all());

        return $cohort;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $cohort
     * @param  array<string, array{id: int, sat_at: CarbonImmutable}>  $exams
     */
    private function seedHistoricAttempts($cohort, array $exams, int $admissionYear): void
    {
        // The item ids are the same for every exam in a cohort, since all six
        // draw from the same per-subject Library file.
        $questionsByBank = [];

        foreach (self::SUBJECTS as $subject) {
            $questionsByBank[$subject] = DB::table('question_bank_questions')
                ->where('question_bank_id', $this->libraryBanks[$subject]->id)
                ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
                ->orderBy('item_number')
                ->pluck('id')
                ->all();
        }

        $attemptRows = [];

        foreach ($cohort as $position => $student) {
            // A dropout stops sitting exams partway through, which is what
            // makes them censored rather than failed.
            $isDropout = $student->student_status === User::STUDENT_STATUS_DROPPED;
            $subjects = $isDropout ? array_slice(self::SUBJECTS, 0, 2) : self::SUBJECTS;

            $ability = 48 + (($position * 11) % 42);

            foreach ($subjects as $round => $subject) {
                $exam = $exams[$subject];
                $satAt = $exam['sat_at'];

                // A gentle upward drift across the degree, so the semester
                // buckets show improvement rather than noise.
                $drift = (int) round(($round / max(1, count($subjects) - 1)) * 9);
                $score = max(5, min(100, $ability + $drift + mt_rand(-7, 7)));

                $attemptRows[] = [
                    'exam_id' => $exam['id'],
                    'room_id' => null,
                    'user_id' => $student->id,
                    'status' => ExamAttempt::STATUS_SUBMITTED,
                    'total_items' => 10,
                    'duration_minutes' => 60,
                    'answered_count' => 10,
                    'correct_answers' => (int) round($score / 10),
                    'score_percent' => $score,
                    'started_at' => $satAt->addHours(1),
                    'expires_at' => $satAt->addHours(2),
                    'submitted_at' => $satAt->addHours(1)->addMinutes(mt_rand(20, 55)),
                    'created_at' => $satAt,
                    'updated_at' => $satAt,
                ];
            }
        }

        // room_id is NOT NULL, so point them at the cohort's own archived room.
        $roomId = DB::table('room_user')->where('user_id', $cohort->first()->id)->value('room_id');

        foreach ($attemptRows as $index => $row) {
            $attemptRows[$index]['room_id'] = $roomId;
        }

        foreach (array_chunk($attemptRows, 1000) as $chunk) {
            DB::table('exam_attempts')->insert($chunk);
        }

        // Attempt items and answers, so per-question analytics and the live
        // board footer have something behind the historical exams too.
        $attempts = DB::table('exam_attempts')
            ->select('id', 'exam_id', 'user_id', 'score_percent', 'started_at')
            ->whereIn('user_id', $cohort->pluck('id'))
            ->get();

        $examSubject = [];
        foreach ($exams as $subject => $exam) {
            $examSubject[$exam['id']] = $subject;
        }

        $questionRows = [];
        $answerRows = [];

        foreach ($attempts as $attempt) {
            $subject = $examSubject[$attempt->exam_id] ?? null;
            $questionIds = $subject ? $questionsByBank[$subject] : [];
            $correct = (int) round(((float) $attempt->score_percent) / 10);

            foreach ($questionIds as $index => $questionId) {
                $questionRows[] = [
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $questionId,
                    'item_number' => $index + 1,
                    'created_at' => $attempt->started_at,
                    'updated_at' => $attempt->started_at,
                ];

                $answerRows[] = [
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $questionId,
                    'question_bank_option_id' => null,
                    'answer_text' => null,
                    'is_correct' => $index < $correct,
                    'answered_at' => $attempt->started_at,
                    'created_at' => $attempt->started_at,
                    'updated_at' => $attempt->started_at,
                ];
            }
        }

        foreach (array_chunk($questionRows, 2000) as $chunk) {
            DB::table('exam_attempt_questions')->insert($chunk);
        }

        foreach (array_chunk($answerRows, 2000) as $chunk) {
            DB::table('exam_attempt_answers')->insert($chunk);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $cohort
     */
    private function seedHistoricResults($cohort, int $graduationYear, float $passRate): void
    {
        $rows = [];
        $takenAt = CarbonImmutable::create($graduationYear, 9, 15);
        $graduates = $cohort->where('student_status', User::STUDENT_STATUS_GRADUATED)->values();

        foreach ($graduates as $index => $graduate) {
            // A couple per cohort are left unrecorded on purpose: in practice
            // some outcomes never get transcribed, and the Licensure screen is
            // where that backlog shows up.
            if ($index % 13 === 12) {
                continue;
            }

            $passed = ($index / max(1, $graduates->count())) < $passRate;

            $rows[] = [
                'user_id' => $graduate->id,
                'exam_year' => $graduationYear,
                'taken_at' => $takenAt,
                'result' => $passed ? LleResult::RESULT_PASS : LleResult::RESULT_FAIL,
                'rating' => $passed ? round(mt_rand(7500, 9200) / 100, 2) : round(mt_rand(6200, 7490) / 100, 2),
                'remarks' => null,
                'recorded_by' => $this->staff['examiner']->id,
                'created_at' => $takenAt,
                'updated_at' => $takenAt,
            ];

            // Roughly a third of those who fail sit it again the next year and
            // pass -- two rows for one person, which is why the unique key is
            // (user, exam_year).
            if (!$passed && $index % 3 === 0) {
                $rows[] = [
                    'user_id' => $graduate->id,
                    'exam_year' => $graduationYear + 1,
                    'taken_at' => $takenAt->addYear(),
                    'result' => LleResult::RESULT_PASS,
                    'rating' => round(mt_rand(7500, 8200) / 100, 2),
                    'remarks' => 'Passed on retake.',
                    'recorded_by' => $this->staff['examiner']->id,
                    'created_at' => $takenAt->addYear(),
                    'updated_at' => $takenAt->addYear(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('lle_results')->insert($chunk);
        }
    }

    // ------------------------------------------------------------ audit trail

    private function seedAuditTrail(): void
    {
        $entries = [
            ['examiner', 'library.upload', 'question_bank', 'Uploaded a mock exam set'],
            ['examiner', 'library.map', 'question_bank', 'Mapped items with AI'],
            ['examiner', 'review-bot.admin.generate', 'question_bank', 'Queued Review Bot question generation'],
            ['examiner', 'review-bot.admin.publish', 'question_bank_question', 'Published 12 Review Bot question(s)'],
            ['examiner', 'review-bot.admin.reject', 'question_bank_question', 'Declined 1 Review Bot question(s)'],
            ['examiner', 'lle-result.record', 'user', 'Recorded a licensure examination result'],
            ['admin', 'admin.user.update', 'user', 'Admin updated user account'],
        ];

        foreach ($entries as $index => [$who, $action, $type, $description]) {
            DB::table('audit_logs')->insert([
                'actor_id' => $this->staff[$who]->id,
                'action' => $action,
                'target_type' => $type,
                'target_id' => null,
                'description' => $description,
                'metadata' => json_encode(['seeded' => true]),
                'ip_address' => '127.0.0.1',
                'created_at' => $this->now->subDays(7 - $index),
                'updated_at' => $this->now->subDays(7 - $index),
            ]);
        }
    }

    private function historySummary(): string
    {
        $years = DB::table('lle_results')
            ->join('users', 'users.id', '=', 'lle_results.user_id')
            ->where('users.email', 'like', 'alum%@' . self::DOMAIN)
            ->selectRaw('MIN(exam_year) as first_year, MAX(exam_year) as last_year, COUNT(*) as total')
            ->first();

        if (!$years || !$years->total) {
            return 'none (SHOWCASE_HISTORY_YEARS=0)';
        }

        return sprintf(
            '%d cohorts, board results %d-%d',
            User::query()->where('email', 'like', 'alum%@' . self::DOMAIN)
                ->distinct()->count('admission_year'),
            $years->first_year,
            $years->last_year,
        );
    }

    // ----------------------------------------------------------------- report

    private function report(): void
    {
        $students = User::query()->where('email', 'like', '%@' . self::DOMAIN)->where('role', User::ROLE_STUDENT);

        $byStatus = (clone $students)
            ->selectRaw('student_status, COUNT(*) as total')
            ->groupBy('student_status')
            ->pluck('total', 'student_status');

        $showcaseBankIds = QuestionBank::query()
            ->whereIn('created_by', User::query()->where('email', 'like', '%@' . self::DOMAIN)->select('id'))
            ->pluck('id');

        $reviewBot = QuestionBankQuestion::query()
            ->whereIn('question_bank_id', $showcaseBankIds)
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $this->command?->info('Showcase data seeded. Sign in with password "password".');
        $this->command?->table(
            ['What', 'Count'],
            [
                ['Staff / admin accounts', 4],
                ['Students', (clone $students)->count()],
                ['  by status', $byStatus->map(fn ($n, $s) => "{$s}={$n}")->implode(', ')],
                ['Licensure results', LleResult::query()->whereIn('user_id', (clone $students)->select('id'))->count()],
                ['Library files', QuestionBank::query()->where('title', 'like', '[' . self::PREFIX . ']%')->count()],
                ['Exams', Exam::query()->where('title', 'like', '[' . self::PREFIX . ']%')->count()],
                ['Exam attempts', ExamAttempt::query()->whereIn('user_id', (clone $students)->select('id'))->count()],
                ['Review Bot questions', $reviewBot->map(fn ($n, $s) => "{$s}={$n}")->implode(', ')],
                ['Review Bot sessions', ReviewBotAttempt::query()->whereIn('user_id', (clone $students)->select('id'))->count()],
                ['Cohort history', $this->historySummary()],
                ['Archived rooms', Room::query()->where('code', 'like', self::PREFIX . '%')->whereNotNull('archived_at')->count()],
                ['Sign in as', 'examiner@' . self::DOMAIN . ' / admin@' . self::DOMAIN . ' / student0@' . self::DOMAIN],
            ],
        );
    }
}
