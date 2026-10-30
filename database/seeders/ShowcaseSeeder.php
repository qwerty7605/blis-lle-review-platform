<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LleResult;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\User;
use App\Services\Demo\DemoData;
use App\Services\Demo\DemoDataPurger;
use App\Services\QuestionMappingService;
use App\Services\ReviewBotAdminService;
use App\Support\LleTaxonomy;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoNames;
use Database\Seeders\Demo\DemoQuestions;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The demo: a BLIS programme that looks lived-in on every screen.
 *
 * At the defaults it builds:
 *  - staff: an admin, three teachers (the first is the programme chair), and
 *    a disabled former examiner (the login guard has a branch for that);
 *  - ~1,200 enrolled students in sections of about 45 across four year
 *    levels, irregular students, leavers of every kind, and three featured
 *    fourth years to walk through by name (top, borderline, struggling);
 *  - a Library of board-exam-style items, one per curated subtopic, plus a
 *    file whose mapping failed and one nobody has mapped yet;
 *  - this term's exams: for the fourth years a pre-test series and a mock
 *    board series, six subject exams each (one mock board exam open right
 *    now, with students mid-attempt), regular quizzes for the first and
 *    second years, battery exams for the third years, and a pre-board exam
 *    still to come. The Estimated Outcome shows ready for the fourth years
 *    who finished a series, locked with a series under way for the rest, and
 *    locked for the lower years, whose exams never feed it;
 *  - Review Bot questions made by the app's own generator from those items --
 *    published, declined and awaiting review -- and students' practice on them;
 *  - ten graduated cohorts of history, each with a pre-test and a mock board
 *    series before their LLE, and their LLE results (demo data, so never
 *    used to retrain the model).
 *
 * Sign-ins (see DemoData): the admin is DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD,
 * the teachers are teacher1-3@lnu.edu.ph, and students are on the reserved
 * blis.test domain; everyone but the admin uses DEMO_SEED_PASSWORD. Each falls
 * back to the default in DemoData when its variable is unset.
 *
 * Every account is marked users.is_demo, and nothing else marks the records
 * as demo data: `php artisan demo:purge` finds all of it through those
 * accounts, and running this seeder again replaces the previous demo. If a
 * staff address already belongs to a real account, the seeder stops rather
 * than take that account over.
 *
 *   php artisan db:seed           builds it (DatabaseSeeder runs this)
 *   php artisan demo:purge        takes it all out again
 *
 * SHOWCASE_COHORT_SIZE (default 300) is the intake per year level and
 * SHOWCASE_HISTORY_YEARS (default 10) the number of graduated cohorts.
 */
class ShowcaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const SUBJECTS = [
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Information Technology',
        'Reference, Bibliography and User Services',
        'Library Organization and Management',
        'Selection and Acquisition',
    ];

    /** Students per section; a year level gets as many sections as it needs. */
    private const SECTION_SIZE = 45;

    /** How a cohort thins by year level -- attrition, seen from enrolment. */
    private const RETENTION = [1 => 1.0, 2 => 0.96, 3 => 0.92, 4 => 0.89];

    /**
     * Fourth years the demo can be walked through by name: the score each
     * centres on, and the subjects that pull it down. Review-exam scores run
     * low (the base dataset's sit around 50), and version 1 of the Estimated
     * Outcome puts the at-risk line near 64 on a mock board (all subjects
     * equal). The borderline student centres on 59 with weak subjects the
     * model weighs lightly, which lands them close to the line, on its safe
     * side (about 80% chance of passing).
     */
    private const FEATURED = [
        'top' => ['Angelica', 'Reyes', 'Dela Cruz', 90, []],
        'borderline' => ['Mark Anthony', 'Santos', 'Villareal', 59, ['Cataloging and Classification', 'Indexing and Abstracting']],
        'struggling' => ['Kristine Joy', 'Pacaña', 'Bacalso', 46, ['Cataloging and Classification', 'Information Technology', 'Selection and Acquisition']],
    ];

    /** Quizzes the lower years sat this term: year level => [subject, kind]. */
    private const LOWER_YEAR_EXAMS = [
        1 => [['Information Technology', 'Diagnostic Quiz'], ['Reference, Bibliography and User Services', 'Diagnostic Quiz']],
        2 => [['Cataloging and Classification', 'Midterm Quiz'], ['Indexing and Abstracting', 'Midterm Quiz']],
        3 => [['Library Organization and Management', 'Unit Test'], ['Selection and Acquisition', 'Unit Test']],
    ];

    /**
     * Board pass rates by cohort, oldest first, cycled if more years are asked
     * for. Real programmes do not hold a flat rate, and a flat one would make
     * the year-on-year outcome charts look generated.
     */
    private const HISTORIC_PASS_RATES = [0.58, 0.64, 0.61, 0.72, 0.69, 0.78, 0.74, 0.81, 0.77, 0.85];

    /** Cohort sizes relative to the base intake, cycled the same way. */
    private const HISTORIC_COHORT_RATIOS = [0.74, 0.87, 0.80, 1.03, 0.93, 1.10, 1.00, 1.20, 1.13, 1.27];

    private const DIFFICULTY_WEIGHT = [
        QuestionBankQuestion::DIFFICULTY_EASY => 1.0,
        QuestionBankQuestion::DIFFICULTY_MEDIUM => 0.7,
        QuestionBankQuestion::DIFFICULTY_HARD => 0.45,
    ];

    private CarbonImmutable $now;
    private CarbonImmutable $termStart;
    private string $passwordHash;
    private DemoNames $names;

    /** @var array<string, int> role key => user id */
    private array $staff = [];

    /** @var array<string, array{label: string, name: string, email: string}> */
    private array $logins = [];

    /** @var array<int, list<int>> year level => this term's section room ids */
    private array $sections = [];

    /** @var array<string, array<string, array{id: int, subtopics: array<string, int>}>> */
    private array $taxonomy = [];

    /** @var array<string, int> subject => core Library file id */
    private array $libraryBanks = [];

    /** @var array<int, list<array{id: int, difficulty: string, correct: int|null, wrong: list<int>}>> */
    private array $bankItems = [];

    /** @var array<string, array<string, mixed>> */
    private array $exams = [];

    /** @var list<array{id: int, room_id: int, year_level: int, ability: float, featured: ?string, prediction_ready: bool}> */
    private array $students = [];

    /** @var array<int, array<string, int>> user id => subject => how far that subject sits from their norm */
    private array $subjectOffsets = [];

    /** @var list<array<string, mixed>> */
    private array $pendingAttempts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffers = [];

    /** @var array<int, int> */
    private array $studentIdSequence = [];

    /** @var array<string, true> */
    private array $takenStudentIds = [];

    /** @var array<string, true> */
    private array $takenRoomCodes = [];

    public function run(): void
    {
        $this->now = CarbonImmutable::now()->startOfMinute();
        $this->termStart = CarbonImmutable::create($this->now->month >= 8 ? $this->now->year : $this->now->year - 1, 8, 1);

        $this->ensureStaffAddressesAreFree();

        // A rerun replaces the demo instead of stacking a second one on top.
        // The load-test accounts belong to a separate tool and are left alone.
        $this->command?->info('Removing the previous demo, if there is one...');
        (new DemoDataPurger([DemoData::EMAIL_DOMAIN, 'showcase.local']))->purge();

        // Deterministic: every run produces the same people and numbers, so a
        // walkthrough can be rehearsed and a screenshot stays accurate.
        mt_srand(20260906);

        $this->passwordHash = Hash::make(DemoData::password());
        $this->names = new DemoNames(DemoData::EMAIL_DOMAIN);
        $this->takenStudentIds = array_fill_keys(
            DB::table('users')->whereNotNull('student_id')->pluck('student_id')->map(fn ($id): string => (string) $id)->all(),
            true,
        );
        $this->takenRoomCodes = array_fill_keys(DB::table('rooms')->pluck('code')->all(), true);

        $this->command?->info('Building staff, students and the Library...');
        $this->seedStaff();
        $this->seedTaxonomy();
        $this->seedLibrary();
        $this->seedCurrentStudents();
        $this->seedLeavers();

        $this->command?->info("Building this term's exams and attempts...");
        $this->seedCurrentExams();
        $this->seedCurrentAttempts();

        $this->seedHistory();

        $this->command?->info('Generating Review Bot questions and practice sessions...');
        $this->seedReviewBot();
        $this->seedReviewBotSessions();
        $this->seedAuditTrail();

        // Last, so the numbers everything above draws stay what they were.
        $this->seedThirdYearSemester();

        $this->report();
    }

    // ------------------------------------------------------------------ staff

    /**
     * The admin and teachers sign in with addresses on the real domain. One
     * that already belongs to a real account is someone's actual login, so
     * stop before changing anything rather than take it over.
     */
    private function ensureStaffAddressesAreFree(): void
    {
        $taken = DB::table('users')
            ->whereIn('email', array_filter([DemoData::adminEmail(), ...DemoData::teacherEmails(), DemoData::studentEmail()]))
            ->where('is_demo', false)
            ->pluck('email')
            ->all();

        if ($taken !== []) {
            throw new RuntimeException(sprintf(
                'Nothing was seeded: %s already belongs to a real account, and the demo will not take it over. '
                . 'Delete or change that account first, or choose another demo admin address with DEMO_ADMIN_EMAIL.',
                implode(', ', $taken),
            ));
        }
    }

    private function seedStaff(): void
    {
        [$first, $second, $third] = DemoData::teacherEmails();

        $this->staff['admin'] = $this->insertStaff(
            'Admin',
            ['name' => 'Admin', 'first_name' => 'Admin', 'middle_name' => null, 'last_name' => null, 'email' => DemoData::adminEmail()],
            User::ROLE_ADMIN,
            $this->termStart->subYears(2),
            Hash::make(DemoData::adminPassword()),
        );
        $this->staff['chair'] = $this->insertStaff('Teacher 1 (programme chair)', $this->names->person('Maricel', 'Tan', 'Aquino', $first), User::ROLE_STAFF_MASTER_EXAMINER, $this->termStart->subYears(2)->addDays(2));
        $this->staff['examiner'] = $this->insertStaff('Teacher 2', $this->names->person('Ramon', 'Bautista', 'Villanueva', $second), User::ROLE_STAFF_MASTER_EXAMINER, $this->termStart->subYear());
        $this->staff['teacher3'] = $this->insertStaff('Teacher 3', $this->names->person('Liza', 'Cruz', 'Dimaano', $third), User::ROLE_STAFF_MASTER_EXAMINER, $this->termStart->subMonths(7));

        // A former examiner whose account was disabled -- the login guard and
        // the staff list both have a branch for it. Nobody signs in with it,
        // so it keeps a demo-only address.
        $this->insertStaff(null, $this->names->person('Jocelyn', 'Perez', 'Ramos'), User::ROLE_STAFF_MASTER_EXAMINER, $this->termStart->subYears(4), null, false);
    }

    /**
     * @param  array{name: string, first_name: string, middle_name: ?string, last_name: ?string, email: string}  $person
     */
    private function insertStaff(
        ?string $label,
        array $person,
        string $role,
        CarbonImmutable $createdAt,
        ?string $passwordHash = null,
        bool $active = true,
    ): int {
        if ($label !== null) {
            $this->logins[$label] = [
                'label' => $label,
                'name' => $person['name'],
                'email' => $person['email'],
                'password' => $role === User::ROLE_ADMIN
                    ? $this->passwordLabel('DEMO_ADMIN_PASSWORD', DemoData::adminPassword())
                    : $this->passwordLabel('DEMO_SEED_PASSWORD', DemoData::password()),
            ];
        }

        return (int) DB::table('users')->insertGetId([
            ...$person,
            'role' => $role,
            'is_active' => $active,
            'is_demo' => true,
            'password' => $passwordHash ?? $this->passwordHash,
            'email_verified_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $active ? $createdAt : $this->now->subMonths(3),
        ]);
    }

    /**
     * What to print for a password: the value when it is the committed
     * default, never one someone set in the environment.
     */
    private function passwordLabel(string $variable, string $password): string
    {
        return env($variable) ? "(the {$variable} you set)" : $password;
    }

    // --------------------------------------------------------------- taxonomy

    private function seedTaxonomy(): void
    {
        $mapping = app(QuestionMappingService::class);

        foreach (self::SUBJECTS as $subject) {
            foreach ($mapping->curatedTopicsForSubject($subject) as $topicName) {
                // Only created when missing, so a topic real uploads already
                // use is shared rather than duplicated -- and survives a purge.
                // Through the mapping service, so each carries its TOS code.
                $topic = $mapping->resolveTopic($subject, $topicName, $this->staff['chair']);

                $subtopics = [];

                foreach ($mapping->curatedSubtopicsForTopic($subject, $topicName) as $subtopicName) {
                    $subtopics[$subtopicName] = (int) $mapping->resolveSubtopic($topic, $subtopicName, $this->staff['chair'])->id;
                }

                $this->taxonomy[$subject][$topicName] = ['id' => (int) $topic->id, 'subtopics' => $subtopics];
            }
        }
    }

    // ---------------------------------------------------------------- library

    private function seedLibrary(): void
    {
        foreach (self::SUBJECTS as $index => $subject) {
            // Uploaded last year and reused every term since.
            $uploadedAt = $this->termStart->subMonths(14)->addDays($index * 3)->setTime(10 + $index, 12);
            $owner = $this->teacher($index);

            $bankId = $this->insertBank([
                'title' => "{$subject} Mock Board Reviewer",
                'subject' => $subject,
                'source_filename' => str_replace(' ', '_', $subject) . '_Mock_Board_Reviewer.docx',
                'mapping_status' => QuestionBank::MAPPING_STATUS_COMPLETED,
                'mapping_mode' => QuestionBank::MAPPING_MODE_AUTO,
                'mapping_message' => null,
                'mapping_requested_at' => $uploadedAt,
                'mapping_completed_at' => $uploadedAt->addMinutes(3),
                'created_by' => $owner,
                'created_at' => $uploadedAt,
                'updated_at' => $uploadedAt->addMinutes(3),
            ]);

            $this->insertItems($bankId, $subject, DemoQuestions::forSubject($subject), $uploadedAt, true);
            $this->libraryBanks[$subject] = $bankId;
        }

        // A recent upload whose mapping stopped partway.
        $failedAt = $this->now->subDays(3)->setTime(15, 12);
        $failed = $this->insertBank([
            'title' => 'Reference Services Quiz Set B',
            'subject' => 'Reference, Bibliography and User Services',
            'source_filename' => 'Reference_Services_Quiz_Set_B.docx',
            'mapping_status' => QuestionBank::MAPPING_STATUS_FAILED,
            'mapping_mode' => QuestionBank::MAPPING_MODE_AUTO,
            'mapping_message' => 'Mapping stopped before it finished. Retry mapping to tag the remaining items.',
            'mapping_requested_at' => $failedAt,
            'mapping_completed_at' => null,
            'created_by' => $this->staff['examiner'],
            'created_at' => $failedAt,
            'updated_at' => $failedAt->addMinutes(6),
        ]);
        $this->insertItems($failed, 'Reference, Bibliography and User Services', array_slice(DemoQuestions::forSubject('Reference, Bibliography and User Services'), 0, 6), $failedAt, false);

        // A file nobody has mapped yet -- the "Map Items" call to action.
        $idleAt = $this->now->subDay()->setTime(9, 40);
        $idle = $this->insertBank([
            'title' => 'Archival Studies Pre-Test',
            'subject' => 'Archival Studies',
            'source_filename' => 'Archival_Studies_Pre-Test.docx',
            'mapping_status' => QuestionBank::MAPPING_STATUS_IDLE,
            'mapping_mode' => null,
            'mapping_message' => null,
            'mapping_requested_at' => null,
            'mapping_completed_at' => null,
            'created_by' => $this->staff['teacher3'],
            'created_at' => $idleAt,
            'updated_at' => $idleAt,
        ]);
        $this->insertItems($idle, 'Archival Studies', DemoQuestions::archivalStudies(), $idleAt, false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertBank(array $attributes): int
    {
        return (int) DB::table('question_banks')->insertGetId($attributes + ['total_items' => 0]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function insertItems(int $bankId, string $subject, array $items, CarbonImmutable $at, bool $mapped): void
    {
        $labels = ['A', 'B', 'C', 'D'];
        $count = count($items);

        foreach ($items as $index => $item) {
            // Items name their topic and sub-topic by TOS code.
            $topicLabel = LleTaxonomy::topic($subject, $item['topic'] ?? null)['label'] ?? null;
            $subtopicLabel = LleTaxonomy::subtopic($subject, $item['topic'] ?? null, $item['subtopic'] ?? null)['label'] ?? null;
            $pair = $topicLabel !== null ? ($this->taxonomy[$subject][$topicLabel] ?? null) : null;

            // The classifier leaves an item it is unsure of for a teacher to
            // confirm; the last item of each mapped file shows that state.
            $tagged = $mapped && $pair !== null && $index !== $count - 1;
            $isTrueFalse = ($item['type'] ?? null) === QuestionBankQuestion::TYPE_TRUE_FALSE;

            if ($isTrueFalse) {
                $texts = ['True', 'False'];
                $correctIndex = $item['answer'] ? 0 : 1;
            } else {
                // The data lists the answer first; spread the key across A-D.
                $order = array_keys($item['options']);
                shuffle($order);
                $texts = array_map(fn (int $i): string => $item['options'][$i], $order);
                $correctIndex = (int) array_search(0, $order, true);
            }

            $questionId = (int) DB::table('question_bank_questions')->insertGetId([
                'question_bank_id' => $bankId,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => $subject,
                'topic_id' => $tagged ? $pair['id'] : null,
                'subtopic_id' => $tagged ? ($pair['subtopics'][$subtopicLabel] ?? null) : null,
                'difficulty' => $item['difficulty'],
                'tag_status' => $tagged
                    ? QuestionBankQuestion::TAG_STATUS_CONFIRMED
                    : QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW,
                // Most tags come from the classifier; a few a teacher corrected.
                'tag_source' => $tagged
                    ? ($index % 7 === 3 ? QuestionBankQuestion::TAG_SOURCE_FACULTY : QuestionBankQuestion::TAG_SOURCE_RULE_BASED)
                    : null,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'item_number' => $index + 1,
                'question_text' => $item['question'],
                'question_type' => $isTrueFalse
                    ? QuestionBankQuestion::TYPE_TRUE_FALSE
                    : QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => $labels[$correctIndex],
                'answer_text' => $texts[$correctIndex],
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            DB::table('question_bank_options')->insert(array_map(fn (int $i): array => [
                'question_bank_question_id' => $questionId,
                'sort_order' => $i + 1,
                'option_label' => $labels[$i],
                'option_text' => $texts[$i],
                'is_correct' => $i === $correctIndex,
                'created_at' => $at,
                'updated_at' => $at,
            ], array_keys($texts)));

            $optionIds = DB::table('question_bank_options')
                ->where('question_bank_question_id', $questionId)
                ->orderBy('sort_order')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $wrong = $optionIds;
            unset($wrong[$correctIndex]);

            $this->bankItems[$bankId][] = [
                'id' => $questionId,
                'difficulty' => $item['difficulty'],
                'correct' => $optionIds[$correctIndex] ?? null,
                'wrong' => array_values($wrong),
            ];
        }

        DB::table('question_banks')->where('id', $bankId)->update(['total_items' => $count]);
    }

    // --------------------------------------------------------------- students

    private function seedCurrentStudents(): void
    {
        $termYear = $this->termStart->year;
        $opened = $this->termStart->min($this->now->subDays(45));
        $rows = [];
        $placements = [];

        foreach (self::RETENTION as $yearLevel => $retention) {
            $count = max(1, (int) round($this->cohortSize() * $retention));
            $sectionCount = (int) ceil($count / self::SECTION_SIZE);
            $admissionYear = $termYear - ($yearLevel - 1);

            for ($section = 0; $section < $sectionCount; $section++) {
                $this->sections[$yearLevel][] = $this->insertRoom(
                    sprintf('BLIS %d-%s', $yearLevel, chr(ord('A') + $section)),
                    $this->yearLevelTeacher($yearLevel),
                    $opened,
                );
            }

            $featuredKeys = $yearLevel === 4 ? array_keys(self::FEATURED) : [];

            for ($i = 0; $i < $count; $i++) {
                $featured = $featuredKeys[$i] ?? null;

                if ($featured !== null) {
                    [$first, $middle, $last, $ability] = self::FEATURED[$featured];
                    $person = $this->names->person($first, $middle, $last, $i === 0 ? DemoData::studentEmail() : null);
                } else {
                    $person = $this->names->next();
                    $ability = $this->ability();
                }

                $rows[] = $this->studentRow($person, $admissionYear, $yearLevel, User::STUDENT_STATUS_ENROLLED);
                $placements[$person['email']] = [
                    'room_id' => $this->sections[$yearLevel][$i % $sectionCount],
                    'year_level' => $yearLevel,
                    'ability' => (float) $ability,
                    'featured' => $featured,
                    // Most fourth years have finished a pre-test and a mock
                    // board, which is what unlocks the Estimated Outcome; the
                    // rest have a series under way, so "locked" shows too.
                    'prediction_ready' => $yearLevel === 4 && ($featured !== null || $i < (int) round($count * 0.6)),
                ];
            }
        }

        // Irregular students past the standard four years.
        foreach ([5, 6] as $yearLevel) {
            $person = $this->names->next();
            $rows[] = $this->studentRow($person, $termYear - $yearLevel + 1, $yearLevel, User::STUDENT_STATUS_ENROLLED);
            $placements[$person['email']] = [
                'room_id' => $this->sections[4][0],
                'year_level' => $yearLevel,
                'ability' => $this->ability(64),
                'featured' => null,
                'prediction_ready' => false,
            ];
        }

        // Enrolled, but the account is disabled, so sign-in is refused.
        $person = $this->names->next();
        $rows[] = ['is_active' => false] + $this->studentRow($person, $termYear - 1, 2, User::STUDENT_STATUS_ENROLLED);
        $placements[$person['email']] = [
            'room_id' => $this->sections[2][0],
            'year_level' => 2,
            'ability' => $this->ability(),
            'featured' => null,
            'prediction_ready' => false,
        ];

        $ids = $this->insertStudents($rows);
        $memberships = [];

        foreach ($placements as $email => $placement) {
            $id = $ids[$email];
            $this->students[] = ['id' => $id] + $placement;

            $joinedAt = $opened->addDays(mt_rand(0, 6))->setTime(mt_rand(7, 18), mt_rand(0, 59));
            $memberships[] = ['room_id' => $placement['room_id'], 'user_id' => $id, 'created_at' => $joinedAt, 'updated_at' => $joinedAt];

            if ($placement['featured'] !== null) {
                $labels = [
                    'top' => 'Student, top of the class',
                    'borderline' => 'Student, near the passing line',
                    'struggling' => 'Student, struggling',
                ];
                $label = $labels[$placement['featured']];
                $this->logins[$label] = [
                    'label' => $label,
                    'name' => $this->nameOf($rows, $email),
                    'email' => $email,
                    'password' => $this->passwordLabel('DEMO_SEED_PASSWORD', DemoData::password()),
                ];
            }
        }

        foreach (array_chunk($memberships, 1000) as $chunk) {
            DB::table('room_user')->insert($chunk);
        }
    }

    /**
     * Students who have left, each for a different reason. Keeping graduates
     * apart from dropouts and transferees is the reason student_status exists.
     */
    private function seedLeavers(): void
    {
        $termYear = $this->termStart->year;
        $graduatedAt = CarbonImmutable::create($termYear, 6, 30);
        $rows = [];
        $graduates = [];

        for ($i = 0; $i < 8; $i++) {
            $person = $this->names->next();
            $rows[] = $this->studentRow($person, $termYear - 4, null, User::STUDENT_STATUS_GRADUATED, $graduatedAt);
            $graduates[$person['email']] = $this->ability(70);
        }

        foreach ([
            [3, User::STUDENT_STATUS_DROPPED, $termYear - 2, 3, 3],
            [2, User::STUDENT_STATUS_TRANSFERRED, $termYear - 1, 2, 2],
            [2, User::STUDENT_STATUS_ON_LEAVE, $termYear - 2, 3, 1],
            // Archived before anyone recorded why: the backlog the Students tab
            // surfaces as "Reason not recorded".
            [3, User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED, $termYear - 6, null, 30],
        ] as [$count, $status, $admissionYear, $yearLevel, $monthsAgo]) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = $this->studentRow(
                    $this->names->next(),
                    $admissionYear,
                    $yearLevel,
                    $status,
                    $this->now->subMonths($monthsAgo + $i)->subDays(mt_rand(0, 20)),
                );
            }
        }

        $ids = $this->insertStudents($rows);

        // Graduated in June; the board exam comes after, and results take about
        // six weeks. Until then this batch has none. Once they are out, two
        // are still left unrecorded -- the gap the Licensure screen closes.
        $boardDate = CarbonImmutable::create($termYear, 9, 14);

        if ($boardDate->addWeeks(6)->lessThan($this->now)) {
            $graduateIds = [];

            foreach ($graduates as $email => $ability) {
                $graduateIds[$ids[$email]] = $ability;
            }

            $this->insertBoardResults(array_slice($graduateIds, 0, 6, true), $termYear, $boardDate, 0.7);
        }
    }

    /**
     * @param  array{name: string, first_name: string, middle_name: string, last_name: string, email: string}  $person
     * @return array<string, mixed>
     */
    private function studentRow(array $person, int $admissionYear, ?int $yearLevel, string $status, ?CarbonImmutable $leftAt = null): array
    {
        $enrolledAt = CarbonImmutable::create($admissionYear, 8, 1)->addDays(mt_rand(0, 20))->setTime(mt_rand(8, 17), mt_rand(0, 59));
        $archivedAt = User::isEnrolledStatus($status) ? null : ($leftAt ?? $this->now);

        return [
            ...$person,
            'student_id' => $this->newStudentId($admissionYear),
            'role' => User::ROLE_STUDENT,
            'year_level' => $yearLevel,
            'student_status' => $status,
            'admission_year' => $admissionYear,
            'is_active' => true,
            'is_demo' => true,
            // Same rule as User::applyStudentStatus(): only enrolled students
            // are in circulation.
            'archived_at' => $archivedAt,
            'password' => $this->passwordHash,
            'email_verified_at' => $enrolledAt,
            'created_at' => $enrolledAt,
            'updated_at' => $archivedAt ?? $enrolledAt,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int> email => id
     */
    private function insertStudents(array $rows): array
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $ids = [];

        foreach (array_chunk(array_column($rows, 'email'), 1000) as $emails) {
            foreach (DB::table('users')->whereIn('email', $emails)->get(['id', 'email']) as $user) {
                $ids[$user->email] = (int) $user->id;
            }
        }

        return $ids;
    }

    /**
     * Nine digits -- the admission year, then a running number. Real student
     * numbers are seven digits, so a demo account can never take one a real
     * student will need at registration.
     */
    private function newStudentId(int $admissionYear): string
    {
        $sequence = $this->studentIdSequence[$admissionYear] ??= mt_rand(120, 480);

        do {
            $id = sprintf('%d%05d', $admissionYear, ++$sequence);
        } while (isset($this->takenStudentIds[$id]));

        $this->studentIdSequence[$admissionYear] = $sequence;
        $this->takenStudentIds[$id] = true;

        return $id;
    }

    /**
     * A student's typical score: most sit in the 60s and 70s, a few far out.
     */
    private function ability(float $mean = 70.0, float $spread = 11.0): float
    {
        $u = max(1e-9, mt_rand() / mt_getrandmax());
        $v = mt_rand() / mt_getrandmax();

        return max(30.0, min(97.0, $mean + sqrt(-2 * log($u)) * cos(2 * M_PI * $v) * $spread));
    }

    // ------------------------------------------------------------------ rooms

    private function insertRoom(string $name, int $ownerId, CarbonImmutable $createdAt, ?CarbonImmutable $archivedAt = null): int
    {
        return (int) DB::table('rooms')->insertGetId([
            'name' => $name,
            'code' => $this->newRoomCode(),
            'created_by' => $ownerId,
            'archived_at' => $archivedAt,
            'created_at' => $createdAt,
            'updated_at' => $archivedAt ?? $createdAt,
        ]);
    }

    /**
     * Six characters, like the codes rooms get when a teacher creates one.
     */
    private function newRoomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
        } while (isset($this->takenRoomCodes[$code]));

        $this->takenRoomCodes[$code] = true;

        return $code;
    }

    // ------------------------------------------------------------------ exams

    private function seedCurrentExams(): void
    {
        $academicYear = sprintf('AY %d-%d', $this->termStart->year, $this->termStart->year + 1);
        $owner = $this->yearLevelTeacher(4);

        // The fourth years' LLE review: a pre-test early in the term, closed,
        // and the first mock board, one subject still open. Both are series of
        // six subject exams; they are what the Estimated Outcome reads.
        $preTest = $this->insertSeries("Pre-test, {$academicYear}", Exam::TYPE_PRE_TEST, $owner, $this->termStart->addDays(3));
        $mockBoard = $this->insertSeries("Mock Board 1, {$academicYear}", Exam::TYPE_MOCK_BOARD, $owner, $this->termStart->addDays(3));

        foreach (self::SUBJECTS as $index => $subject) {
            $start = $this->termStart->addWeeks(2)->addDays($index)->setTime(8, 0);

            if ($start->greaterThan($this->now->subDays(30))) {
                $start = $this->now->subDays(30 - $index)->setTime(8, 0);
            }

            $this->exams["pretest:{$subject}"] = $this->insertExam([
                'title' => "{$subject} Pre-test",
                'subject' => $subject,
                'type' => Exam::TYPE_PRE_TEST,
                'series' => $preTest,
                'description' => $this->describe($subject),
                'bank' => $this->libraryBanks[$subject],
                'duration' => 60,
                'start' => $start,
                'end' => $start->addDays(1)->setTime(17, 0),
                'results' => 'visible',
                'one_take_only' => true,
                'shuffle' => true,
                'owner' => $owner,
            ], $this->sections[4]);
        }

        foreach (self::SUBJECTS as $index => $subject) {
            // One is open right now, so the live board has students mid-attempt;
            // the rest have closed. None is upcoming: the predictor needs an
            // average in every subject.
            [$start, $end] = $index === 4
                ? [$this->now->subHours(2), $this->now->addHours(6)]
                : [$this->now->subDays(20 - $index)->setTime(8, 0), $this->now->subDays(13 - $index)->setTime(17, 0)];

            $this->exams["mock:{$subject}"] = $this->insertExam([
                'title' => "{$subject} Mock Board Exam",
                'subject' => $subject,
                'type' => Exam::TYPE_MOCK_BOARD,
                'series' => $mockBoard,
                'description' => $this->describe($subject),
                'bank' => $this->libraryBanks[$subject],
                'duration' => 60,
                'start' => $start,
                'end' => $end,
                'results' => $index % 2 === 0 ? 'visible' : 'hidden',
                'one_take_only' => $index === 3,
                'shuffle' => $index % 2 === 1,
                'owner' => $this->yearLevelTeacher(4),
            ], $this->sections[4]);
        }

        // Scheduled, not yet open: the upcoming state on the student's list.
        $this->exams['preboard'] = $this->insertExam([
            'title' => 'Pre-Board Examination: Selection and Acquisition',
            'subject' => 'Selection and Acquisition',
            'description' => 'Final pre-board examination before the licensure review. ' . $this->describe('Selection and Acquisition'),
            'bank' => $this->libraryBanks['Selection and Acquisition'],
            'duration' => 90,
            'start' => $this->now->addDays(5)->setTime(8, 0),
            'end' => $this->now->addDays(7)->setTime(17, 0),
            'results' => 'hidden',
            'one_take_only' => true,
            'shuffle' => true,
            'owner' => $this->yearLevelTeacher(4),
        ], $this->sections[4]);

        // The open exam is being paced live in one section.
        $open = $this->exams['mock:' . self::SUBJECTS[4]];
        DB::table('exam_room_pacing_states')->insert([
            'exam_id' => $open['id'],
            'room_id' => $this->sections[4][0],
            'is_active' => true,
            'current_item_number' => 6,
            'started_at' => $this->now->subMinutes(70),
            'updated_by' => $open['owner'],
            'created_at' => $this->now->subMinutes(70),
            'updated_at' => $this->now->subMinutes(4),
        ]);

        foreach (self::LOWER_YEAR_EXAMS as $yearLevel => $exams) {
            foreach ($exams as $n => [$subject, $kind]) {
                $start = $this->termStart->addWeeks(2 + $n * 3 + $yearLevel)->setTime(9, 0);

                if ($start->greaterThan($this->now->subDays(4))) {
                    $start = $this->now->subDays(4 + $n * 3)->setTime(9, 0);
                }

                $this->exams["{$yearLevel}:{$subject}"] = $this->insertExam([
                    'title' => "{$subject} {$kind}",
                    'subject' => $subject,
                    // The third years' unit tests are this semester's battery.
                    'type' => $yearLevel === 3 ? Exam::TYPE_BATTERY : Exam::TYPE_REGULAR,
                    'description' => $this->describe($subject),
                    'bank' => $this->libraryBanks[$subject],
                    'duration' => 45,
                    'start' => $start,
                    'end' => $start->addDays(2)->setTime(17, 0),
                    'results' => 'visible',
                    'one_take_only' => true,
                    'shuffle' => true,
                    'owner' => $this->yearLevelTeacher($yearLevel),
                ], $this->sections[$yearLevel]);
            }
        }

        // A quiz its teacher has since archived from one section, after the
        // section sat it -- the archived-assignment state.
        $archived = $this->exams['3:Library Organization and Management'];
        DB::table('exam_room')
            ->where('exam_id', $archived['id'])
            ->where('room_id', $this->sections[3][0])
            ->update([
                'archived_at' => $this->now->subDays(2)->setTime(16, 2),
                'archived_by' => $archived['owner'],
                'updated_at' => $this->now->subDays(2)->setTime(16, 2),
            ]);
    }

    /**
     * @param  array<string, mixed>  $exam
     * @param  list<int>  $roomIds
     * @return array<string, mixed>
     */
    private function insertExam(array $exam, array $roomIds, ?CarbonImmutable $archivedAt = null): array
    {
        $createdAt = $exam['start']->subDays(mt_rand(3, 7))->setTime(mt_rand(8, 16), mt_rand(0, 59));
        $total = count($this->bankItems[$exam['bank']]);

        $id = (int) DB::table('exams')->insertGetId([
            'title' => $exam['title'],
            'subject' => $exam['subject'],
            'exam_type' => $exam['type'] ?? Exam::TYPE_REGULAR,
            'exam_series_id' => $exam['series'] ?? null,
            'series_subject' => isset($exam['series']) ? LleTaxonomy::subjectKey($exam['subject']) : null,
            'description' => $exam['description'],
            'question_bank_id' => $exam['bank'],
            'total_items' => $total,
            'duration_minutes' => $exam['duration'],
            'scheduled_at' => $exam['start'],
            'schedule_start_at' => $exam['start'],
            'schedule_end_at' => $exam['end'],
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'results_visibility_mode' => $exam['results'],
            // Filed by date, as an exam saved without them is (Exam::booted()).
            ...Exam::termForDate($exam['start']),
            'one_take_only' => $exam['one_take_only'],
            'shuffle_questions' => $exam['shuffle'],
            'created_by' => $exam['owner'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        DB::table('exam_question_bank')->insert([
            'exam_id' => $id,
            'question_bank_id' => $exam['bank'],
            'position' => 1,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->assignExam($id, $roomIds, $exam['owner'], $createdAt->addHour(), $archivedAt);

        return ['id' => $id, 'total' => $total] + $exam;
    }

    /** A pre-test or mock board series: the six subject exams that belong together. */
    private function insertSeries(string $name, string $type, int $owner, CarbonImmutable $at): int
    {
        return (int) DB::table('exam_series')->insertGetId([
            'name' => $name,
            'type' => $type,
            'created_by' => $owner,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * @param  list<int>  $roomIds
     */
    private function assignExam(int $examId, array $roomIds, int $by, CarbonImmutable $at, ?CarbonImmutable $archivedAt = null): void
    {
        DB::table('exam_room')->insert(array_map(fn (int $roomId): array => [
            'exam_id' => $examId,
            'room_id' => $roomId,
            'assigned_by' => $by,
            'archived_at' => $archivedAt,
            'archived_by' => $archivedAt ? $by : null,
            'created_at' => $at,
            'updated_at' => $archivedAt ?? $at,
        ], $roomIds));
    }

    private function describe(string $subject): string
    {
        // The TOS topics by their plain names, without "Other / Unclassified".
        $topics = array_values(array_map(
            fn (array $topic): string => $topic['name'],
            array_filter(LleTaxonomy::topics($subject), fn (array $topic): bool => !$topic['other']),
        ));
        $last = array_pop($topics);

        return $topics === []
            ? "Covers {$last}."
            : 'Covers ' . implode(', ', $topics) . ", and {$last}.";
    }

    // --------------------------------------------------------------- attempts

    private function seedCurrentAttempts(): void
    {
        $open = $this->exams['mock:' . self::SUBJECTS[4]];
        $live = 0;

        foreach ($this->students as $student) {
            if ($student['year_level'] < 4) {
                continue;
            }

            // The pre-test: those ready for a prediction sat all six; the
            // rest have four, so their card shows a series still under way.
            $preTestSubjects = $student['prediction_ready'] ? self::SUBJECTS : array_slice(self::SUBJECTS, 0, 4);

            foreach ($preTestSubjects as $subject) {
                $exam = $this->exams["pretest:{$subject}"];
                $this->queueAttempt($student, $exam, $this->scoreFor($student, $subject) - 7, $this->sittingTime($exam));
            }

            $subjects = $student['prediction_ready'] ? self::SUBJECTS : array_slice(self::SUBJECTS, 0, 2);

            foreach ($subjects as $subject) {
                $exam = $this->exams["mock:{$subject}"];
                $score = $this->scoreFor($student, $subject);

                $this->queueAttempt($student, $exam, $score, $this->sittingTime($exam));

                // Most sat each mock exam a second time after reviewing -- the
                // latest-attempt rule the analytics use needs that case.
                if ($student['prediction_ready'] && !$exam['one_take_only'] && $exam['id'] !== $open['id']) {
                    $this->queueAttempt($student, $exam, $score + mt_rand(2, 9), $this->sittingTime($exam, true));
                }
            }

            // A dozen students who have not sat it yet are mid-attempt on the
            // open exam right now.
            if (!$student['prediction_ready'] && $student['year_level'] === 4 && $live < 12) {
                $minutesIn = mt_rand(6, 48);
                $this->queueAttempt(
                    $student,
                    $open,
                    $this->scoreFor($student, $open['subject']),
                    $this->now->subMinutes($minutesIn)->subSeconds(mt_rand(0, 59)),
                    false,
                    min(0.95, $minutesIn / 55),
                );
                $live++;
            }
        }

        foreach (self::LOWER_YEAR_EXAMS as $yearLevel => $exams) {
            foreach ($exams as [$subject]) {
                $exam = $this->exams["{$yearLevel}:{$subject}"];

                foreach ($this->students as $student) {
                    // A few are absent from any given quiz.
                    if ($student['year_level'] !== $yearLevel || mt_rand(1, 100) > 92) {
                        continue;
                    }

                    $this->queueAttempt($student, $exam, $this->scoreFor($student, $subject) - 6, $this->sittingTime($exam));
                }
            }
        }

        $this->flushAttempts(true);
    }

    /**
     * @param  array<string, mixed>  $student
     */
    private function scoreFor(array $student, string $subject): float
    {
        if ($student['featured'] !== null) {
            $weak = self::FEATURED[$student['featured']][4];
            $offset = in_array($subject, $weak, true) ? -11 : 3;
        } else {
            // Everyone has stronger and weaker subjects.
            $offset = $this->subjectOffsets[$student['id']][$subject] ??= mt_rand(-9, 9);
        }

        return $student['ability'] + $offset + mt_rand(-6, 6);
    }

    /**
     * @param  array<string, mixed>  $exam
     */
    private function sittingTime(array $exam, bool $retake = false): CarbonImmutable
    {
        // Open right now: sat within the last two hours, and already done.
        if ($exam['end']->greaterThan($this->now)) {
            return $exam['start']->addMinutes(mt_rand(0, 55))->addSeconds(mt_rand(0, 59));
        }

        $days = max(1, (int) $exam['start']->startOfDay()->diffInDays($exam['end']->startOfDay()));
        $half = intdiv($days + 1, 2);
        $day = $retake ? mt_rand($half, $days) : mt_rand(0, max(0, $half - 1));

        return $exam['start']->startOfDay()->addDays($day)->setTime(mt_rand(8, 15), mt_rand(0, 59), mt_rand(0, 59));
    }

    /**
     * @param  array<string, mixed>  $student  needs id and room_id
     * @param  array<string, mixed>  $exam
     */
    private function queueAttempt(
        array $student,
        array $exam,
        float $score,
        CarbonImmutable $startedAt,
        bool $submitted = true,
        float $answeredShare = 1.0,
    ): void {
        $this->pendingAttempts[] = [
            'user_id' => $student['id'],
            'room_id' => $student['room_id'],
            'exam' => $exam,
            'score' => max(5.0, min(100.0, $score)),
            'started_at' => $startedAt,
            'submitted' => $submitted,
            'answered_share' => $answeredShare,
        ];
    }

    /**
     * Writes the queued attempts, with their per-item rows when asked.
     *
     * Bulk inserts rather than models: at the default intake this is tens of
     * thousands of attempts and a few hundred thousand item rows.
     */
    private function flushAttempts(bool $withItems): void
    {
        foreach (array_chunk($this->pendingAttempts, 500) as $chunk) {
            $before = (int) DB::table('exam_attempts')->max('id');
            $sheets = [];
            $rows = [];

            foreach ($chunk as $i => $spec) {
                $sheets[$i] = $sheet = $this->answerSheet($spec);
                $total = count($sheet['order']);
                $submittedAt = $spec['submitted'] ? $spec['started_at']->addSeconds($sheet['seconds']) : null;

                $rows[] = [
                    'exam_id' => $spec['exam']['id'],
                    'room_id' => $spec['room_id'],
                    'user_id' => $spec['user_id'],
                    'status' => $spec['submitted'] ? ExamAttempt::STATUS_SUBMITTED : ExamAttempt::STATUS_IN_PROGRESS,
                    'total_items' => $total,
                    'duration_minutes' => $spec['exam']['duration'],
                    'answered_count' => count($sheet['answers']),
                    'correct_answers' => $sheet['correct'],
                    'score_percent' => $spec['submitted'] && $total > 0 ? round($sheet['correct'] / $total * 100, 2) : null,
                    'started_at' => $spec['started_at'],
                    'expires_at' => $spec['started_at']->addMinutes($spec['exam']['duration']),
                    'submitted_at' => $submittedAt,
                    'created_at' => $spec['started_at'],
                    'updated_at' => $submittedAt ?? $this->now,
                ];
            }

            DB::table('exam_attempts')->insert($rows);

            if (!$withItems) {
                continue;
            }

            // Looked back up by who, what and when; this seeder is the only
            // writer of these accounts' attempts.
            $ids = [];

            foreach (DB::table('exam_attempts')
                ->where('id', '>', $before)
                ->whereIn('user_id', array_values(array_unique(array_column($chunk, 'user_id'))))
                ->get(['id', 'user_id', 'exam_id', 'started_at']) as $row) {
                $ids[$row->user_id . '|' . $row->exam_id . '|' . substr((string) $row->started_at, 0, 19)] = (int) $row->id;
            }

            foreach ($chunk as $i => $spec) {
                $attemptId = $ids[$spec['user_id'] . '|' . $spec['exam']['id'] . '|' . $spec['started_at']->format('Y-m-d H:i:s')] ?? null;

                if ($attemptId !== null) {
                    $this->bufferSheet($attemptId, $spec, $sheets[$i]);
                }
            }

            $this->flushBuffers();
        }

        $this->pendingAttempts = [];
    }

    /**
     * How one sitting went, item by item: which items were answered, which
     * of those were right (easier items more often), and what was chosen
     * when wrong (most often the item's most tempting distractor).
     *
     * @param  array<string, mixed>  $spec
     * @return array{order: list<int>, answers: array<int, array{0: ?int, 1: bool, 2: int}>, correct: int, seconds: int}
     */
    private function answerSheet(array $spec): array
    {
        $items = $this->bankItems[$spec['exam']['bank']];
        $total = count($items);
        $order = array_keys($items);

        if ($spec['exam']['shuffle']) {
            shuffle($order);
        }

        $reach = $spec['submitted'] ? $total : max(1, (int) floor($total * $spec['answered_share']));
        $answered = [];

        foreach (array_slice($order, 0, $reach, true) as $position => $itemIndex) {
            // Now and then an item is left blank.
            if ($spec['submitted'] && mt_rand(1, 100) <= 2) {
                continue;
            }

            $weight = self::DIFFICULTY_WEIGHT[$items[$itemIndex]['difficulty']] ?? 0.7;
            // Weighted sampling (Efraimidis-Spirakis): the highest keys win.
            $answered[$position] = (mt_rand() / mt_getrandmax()) ** (1 / $weight);
        }

        $target = min(count($answered), (int) round($total * $spec['score'] / 100));
        arsort($answered);
        $right = array_flip(array_slice(array_keys($answered), 0, $target));
        ksort($answered);

        $seconds = 0;
        $pace = max(20, (int) ($spec['exam']['duration'] * 60 * (mt_rand(40, 85) / 100) / max(1, $total)));
        $answers = [];

        foreach (array_keys($answered) as $position) {
            $item = $items[$order[$position]];
            $isRight = isset($right[$position]) && $item['correct'] !== null;
            $seconds += (int) ($pace * (mt_rand(60, 140) / 100));

            if ($isRight) {
                $option = $item['correct'];
            } elseif ($item['wrong'] === []) {
                $option = null;
            } else {
                $option = mt_rand(1, 100) <= 60 ? $item['wrong'][0] : $item['wrong'][mt_rand(0, count($item['wrong']) - 1)];
            }

            $answers[$position] = [$option, $isRight, $seconds];
        }

        return [
            'order' => $order,
            'answers' => $answers,
            'correct' => count(array_filter($answers, fn (array $answer): bool => $answer[1])),
            'seconds' => min($spec['exam']['duration'] * 60 - 30, $seconds + mt_rand(30, 180)),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $sheet
     */
    private function bufferSheet(int $attemptId, array $spec, array $sheet): void
    {
        $items = $this->bankItems[$spec['exam']['bank']];
        $startedAt = $spec['started_at'];

        foreach ($sheet['order'] as $position => $itemIndex) {
            $this->buffer('exam_attempt_questions', [
                'exam_attempt_id' => $attemptId,
                'question_bank_question_id' => $items[$itemIndex]['id'],
                'item_number' => $position + 1,
                'is_bookmarked' => mt_rand(1, 100) <= 3,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);

            if (!isset($sheet['answers'][$position])) {
                continue;
            }

            [$optionId, $isRight, $offset] = $sheet['answers'][$position];
            $answeredAt = $startedAt->addSeconds($offset);

            // Someone mid-attempt cannot have answered in the future.
            if (!$spec['submitted']) {
                $answeredAt = $answeredAt->min($this->now->subSeconds(20));
            }

            $this->buffer('exam_attempt_answers', [
                'exam_attempt_id' => $attemptId,
                'question_bank_question_id' => $items[$itemIndex]['id'],
                'question_bank_option_id' => $optionId,
                'answer_text' => null,
                'is_correct' => $isRight,
                'answered_at' => $answeredAt,
                'created_at' => $answeredAt,
                'updated_at' => $answeredAt,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buffer(string $table, array $row): void
    {
        $this->buffers[$table][] = $row;

        if (count($this->buffers[$table]) >= 2000) {
            DB::table($table)->insert($this->buffers[$table]);
            $this->buffers[$table] = [];
        }
    }

    private function flushBuffers(): void
    {
        foreach ($this->buffers as $table => $rows) {
            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }

            $this->buffers[$table] = [];
        }
    }

    // ---------------------------------------------------------------- history

    /**
     * The third years' battery exams in the four subjects LOWER_YEAR_EXAMS
     * does not give them. Battery exams never feed the Estimated Outcome, so
     * the third years' card stays locked however many they sit.
     */
    private function seedThirdYearSemester(): void
    {
        $sat = array_column(self::LOWER_YEAR_EXAMS[3], 0);
        $thirdYears = array_values(array_filter($this->students, fn (array $student): bool => $student['year_level'] === 3));

        foreach (array_values(array_diff(self::SUBJECTS, $sat)) as $n => $subject) {
            $start = $this->termStart->addWeeks(3 + $n)->setTime(9, 0);

            if ($start->greaterThan($this->now->subDays(6))) {
                $start = $this->now->subDays(6 + $n * 2)->setTime(9, 0);
            }

            $exam = $this->exams["3:{$subject}"] = $this->insertExam([
                'title' => "{$subject} Unit Test",
                'subject' => $subject,
                'type' => Exam::TYPE_BATTERY,
                'description' => $this->describe($subject),
                'bank' => $this->libraryBanks[$subject],
                'duration' => 45,
                'start' => $start,
                'end' => $start->addDays(2)->setTime(17, 0),
                'results' => 'visible',
                'one_take_only' => true,
                'shuffle' => true,
                'owner' => $this->yearLevelTeacher(3),
            ], $this->sections[3]);

            foreach ($thirdYears as $student) {
                // A few are absent from any given quiz, and so stay locked.
                if (mt_rand(1, 100) > 92) {
                    continue;
                }

                $this->queueAttempt($student, $exam, $this->scoreFor($student, $subject) - 6, $this->sittingTime($exam));
            }
        }

        $this->flushAttempts(true);
    }

    /**
     * One graduating cohort per past year, each with the exam record they
     * built and the board result they got -- the shape the LET predictor's
     * retraining data has to arrive in, and what fills the archive views.
     */
    private function seedHistory(): void
    {
        $years = $this->historyYears();

        if ($years === 0) {
            return;
        }

        $this->command?->info("Building {$years} graduated cohort(s) of history...");
        $termYear = $this->termStart->year;

        for ($offset = $years; $offset >= 1; $offset--) {
            $graduationYear = $termYear - $offset;
            $admissionYear = $graduationYear - 4;
            $index = ($years - $offset) % count(self::HISTORIC_COHORT_RATIOS);
            $size = max(1, (int) round($this->cohortSize() * self::HISTORIC_COHORT_RATIOS[$index]));
            $closedAt = CarbonImmutable::create($graduationYear, 6, 30);
            $academicYear = sprintf('AY %d-%d', $graduationYear - 1, $graduationYear);

            // Their fourth-year sections, archived when they graduated.
            $sections = [];

            for ($section = 0; $section < (int) ceil($size / self::SECTION_SIZE); $section++) {
                $sections[] = $this->insertRoom(
                    sprintf('BLIS 4-%s (%s)', chr(ord('A') + $section), $academicYear),
                    $this->staff['chair'],
                    CarbonImmutable::create($graduationYear - 1, 8, 1),
                    $closedAt,
                );
            }

            // Their LLE review in the final year: a pre-test in September and a
            // mock board in February, each a series of six subject exams, both
            // before the LLE that September. That is the shape the model's
            // retraining rows come in (demo results are never trained on).
            $exams = [];
            $review = [
                'pretest' => [Exam::TYPE_PRE_TEST, "Pre-test, {$academicYear}", CarbonImmutable::create($graduationYear - 1, 9, 2), 'Pre-test'],
                'mock' => [Exam::TYPE_MOCK_BOARD, "Mock Board, {$academicYear}", CarbonImmutable::create($graduationYear, 2, 3), 'Mock Board Exam'],
            ];

            foreach ($review as $kind => [$type, $seriesName, $from, $label]) {
                $series = $this->insertSeries($seriesName, $type, $this->staff['chair'], $from->subWeeks(2));

                foreach (self::SUBJECTS as $i => $subject) {
                    $sat = $from->addDays($i * 2)->setTime(8, 0);

                    $exams[$kind][$subject] = $this->insertExam([
                        'title' => "{$subject} {$label} ({$academicYear})",
                        'subject' => $subject,
                        'type' => $type,
                        'series' => $series,
                        'description' => $this->describe($subject),
                        'bank' => $this->libraryBanks[$subject],
                        'duration' => 60,
                        'start' => $sat,
                        'end' => $sat->addDays(1)->setTime(17, 0),
                        'results' => 'visible',
                        'one_take_only' => false,
                        'shuffle' => false,
                        'owner' => $this->staff['chair'],
                    ], $sections, $closedAt);
                }
            }

            $rows = [];
            $members = [];

            for ($i = 0; $i < $size; $i++) {
                // Roughly one in nine leaves before finishing. They are
                // dropouts, not board failures; mixing the two would poison a
                // model trained on this data.
                $dropped = $i % 9 === 8;
                $person = $this->names->next();
                $leftAt = $dropped
                    ? CarbonImmutable::create($admissionYear + 2, 1, 15)->addDays(mt_rand(0, 120))
                    : $closedAt;

                $rows[] = ['is_active' => false] + $this->studentRow(
                    $person,
                    $admissionYear,
                    null,
                    $dropped ? User::STUDENT_STATUS_DROPPED : User::STUDENT_STATUS_GRADUATED,
                    $leftAt,
                );

                $members[$person['email']] = [
                    'room_id' => $sections[$i % count($sections)],
                    'ability' => $this->ability(66, 12),
                    'dropped' => $dropped,
                ];
            }

            $ids = $this->insertStudents($rows);
            $memberships = [];
            $graduates = [];

            foreach ($members as $email => $member) {
                $student = ['id' => $ids[$email], 'room_id' => $member['room_id']];
                $joinedAt = CarbonImmutable::create($graduationYear - 1, 8, 1)->addDays(mt_rand(0, 10));
                $memberships[] = ['room_id' => $member['room_id'], 'user_id' => $student['id'], 'created_at' => $joinedAt, 'updated_at' => $joinedAt];

                // A dropout stops partway through the pre-test -- censored,
                // not failed -- and never reaches the mock board.
                $sittings = $member['dropped']
                    ? ['pretest' => array_slice(self::SUBJECTS, 0, 2)]
                    : ['pretest' => self::SUBJECTS, 'mock' => self::SUBJECTS];

                foreach ($sittings as $kind => $subjects) {
                    foreach ($subjects as $subject) {
                        // The mock board comes after months of review.
                        $exam = $exams[$kind][$subject];

                        $this->queueAttempt(
                            $student,
                            $exam,
                            $member['ability'] + ($kind === 'mock' ? 4 : -4) + mt_rand(-7, 7),
                            $exam['start']->addHours(mt_rand(0, 8))->addMinutes(mt_rand(0, 59)),
                        );
                    }
                }

                if (!$member['dropped']) {
                    $graduates[$student['id']] = $member['ability'];
                }
            }

            foreach (array_chunk($memberships, 1000) as $chunk) {
                DB::table('room_user')->insert($chunk);
            }

            // Per-item rows only for the two most recent cohorts: item-level
            // views are all anyone opens an old exam for, and a decade of them
            // would be hundreds of thousands of rows nobody reads. Every
            // analytics path that matters reads the attempt's score, which all
            // of them carry.
            $this->flushAttempts($offset <= 2);

            $boardDate = CarbonImmutable::create($graduationYear, 9, 14);
            $this->insertBoardResults($graduates, $graduationYear, $boardDate, self::HISTORIC_PASS_RATES[$index]);
        }
    }

    /**
     * Board results that follow ability, loosely: the stronger students
     * mostly pass, but not in strict rank order. Every thirteenth outcome is
     * left unrecorded, and about a third of those who fail pass a year later.
     *
     * @param  array<int, float>  $graduates  user id => ability
     */
    private function insertBoardResults(array $graduates, int $examYear, CarbonImmutable $takenAt, float $passRate): void
    {
        $standing = [];

        foreach ($graduates as $userId => $ability) {
            $standing[$userId] = $ability + mt_rand(-6, 6);
        }

        arsort($standing);
        $passing = (int) round(count($standing) * $passRate);
        $rows = [];
        $position = 0;

        foreach (array_keys($standing) as $userId) {
            $passed = $position++ < $passing;

            if ($position % 13 === 0) {
                continue;
            }

            $rows[] = [
                'user_id' => $userId,
                'exam_year' => $examYear,
                'taken_at' => $takenAt,
                'result' => $passed ? LleResult::RESULT_PASS : LleResult::RESULT_FAIL,
                'rating' => $passed ? round(mt_rand(7500, 9200) / 100, 2) : round(mt_rand(6200, 7490) / 100, 2),
                'remarks' => null,
                'recorded_by' => $this->staff['chair'],
                'created_at' => $takenAt->addWeeks(6),
                'updated_at' => $takenAt->addWeeks(6),
            ];

            $retakeAt = $takenAt->addYear();

            if (!$passed && $position % 3 === 0 && $retakeAt->addWeeks(6)->lessThan($this->now)) {
                $rows[] = [
                    'user_id' => $userId,
                    'exam_year' => $examYear + 1,
                    'taken_at' => $retakeAt,
                    'result' => LleResult::RESULT_PASS,
                    'rating' => round(mt_rand(7500, 8300) / 100, 2),
                    'remarks' => 'Passed on retake.',
                    'recorded_by' => $this->staff['chair'],
                    'created_at' => $retakeAt->addWeeks(6),
                    'updated_at' => $retakeAt->addWeeks(6),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('lle_results')->insert($chunk);
        }
    }

    // -------------------------------------------------------------- review bot

    /**
     * Review questions made the way a teacher makes them: the app's own
     * generator, run over each subject's Library file. Four subjects have
     * been reviewed (most published, a couple declined); the last two still
     * wait in the queue. The final items of each file are left uncovered, so
     * the coverage report has gaps to show.
     */
    private function seedReviewBot(): void
    {
        $service = app(ReviewBotAdminService::class);

        foreach (self::SUBJECTS as $index => $subject) {
            $sourceBank = $this->libraryBanks[$subject];
            $sources = array_column($this->bankItems[$sourceBank], 'id');

            $service->generate(
                $service->reviewBotBankFor($subject, $this->staff['chair']),
                $this->staff['chair'],
                $subject,
                null,
                null,
                max(1, count($sources) - 3),
                $sourceBank,
            );

            $variants = DB::table('question_bank_questions')
                ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
                ->whereIn('source_question_id', $sources)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $generatedAt = $this->now->subDays(12 - $index)->setTime(14, 5);
            DB::table('question_bank_questions')->whereIn('id', $variants)->update([
                'created_at' => $generatedAt,
                'updated_at' => $generatedAt,
            ]);

            $declined = array_values(array_filter($variants, fn (int $id, int $i): bool => $i % 11 === 5, ARRAY_FILTER_USE_BOTH));
            $service->reject($declined);

            if ($index < 4) {
                $service->publish(array_values(array_diff($variants, $declined)), $this->staff['chair']);
                DB::table('question_bank_questions')->whereIn('id', $variants)->update(['updated_at' => $generatedAt->addDays(2)]);
            }
        }
    }

    /**
     * Students' practice on the published questions, in date order so the
     * mastery streaks come out the way the adaptive queue would have built
     * them: weak, learning, and mastered items all present.
     */
    private function seedReviewBotSessions(): void
    {
        $questions = DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('status', QuestionBankQuestion::STATUS_PUBLISHED)
            ->whereIn('source_question_id', array_merge(...array_map(
                fn (int $bankId): array => array_column($this->bankItems[$bankId], 'id'),
                array_values($this->libraryBanks),
            )))
            ->get(['id', 'subject', 'topic_id', 'subtopic_id']);

        if ($questions->isEmpty()) {
            return;
        }

        $options = [];

        foreach (DB::table('question_bank_options')->whereIn('question_bank_question_id', $questions->pluck('id'))->get() as $option) {
            $key = $option->is_correct ? 'correct' : 'wrong';
            $options[(int) $option->question_bank_question_id][$key][] = (int) $option->id;
        }

        $pools = $questions->groupBy('subject')->map(fn ($group) => $group->values()->all())->all();
        $subjects = array_keys($pools);
        $states = [];

        foreach ($this->students as $student) {
            if ($student['year_level'] < 3) {
                continue;
            }

            $count = $student['featured'] !== null ? 6 : (mt_rand(1, 100) <= 45 ? mt_rand(1, 5) : 0);
            $days = [];

            for ($n = 0; $n < $count; $n++) {
                $days[] = mt_rand(1, 40);
            }

            rsort($days);

            foreach ($days as $n => $daysAgo) {
                $subject = $subjects[mt_rand(0, count($subjects) - 1)];
                $pool = $pools[$subject];
                $picked = (array) array_rand($pool, min(count($pool), mt_rand(5, 10)));
                shuffle($picked);
                $first = $pool[$picked[0]];
                $weakArea = $n % 2 === 1;
                $startedAt = $this->now->subDays($daysAgo)->setTime(mt_rand(7, 22), mt_rand(0, 59));
                $accuracy = max(0.2, min(0.97, ($student['ability'] + mt_rand(-10, 10)) / 100));

                $answers = [];

                foreach ($picked as $slot => $poolIndex) {
                    $question = $pool[$poolIndex];
                    $isRight = mt_rand() / mt_getrandmax() < $accuracy && isset($options[$question->id]['correct']);
                    $choices = $options[$question->id][$isRight ? 'correct' : 'wrong'] ?? [null];
                    $answers[] = [$question, $choices[mt_rand(0, count($choices) - 1)], $isRight];
                }

                $correct = count(array_filter($answers, fn (array $answer): bool => $answer[2]));
                $submittedAt = $startedAt->addSeconds(count($answers) * mt_rand(35, 70));

                $attemptId = (int) DB::table('review_bot_attempts')->insertGetId([
                    'user_id' => $student['id'],
                    'subject' => $subject,
                    'topic_id' => $weakArea ? $first->topic_id : null,
                    'subtopic_id' => $weakArea ? $first->subtopic_id : null,
                    'difficulty' => null,
                    'source' => $weakArea ? ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA : ReviewBotAttempt::SOURCE_MANUAL,
                    'status' => ReviewBotAttempt::STATUS_SUBMITTED,
                    'total_items' => count($answers),
                    'answered_count' => count($answers),
                    'correct_answers' => $correct,
                    'score_percent' => round($correct / count($answers) * 100, 2),
                    'started_at' => $startedAt,
                    'submitted_at' => $submittedAt,
                    'created_at' => $startedAt,
                    'updated_at' => $submittedAt,
                ]);

                foreach ($answers as $slot => [$question, $optionId, $isRight]) {
                    $answeredAt = $startedAt->addSeconds(($slot + 1) * 50);

                    $this->buffer('review_bot_attempt_questions', [
                        'review_bot_attempt_id' => $attemptId,
                        'question_bank_question_id' => $question->id,
                        'item_number' => $slot + 1,
                        'created_at' => $startedAt,
                        'updated_at' => $startedAt,
                    ]);

                    $this->buffer('review_bot_attempt_answers', [
                        'review_bot_attempt_id' => $attemptId,
                        'question_bank_question_id' => $question->id,
                        'question_bank_option_id' => $optionId,
                        'answer_text' => null,
                        'is_correct' => $isRight,
                        'answered_at' => $answeredAt,
                        'created_at' => $answeredAt,
                        'updated_at' => $answeredAt,
                    ]);

                    // consecutive_correct buckets the item: 0 weak, 1 learning,
                    // 2 or more mastered.
                    $key = $student['id'] . '|' . $question->id;
                    $state = $states[$key] ?? ['times_seen' => 0, 'times_correct' => 0, 'consecutive_correct' => 0];
                    $states[$key] = [
                        'user_id' => $student['id'],
                        'question_bank_question_id' => (int) $question->id,
                        'times_seen' => $state['times_seen'] + 1,
                        'times_correct' => $state['times_correct'] + ($isRight ? 1 : 0),
                        'consecutive_correct' => $isRight ? $state['consecutive_correct'] + 1 : 0,
                        'last_seen_at' => $answeredAt,
                        'created_at' => $state['created_at'] ?? $answeredAt,
                        'updated_at' => $answeredAt,
                    ];
                }
            }
        }

        $this->flushBuffers();

        foreach (array_chunk(array_values($states), 1000) as $chunk) {
            DB::table('review_bot_item_states')->insert($chunk);
        }

        // One session left open, so "resume where you left off" has a record.
        $resuming = $this->featuredStudent('borderline');
        $pool = $pools[$subjects[0]];
        $picked = array_slice($pool, 0, min(5, count($pool)));
        $startedAt = $this->now->subMinutes(12);

        $attemptId = (int) DB::table('review_bot_attempts')->insertGetId([
            'user_id' => $resuming['id'],
            'subject' => $subjects[0],
            'topic_id' => null,
            'subtopic_id' => null,
            'difficulty' => null,
            'source' => ReviewBotAttempt::SOURCE_MANUAL,
            'status' => ReviewBotAttempt::STATUS_IN_PROGRESS,
            'total_items' => count($picked),
            'answered_count' => 0,
            'correct_answers' => 0,
            'score_percent' => null,
            'started_at' => $startedAt,
            'submitted_at' => null,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        DB::table('review_bot_attempt_questions')->insert(array_map(fn (object $question, int $slot): array => [
            'review_bot_attempt_id' => $attemptId,
            'question_bank_question_id' => $question->id,
            'item_number' => $slot + 1,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ], $picked, array_keys($picked)));
    }

    // ------------------------------------------------------------ audit trail

    private function seedAuditTrail(): void
    {
        $chair = $this->staff['chair'];
        $examiner = $this->staff['examiner'];
        $admin = $this->staff['admin'];
        $entries = [];

        foreach ($this->libraryBanks as $subject => $bankId) {
            $bank = DB::table('question_banks')->where('id', $bankId)->first(['created_by', 'title', 'created_at']);
            $at = CarbonImmutable::parse($bank->created_at);
            $entries[] = [(int) $bank->created_by, 'library.bank.create', 'question_bank', $bankId, 'Question bank created from parsed DOCX', ['title' => $bank->title, 'subject' => $subject], $at];
            $entries[] = [(int) $bank->created_by, 'library.mapping.queue', 'question_bank', $bankId, 'Queued AI item mapping for a set', ['title' => $bank->title], $at->addMinute()];
        }

        foreach ($this->sections as $yearLevel => $roomIds) {
            foreach ($roomIds as $roomId) {
                $room = DB::table('rooms')->where('id', $roomId)->first(['name', 'code', 'created_by', 'created_at']);
                $entries[] = [(int) $room->created_by, 'room.create', 'room', $roomId, 'Room created', ['name' => $room->name, 'code' => $room->code], CarbonImmutable::parse($room->created_at)];
            }
        }

        foreach ($this->exams as $exam) {
            $entries[] =[$exam['owner'], 'exam.create', 'exam', $exam['id'], 'Exam created', ['title' => $exam['title']], $exam['start']->subDays(4)->setTime(10, 20)];
        }

        $entries[] = [$examiner, 'room.exam.archive', 'exam', $this->exams['3:Library Organization and Management']['id'], 'Exam archived for room', ['room_id' => $this->sections[3][0]], $this->now->subDays(2)->setTime(16, 2)];
        $entries[] = [$chair, 'library.mapping.bulk_confirm', 'question_bank', $this->libraryBanks['Cataloging and Classification'], 'Bulk confirmed AI-suggested item tags', ['confirmed' => 16], $this->now->subDays(15)->setTime(9, 12)];
        $entries[] = [$chair, 'review-bot.admin.publish', 'question_bank_question', null, 'Published Review Bot question(s)', ['subject' => 'Cataloging and Classification'], $this->now->subDays(10)->setTime(15, 30)];
        $entries[] = [$chair, 'review-bot.admin.reject', 'question_bank_question', null, 'Declined Review Bot question(s)', ['subject' => 'Indexing and Abstracting'], $this->now->subDays(9)->setTime(11, 5)];
        $entries[] = [$chair, 'report.export.complete_results_xlsx', 'exam', $this->exams['mock:Indexing and Abstracting']['id'], 'Exported complete results (XLSX)', ['room_id' => $this->sections[4][0]], $this->now->subDays(6)->setTime(13, 44)];
        $entries[] = [$examiner, 'report.export.summary_pdf', 'exam', $this->exams['2:Cataloging and Classification']['id'], 'Exported summary report (PDF)', ['room_id' => $this->sections[2][0]], $this->now->subDays(5)->setTime(10, 3)];
        $entries[] = [$chair, 'prediction.let.run', 'room', $this->sections[4][0], 'Ran LET readiness prediction', ['students' => count(array_filter($this->students, fn (array $s): bool => $s['prediction_ready']))], $this->now->subDays(2)->setTime(14, 18)];
        $entries[] = [$admin, 'admin.user.create', 'user', $examiner, 'Admin created user account', ['role' => User::ROLE_STAFF_MASTER_EXAMINER], $this->termStart->subYear()];
        $entries[] = [$admin, 'admin.user.update', 'user', null, 'Admin updated user account', ['is_active' => false], $this->now->subMonths(3)];
        $entries[] = [$admin, 'settings.update', 'system_settings', null, 'Updated system settings', [], $this->now->subDays(20)->setTime(8, 51)];

        $rows = [];

        foreach ($entries as [$actor, $action, $type, $targetId, $description, $metadata, $at]) {
            $rows[] = [
                'actor_id' => $actor,
                'action' => $action,
                'target_type' => $type,
                'target_id' => $targetId,
                'description' => $description,
                'metadata' => json_encode($metadata),
                // Documentation address ranges (RFC 5737): realistic, never anyone's.
                'ip_address' => '203.0.113.' . mt_rand(10, 60),
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        // Students on the open exam, right now.
        $open = $this->exams['mock:' . self::SUBJECTS[4]];

        foreach (DB::table('exam_attempts')->where('exam_id', $open['id'])->get(['id', 'user_id', 'status', 'started_at', 'submitted_at']) as $attempt) {
            $rows[] = $this->studentAudit((int) $attempt->user_id, 'exam.attempt.start', (int) $attempt->id, 'Exam attempt started', $attempt->started_at);

            if ($attempt->status === ExamAttempt::STATUS_SUBMITTED) {
                $rows[] = $this->studentAudit((int) $attempt->user_id, 'exam.attempt.submit', (int) $attempt->id, 'Exam attempt submitted', $attempt->submitted_at);
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function studentAudit(int $userId, string $action, int $attemptId, string $description, string $at): array
    {
        return [
            'actor_id' => $userId,
            'action' => $action,
            'target_type' => 'exam_attempt',
            'target_id' => $attemptId,
            'description' => $description,
            'metadata' => json_encode([]),
            'ip_address' => '198.51.100.' . mt_rand(2, 250),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    // ----------------------------------------------------------------- report

    private function report(): void
    {
        if (!$this->command) {
            return;
        }

        $accounts = DB::table('users')->where('is_demo', true);
        $accountIds = (clone $accounts)->select('id');

        $this->command->table(['Demo data', 'Count'], [
            ['Staff accounts', (clone $accounts)->where('role', '!=', User::ROLE_STUDENT)->count()],
            ['Enrolled students', (clone $accounts)->where('role', User::ROLE_STUDENT)->where('student_status', User::STUDENT_STATUS_ENROLLED)->count()],
            ['Graduates and other leavers', (clone $accounts)->where('role', User::ROLE_STUDENT)->where('student_status', '!=', User::STUDENT_STATUS_ENROLLED)->count()],
            ['Rooms (sections)', DB::table('rooms')->whereIn('created_by', $accountIds)->count()],
            ['Exams', DB::table('exams')->whereIn('created_by', $accountIds)->count()],
            ['Exam attempts', DB::table('exam_attempts')->whereIn('user_id', $accountIds)->count()],
            ['Library files', count($this->bankItems)],
            ['Review Bot questions', DB::table('question_bank_questions')
                ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
                ->whereIn('source_question_id', array_merge(...array_map(fn (array $items): array => array_column($items, 'id'), $this->bankItems)))
                ->count()],
            ['Review Bot sessions', DB::table('review_bot_attempts')->whereIn('user_id', $accountIds)->count()],
            ['Board exam results', DB::table('lle_results')->whereIn('user_id', $accountIds)->count()],
        ]);

        $this->command->table(['Sign in as', 'Name', 'Email', 'Password'], array_map(
            fn (array $login): array => [$login['label'], $login['name'], $login['email'], $login['password']],
            array_values($this->logins),
        ));

        $this->command->info('Every other demo student uses the same password as the teachers.');
        $this->command->info('Remove all of it with: php artisan demo:purge');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The Library is shared by all staff, so its files are spread across the
     * three teachers.
     */
    private function teacher(int $turn): int
    {
        return [$this->staff['chair'], $this->staff['examiner'], $this->staff['teacher3']][$turn % 3];
    }

    /**
     * Who runs a year level: its sections and every exam given in them. A
     * teacher can only see reports and the live board for an exam and room
     * they both own, so the two always go together -- the chair has the
     * fourth years and their mock boards.
     */
    private function yearLevelTeacher(int $yearLevel): int
    {
        return match (true) {
            $yearLevel >= 4 => $this->staff['chair'],
            $yearLevel >= 2 => $this->staff['examiner'],
            default => $this->staff['teacher3'],
        };
    }

    private function cohortSize(): int
    {
        return max(4, (int) env('SHOWCASE_COHORT_SIZE', 300));
    }

    private function historyYears(): int
    {
        return max(0, (int) env('SHOWCASE_HISTORY_YEARS', 10));
    }

    /**
     * @return array<string, mixed>
     */
    private function featuredStudent(string $key): array
    {
        foreach ($this->students as $student) {
            if ($student['featured'] === $key) {
                return $student;
            }
        }

        return $this->students[0];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function nameOf(array $rows, string $email): string
    {
        foreach ($rows as $row) {
            if ($row['email'] === $email) {
                return $row['name'];
            }
        }

        return '';
    }
}
