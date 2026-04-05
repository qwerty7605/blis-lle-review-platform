<?php

namespace Database\Seeders;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use App\Services\ExamAttemptService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class JinxinStudentHistorySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $defaultPassword = Hash::make('pass');

            $teacher = User::query()->updateOrCreate([
                'email' => 'teacher@example.com',
            ], [
                'name' => 'Teacher User',
                'first_name' => 'Teacher',
                'middle_name' => null,
                'last_name' => 'User',
                'student_id' => null,
                'role' => User::ROLE_STAFF_MASTER_EXAMINER,
                'is_active' => true,
                'password' => $defaultPassword,
            ]);

            $student = $this->resolveStudent($defaultPassword);

            $peerStudents = User::query()
                ->whereIn('email', [
                    'student1@example.com',
                    'student2@example.com',
                    'student3@example.com',
                    'student4@example.com',
                    'student5@example.com',
                ])
                ->get()
                ->keyBy('email');

            $questionBanks = collect($this->subjectBlueprints())
                ->mapWithKeys(fn (array $blueprint, string $key) => [
                    $key => $this->seedQuestionBank($teacher, $key, $blueprint),
                ]);

            $rooms = collect($this->roomBlueprints())
                ->mapWithKeys(fn (array $blueprint, string $code) => [
                    $code => $this->seedRoom($teacher, $code, $blueprint),
                ]);

            $this->seedRoomMembers(
                $rooms,
                $student,
                $peerStudents,
            );

            $exams = collect($this->examBlueprints())
                ->mapWithKeys(fn (array $blueprint, string $key) => [
                    $key => $this->seedExam($teacher, $blueprint, $questionBanks, $rooms),
                ]);

            $this->seedAttemptHistory(
                $student,
                $exams,
                $questionBanks,
                $rooms,
                app(ExamAttemptService::class),
            );
        }, 5);
    }

    /**
     * @return array<string, array{label: string, topics: list<string>}>
     */
    private function subjectBlueprints(): array
    {
        return [
            'cataloging' => [
                'label' => 'Cataloging and Classification',
                'topics' => [
                    'chief source of information',
                    'main entry selection',
                    'call number construction',
                    'authority control',
                    'subject heading verification',
                    'MARC 245 field review',
                    'edition statement handling',
                    'series tracing',
                    'copy cataloging review',
                    'Cutter number assignment',
                    'shelving sequence check',
                    'bibliographic description',
                ],
            ],
            'indexing' => [
                'label' => 'Indexing and Abstracting',
                'topics' => [
                    'keyword extraction',
                    'descriptor selection',
                    'scope note writing',
                    'controlled vocabulary use',
                    'broader and narrower terms',
                    'indexing consistency',
                    'precision and recall balance',
                    'see and see also references',
                    'depth indexing',
                    'subject analysis',
                    'abstract opening sentence',
                    'search term normalization',
                ],
            ],
            'information_technology' => [
                'label' => 'Information Technology',
                'topics' => [
                    'database backup scheduling',
                    'network troubleshooting',
                    'metadata schema selection',
                    'cybersecurity basics',
                    'spreadsheet validation formulas',
                    'data privacy handling',
                    'hardware maintenance routines',
                    'cloud storage planning',
                    'systems analysis workflow',
                    'web usability checks',
                    'file format preservation',
                    'user access permissions',
                ],
            ],
            'reference_services' => [
                'label' => 'Reference Services',
                'topics' => [
                    'reference interview opening',
                    'print source selection',
                    'database search strategy',
                    'citation verification',
                    'referral service handling',
                    'reader advisory support',
                    'website evaluation',
                    'OPAC search refinement',
                    'question clarification',
                    'service ethics',
                    'user instruction',
                    'fact checking',
                ],
            ],
            'library_management' => [
                'label' => 'Library Management',
                'topics' => [
                    'staffing schedule review',
                    'budget allocation',
                    'collection policy updates',
                    'circulation workflow',
                    'facility planning',
                    'service key performance indicators',
                    'strategic planning',
                    'project coordination',
                    'service evaluation',
                    'records management',
                    'procurement requests',
                    'policy communication',
                ],
            ],
            'selection_acquisition' => [
                'label' => 'Selection and Acquisition',
                'topics' => [
                    'vendor evaluation',
                    'selection tools',
                    'gift policy screening',
                    'acquisition workflow',
                    'standing orders',
                    'licensing terms review',
                    'budget prioritization',
                    'duplicate title checks',
                    'request verification',
                    'serials renewal',
                    'order tracking',
                    'invoice matching',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{name: string, created_at: string}>
     */
    private function roomBlueprints(): array
    {
        return [
            'B3COHORT26' => [
                'name' => 'BLIS 3A Review Cohort',
                'created_at' => '2026-01-10 08:00:00',
            ],
            'B3MOCK26' => [
                'name' => 'BLIS Mock Board Prep',
                'created_at' => '2026-03-25 08:00:00',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function examBlueprints(): array
    {
        return [
            'cataloging_a' => [
                'title' => 'Cataloging Foundations Drill A',
                'description' => 'Introductory cataloging drill for third-year review students.',
                'bank_keys' => ['cataloging'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-01-18 08:00:00',
                'schedule_end_at' => '2026-01-20 23:59:00',
            ],
            'indexing_a' => [
                'title' => 'Indexing Practice Quiz A',
                'description' => 'Early-semester indexing practice quiz.',
                'bank_keys' => ['indexing'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-01-25 08:00:00',
                'schedule_end_at' => '2026-01-27 23:59:00',
            ],
            'reference_a' => [
                'title' => 'Reference Services Case Drill A',
                'description' => 'Scenario-based reference services practice.',
                'bank_keys' => ['reference_services'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-02-03 08:00:00',
                'schedule_end_at' => '2026-02-05 23:59:00',
            ],
            'it_a' => [
                'title' => 'Information Technology Review Quiz A',
                'description' => 'Core IT concepts check for BLIS students.',
                'bank_keys' => ['information_technology'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-02-11 08:00:00',
                'schedule_end_at' => '2026-02-13 23:59:00',
            ],
            'library_a' => [
                'title' => 'Library Management Situational Quiz A',
                'description' => 'Library management decision-making drill.',
                'bank_keys' => ['library_management'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-02-19 08:00:00',
                'schedule_end_at' => '2026-02-21 23:59:00',
            ],
            'selection_a' => [
                'title' => 'Selection and Acquisition Practice A',
                'description' => 'Collection development and acquisition workflow practice.',
                'bank_keys' => ['selection_acquisition'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-02-27 08:00:00',
                'schedule_end_at' => '2026-03-01 23:59:00',
            ],
            'cataloging_b' => [
                'title' => 'Cataloging Foundations Drill B',
                'description' => 'Follow-up cataloging drill after remediation.',
                'bank_keys' => ['cataloging'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-03-12 08:00:00',
                'schedule_end_at' => '2026-03-14 23:59:00',
            ],
            'reference_b' => [
                'title' => 'Reference Services Case Drill B',
                'description' => 'Second reference drill with stronger case-based questions.',
                'bank_keys' => ['reference_services'],
                'room_codes' => ['B3COHORT26'],
                'total_items' => 10,
                'duration_minutes' => 25,
                'schedule_start_at' => '2026-04-02 08:00:00',
                'schedule_end_at' => '2026-04-04 23:59:00',
            ],
            'it_b' => [
                'title' => 'Information Technology Review Quiz B',
                'description' => 'Current open IT review set for Jinxin and classmates.',
                'bank_keys' => ['information_technology'],
                'room_codes' => ['B3MOCK26'],
                'total_items' => 10,
                'duration_minutes' => 30,
                'schedule_start_at' => '2026-04-05 08:00:00',
                'schedule_end_at' => '2026-04-10 23:59:00',
            ],
            'mock_board_upcoming' => [
                'title' => 'Comprehensive Mock Board Prep 1',
                'description' => 'Upcoming mixed-subject mock board for the review group.',
                'bank_keys' => [
                    'cataloging',
                    'indexing',
                    'information_technology',
                    'reference_services',
                ],
                'room_codes' => ['B3MOCK26'],
                'total_items' => 20,
                'duration_minutes' => 45,
                'schedule_start_at' => '2026-04-09 08:00:00',
                'schedule_end_at' => '2026-04-12 23:59:00',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attemptBlueprints(): array
    {
        return [
            [
                'exam_key' => 'cataloging_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'cataloging',
                'started_at' => '2026-01-18 09:12:00',
                'submitted_at' => '2026-01-18 09:31:00',
                'question_offset' => 0,
                'correct_item_numbers' => [1, 3, 5, 7, 10],
                'skipped_item_numbers' => [9],
                'bookmarked_item_numbers' => [2, 6],
            ],
            [
                'exam_key' => 'indexing_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'indexing',
                'started_at' => '2026-01-25 10:04:00',
                'submitted_at' => '2026-01-25 10:23:00',
                'question_offset' => 1,
                'correct_item_numbers' => [1, 2, 4, 6, 8, 10],
                'skipped_item_numbers' => [],
                'bookmarked_item_numbers' => [3],
            ],
            [
                'exam_key' => 'reference_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'reference_services',
                'started_at' => '2026-02-03 14:10:00',
                'submitted_at' => '2026-02-03 14:32:00',
                'question_offset' => 0,
                'correct_item_numbers' => [1, 2, 5, 6, 8, 10],
                'skipped_item_numbers' => [4],
                'bookmarked_item_numbers' => [4, 7],
            ],
            [
                'exam_key' => 'it_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'information_technology',
                'started_at' => '2026-02-11 16:20:00',
                'submitted_at' => '2026-02-11 16:42:00',
                'question_offset' => 2,
                'correct_item_numbers' => [1, 2, 4, 5, 7, 8, 10],
                'skipped_item_numbers' => [],
                'bookmarked_item_numbers' => [6],
            ],
            [
                'exam_key' => 'library_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'library_management',
                'started_at' => '2026-02-19 08:35:00',
                'submitted_at' => '2026-02-19 08:57:00',
                'question_offset' => 1,
                'correct_item_numbers' => [1, 3, 4, 6, 7, 8, 10],
                'skipped_item_numbers' => [],
                'bookmarked_item_numbers' => [2, 9],
            ],
            [
                'exam_key' => 'selection_a',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'selection_acquisition',
                'started_at' => '2026-02-27 11:08:00',
                'submitted_at' => '2026-02-27 11:29:00',
                'question_offset' => 0,
                'correct_item_numbers' => [2, 3, 4, 6, 8, 10],
                'skipped_item_numbers' => [5],
                'bookmarked_item_numbers' => [1, 5],
            ],
            [
                'exam_key' => 'cataloging_b',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'cataloging',
                'started_at' => '2026-03-12 09:18:00',
                'submitted_at' => '2026-03-12 09:37:00',
                'question_offset' => 2,
                'correct_item_numbers' => [1, 2, 4, 5, 6, 8, 9],
                'skipped_item_numbers' => [],
                'bookmarked_item_numbers' => [3, 8],
            ],
            [
                'exam_key' => 'reference_b',
                'room_code' => 'B3COHORT26',
                'bank_key' => 'reference_services',
                'started_at' => '2026-04-02 13:02:00',
                'submitted_at' => '2026-04-02 13:23:00',
                'question_offset' => 2,
                'correct_item_numbers' => [1, 2, 3, 5, 6, 7, 8, 10],
                'skipped_item_numbers' => [],
                'bookmarked_item_numbers' => [4],
            ],
        ];
    }

    private function resolveStudent(string $defaultPassword): User
    {
        $student = User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where(function ($query): void {
                $query
                    ->whereRaw('LOWER(name) = ?', ['jinxin santos farquad'])
                    ->orWhereIn('email', ['person1@example.com', 'jinxin.farquad@example.com'])
                    ->orWhere('student_id', '2300002');
            })
            ->orderByRaw("
                CASE
                    WHEN LOWER(name) = 'jinxin santos farquad' THEN 0
                    WHEN email = 'person1@example.com' THEN 1
                    WHEN email = 'jinxin.farquad@example.com' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('id')
            ->first();

        if (!$student) {
            $student = new User([
                'email' => 'jinxin.farquad@example.com',
            ]);
        }

        User::query()
            ->whereKeyNot($student->id)
            ->where('student_id', '2300002')
            ->update([
                'student_id' => null,
            ]);

        $student->fill([
            'name' => 'Jinxin Santos Farquad',
            'first_name' => 'Jinxin',
            'middle_name' => 'Santos',
            'last_name' => 'Farquad',
            'email' => filled($student->email) ? $student->email : 'jinxin.farquad@example.com',
            'student_id' => '2300002',
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
            'password' => $defaultPassword,
        ]);

        $student->save();

        return $student->fresh();
    }

    /**
     * @param  array{label: string, topics: list<string>}  $blueprint
     */
    private function seedQuestionBank(User $teacher, string $key, array $blueprint): QuestionBank
    {
        $bank = QuestionBank::query()->updateOrCreate([
            'created_by' => $teacher->id,
            'title' => "{$blueprint['label']} Seed Bank",
        ], [
            'subject' => $blueprint['label'],
            'source_filename' => "{$key}-seed.docx",
            'total_items' => count($blueprint['topics']),
        ]);

        $activeItemNumbers = [];
        $labels = ['A', 'B', 'C', 'D'];

        foreach ($blueprint['topics'] as $index => $topic) {
            $itemNumber = $index + 1;
            $activeItemNumbers[] = $itemNumber;
            $correctIndex = ($index + strlen($key)) % count($labels);

            $optionTexts = [
                'Skip the documented procedure and rely on memory alone.',
                'Delay the review until after the output is already released.',
                "Review the standard procedure for {$topic} before finalizing the task.",
                'Use a random shortcut instead of recording the required details.',
            ];

            $correctText = $optionTexts[2];
            $orderedOptions = [];

            foreach ($labels as $labelIndex => $label) {
                $sourceIndex = match ($labelIndex) {
                    $correctIndex => 2,
                    0 => 0,
                    1 => 1,
                    default => 3,
                };

                if ($labelIndex > $correctIndex) {
                    $sourceIndex = match ($labelIndex) {
                        1 => 0,
                        2 => 1,
                        default => 3,
                    };
                }

                $orderedOptions[] = [
                    'label' => $label,
                    'text' => $labelIndex === $correctIndex
                        ? $correctText
                        : $optionTexts[$sourceIndex],
                    'is_correct' => $labelIndex === $correctIndex,
                ];
            }

            $question = QuestionBankQuestion::query()->updateOrCreate([
                'question_bank_id' => $bank->id,
                'item_number' => $itemNumber,
            ], [
                'question_text' => "{$blueprint['label']} practice item {$itemNumber}: Which action best addresses {$topic}?",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => $labels[$correctIndex],
                'answer_text' => $correctText,
            ]);

            foreach ($orderedOptions as $sortOrder => $optionData) {
                QuestionBankOption::query()->updateOrCreate([
                    'question_bank_question_id' => $question->id,
                    'option_label' => $optionData['label'],
                ], [
                    'sort_order' => $sortOrder + 1,
                    'option_text' => $optionData['text'],
                    'is_correct' => $optionData['is_correct'],
                ]);
            }
        }

        QuestionBankQuestion::query()
            ->where('question_bank_id', $bank->id)
            ->whereNotIn('item_number', $activeItemNumbers)
            ->delete();

        if ((int) $bank->total_items !== count($blueprint['topics'])) {
            $bank->forceFill(['total_items' => count($blueprint['topics'])])->save();
        }

        return $bank->fresh(['questions.options']);
    }

    /**
     * @param  array{name: string, created_at: string}  $blueprint
     */
    private function seedRoom(User $teacher, string $code, array $blueprint): Room
    {
        $room = Room::query()->updateOrCreate([
            'code' => $code,
        ], [
            'name' => $blueprint['name'],
            'created_by' => $teacher->id,
        ]);

        $createdAt = CarbonImmutable::parse($blueprint['created_at']);

        DB::table('rooms')
            ->where('id', $room->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

        return $room->fresh();
    }

    /**
     * @param  Collection<string, Room>  $rooms
     * @param  Collection<string, User>  $peerStudents
     */
    private function seedRoomMembers(Collection $rooms, User $student, Collection $peerStudents): void
    {
        $membersByRoom = [
            'B3COHORT26' => [
                [$student, '2026-01-11 09:00:00'],
                [$peerStudents->get('student1@example.com'), '2026-01-11 09:05:00'],
                [$peerStudents->get('student2@example.com'), '2026-01-11 09:07:00'],
                [$peerStudents->get('student3@example.com'), '2026-01-11 09:10:00'],
            ],
            'B3MOCK26' => [
                [$student, '2026-03-26 08:30:00'],
                [$peerStudents->get('student2@example.com'), '2026-03-26 08:35:00'],
                [$peerStudents->get('student4@example.com'), '2026-03-26 08:40:00'],
                [$peerStudents->get('student5@example.com'), '2026-03-26 08:45:00'],
            ],
        ];

        foreach ($membersByRoom as $roomCode => $members) {
            $room = $rooms->get($roomCode);
            if (!$room) {
                continue;
            }

            foreach ($members as [$member, $joinedAt]) {
                if (!$member instanceof User) {
                    continue;
                }

                $timestamp = CarbonImmutable::parse($joinedAt);

                DB::table('room_user')->updateOrInsert([
                    'room_id' => $room->id,
                    'user_id' => $member->id,
                ], [
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  Collection<string, QuestionBank>  $questionBanks
     * @param  Collection<string, Room>  $rooms
     */
    private function seedExam(
        User $teacher,
        array $blueprint,
        Collection $questionBanks,
        Collection $rooms,
    ): Exam {
        $banks = collect($blueprint['bank_keys'])
            ->map(fn (string $bankKey) => $questionBanks->get($bankKey))
            ->filter()
            ->values();

        $scheduleStartAt = CarbonImmutable::parse((string) $blueprint['schedule_start_at']);
        $scheduleEndAt = CarbonImmutable::parse((string) $blueprint['schedule_end_at']);

        $exam = Exam::query()->updateOrCreate([
            'created_by' => $teacher->id,
            'title' => (string) $blueprint['title'],
        ], [
            'subject' => Exam::summarizeQuestionBankSubject($banks) ?? ($banks->first()?->subject),
            'description' => $blueprint['description'],
            'question_bank_id' => $banks->first()?->id,
            'total_items' => (int) $blueprint['total_items'],
            'duration_minutes' => (int) $blueprint['duration_minutes'],
            'scheduled_at' => $scheduleStartAt,
            'schedule_start_at' => $scheduleStartAt,
            'schedule_end_at' => $scheduleEndAt,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
        ]);

        $exam->syncQuestionBanks(
            $banks
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        $assignmentTimestamp = $scheduleStartAt->subDays(4);
        $roomSyncPayload = collect($blueprint['room_codes'])
            ->mapWithKeys(function (string $roomCode) use ($rooms, $teacher, $assignmentTimestamp): array {
                $room = $rooms->get($roomCode);

                if (!$room) {
                    return [];
                }

                return [
                    $room->id => [
                        'assigned_by' => $teacher->id,
                        'created_at' => $assignmentTimestamp,
                        'updated_at' => $assignmentTimestamp,
                    ],
                ];
            })
            ->all();

        $exam->rooms()->sync($roomSyncPayload);

        return $exam->fresh(['questionBanks']);
    }

    /**
     * @param  Collection<string, Exam>  $exams
     * @param  Collection<string, QuestionBank>  $questionBanks
     * @param  Collection<string, Room>  $rooms
     */
    private function seedAttemptHistory(
        User $student,
        Collection $exams,
        Collection $questionBanks,
        Collection $rooms,
        ExamAttemptService $attemptService,
    ): void {
        $attemptBlueprints = collect($this->attemptBlueprints());
        $examIds = $attemptBlueprints
            ->pluck('exam_key')
            ->map(fn (string $examKey) => $exams->get($examKey)?->id)
            ->filter()
            ->values()
            ->all();

        if ($examIds !== []) {
            ExamAttempt::query()
                ->where('user_id', $student->id)
                ->whereIn('exam_id', $examIds)
                ->delete();
        }

        foreach ($attemptBlueprints as $blueprint) {
            $exam = $exams->get((string) $blueprint['exam_key']);
            $bank = $questionBanks->get((string) $blueprint['bank_key']);
            $room = $rooms->get((string) $blueprint['room_code']);

            if (!$exam || !$bank || !$room) {
                continue;
            }

            $startedAt = CarbonImmutable::parse((string) $blueprint['started_at']);
            $submittedAt = CarbonImmutable::parse((string) $blueprint['submitted_at']);
            $correctItemNumbers = collect($blueprint['correct_item_numbers'] ?? [])
                ->map(fn ($number) => (int) $number)
                ->values();
            $skippedItemNumbers = collect($blueprint['skipped_item_numbers'] ?? [])
                ->map(fn ($number) => (int) $number)
                ->values();
            $bookmarkedItemNumbers = collect($blueprint['bookmarked_item_numbers'] ?? [])
                ->map(fn ($number) => (int) $number)
                ->values();

            $selectedQuestions = $this->selectQuestions(
                $bank->questions,
                (int) $exam->total_items,
                (int) ($blueprint['question_offset'] ?? 0),
            );

            $attempt = ExamAttempt::query()->create([
                'exam_id' => $exam->id,
                'room_id' => $room->id,
                'user_id' => $student->id,
                'status' => ExamAttempt::STATUS_IN_PROGRESS,
                'total_items' => (int) $exam->total_items,
                'duration_minutes' => (int) $exam->duration_minutes,
                'answered_count' => 0,
                'correct_answers' => 0,
                'score_percent' => null,
                'started_at' => $startedAt,
                'expires_at' => $startedAt->addMinutes((int) $exam->duration_minutes),
                'submitted_at' => $submittedAt,
            ]);

            foreach ($selectedQuestions as $index => $question) {
                $itemNumber = $index + 1;

                ExamAttemptQuestion::query()->create([
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                    'item_number' => $itemNumber,
                    'is_bookmarked' => $bookmarkedItemNumbers->contains($itemNumber),
                ]);

                if ($skippedItemNumbers->contains($itemNumber)) {
                    continue;
                }

                $isCorrect = $correctItemNumbers->contains($itemNumber);
                $options = $question->options->sortBy('sort_order')->values();
                $selectedOption = $isCorrect
                    ? $options->first(fn (QuestionBankOption $option) => $option->is_correct)
                    : $options
                        ->filter(fn (QuestionBankOption $option) => !$option->is_correct)
                        ->values()
                        ->get(($itemNumber - 1) % max(1, $options->where('is_correct', false)->count()));

                if (!$selectedOption) {
                    continue;
                }

                ExamAttemptAnswer::query()->create([
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                    'question_bank_option_id' => $selectedOption->id,
                    'answer_text' => null,
                    'is_correct' => $isCorrect,
                    'answered_at' => $startedAt->addSeconds($itemNumber * 90),
                ]);
            }

            $attemptService->refreshMetrics($attempt, true);
        }
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $questions
     * @return Collection<int, QuestionBankQuestion>
     */
    private function selectQuestions(Collection $questions, int $targetCount, int $offset = 0): Collection
    {
        $orderedQuestions = $questions
            ->sortBy('item_number')
            ->values();

        if ($orderedQuestions->isEmpty()) {
            return collect();
        }

        $selection = collect();
        $questionCount = $orderedQuestions->count();

        for ($index = 0; $index < $targetCount; $index++) {
            $selection->push($orderedQuestions[($offset + $index) % $questionCount]);
        }

        return $selection;
    }
}
