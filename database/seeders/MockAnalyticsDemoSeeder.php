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
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Services\QuestionMappingService;
use App\Services\ExamAttemptService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for exercising the student Analytics page (score bands, subject
 * breakdown, trend chart, LET readiness prediction) without a teacher having
 * to manually build exams and take them. Two accounts, two very different
 * performance profiles:
 *
 *   student@example.com  -- failing (~38% average, weak in every subject)
 *   student1@example.com -- average (~76% average, hovering the 75% passing
 *                            line, a mix of strong and weak subjects)
 *
 * Both get exactly 8 submitted attempts spanning all 6 core subjects --
 * the minimum LetReadinessFeatureBuilder needs to move the LET Readiness
 * panel out of "not enough history yet" and into a real prediction.
 *
 * Modeled on JinxinStudentHistorySeeder's approach (question bank / room /
 * exam / attempt seeding), trimmed to just what these two profiles need.
 */
class MockAnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $defaultPassword = Hash::make('pass');

            $teacher = User::query()->firstOrCreate(
                ['email' => 'teacher@example.com'],
                [
                    'name' => 'Teacher User',
                    'first_name' => 'Teacher',
                    'last_name' => 'User',
                    'role' => User::ROLE_STAFF_MASTER_EXAMINER,
                    'is_active' => true,
                    'password' => $defaultPassword,
                ],
            );

            $failingStudent = User::query()->updateOrCreate(
                ['email' => 'student@example.com'],
                [
                    'name' => 'Student User',
                    'first_name' => 'Student',
                    'last_name' => 'User',
                    'role' => User::ROLE_STUDENT,
                    'is_active' => true,
                    'password' => $defaultPassword,
                ],
            );

            $averageStudent = User::query()->updateOrCreate(
                ['email' => 'student1@example.com'],
                [
                    'name' => 'Student One',
                    'first_name' => 'Student',
                    'last_name' => 'One',
                    'role' => User::ROLE_STUDENT,
                    'is_active' => true,
                    'password' => $defaultPassword,
                ],
            );

            $questionBanks = collect($this->subjectBlueprints())
                ->mapWithKeys(fn (array $blueprint, string $key) => [
                    $key => $this->seedQuestionBank($teacher, $key, $blueprint),
                ]);

            $room = $this->seedRoom($teacher);
            $this->seedRoomMembers($room, [$failingStudent, $averageStudent]);

            $exams = collect($this->examBlueprints())
                ->mapWithKeys(fn (array $blueprint, string $key) => [
                    $key => $this->seedExam($teacher, $key, $blueprint, $questionBanks, $room),
                ]);

            $attemptService = app(ExamAttemptService::class);

            $this->seedAttemptHistory($failingStudent, $exams, $questionBanks, $room, $this->failingStudentScores(), $attemptService);
            $this->seedAttemptHistory($averageStudent, $exams, $questionBanks, $room, $this->averageStudentScores(), $attemptService);
        }, 5);
    }

    /**
     * Demo content is built from the same curated taxonomy real teacher uploads
     * get classified into (QuestionMappingService::curatedTopics()), rather
     * than a hand-written list of plausible-sounding labels.
     *
     * The hardcoded list this replaced invented its own names -- "MARC 245
     * field review", "website evaluation" -- so demo history and real uploads
     * landed on two different vocabularies for the same concepts, and every
     * weak-area reading was split between them. The curated list is the one
     * the classifier assigns to, so it is the one demo data has to speak.
     *
     * One item per curated subtopic, which also gives the demo data depth at
     * the subtopic level the analytics already support.
     *
     * @return array<string, array{label: string, topics: list<array{topic: string, subtopic: string}>}>
     */
    private function subjectBlueprints(): array
    {
        // Stable keys, because examBlueprints() addresses banks by them.
        $keysBySubject = [
            'Cataloging and Classification' => 'cataloging',
            'Indexing and Abstracting' => 'indexing',
            'Information Technology' => 'information_technology',
            'Reference Services' => 'reference_services',
            'Library Management' => 'library_management',
            'Selection and Acquisition' => 'selection_acquisition',
        ];

        $blueprints = [];

        foreach (app(QuestionMappingService::class)->curatedTopics() as $subject => $topics) {
            $key = $keysBySubject[$subject] ?? null;

            if ($key === null) {
                continue;
            }

            $pairs = [];

            foreach ($topics as $topic => $subtopics) {
                foreach ($subtopics as $subtopic) {
                    $pairs[] = ['topic' => $topic, 'subtopic' => $subtopic];
                }
            }

            $blueprints[$key] = ['label' => $subject, 'topics' => $pairs];
        }

        return $blueprints;
    }


    /**
     * 8 exams covering all 6 subjects (cataloging and reference doubled up),
     * dated across roughly three months so the trend chart has a real span.
     *
     * @return array<string, array{bank_key: string, title: string, submitted_at: string}>
     */
    private function examBlueprints(): array
    {
        return [
            'cataloging_a' => ['bank_key' => 'cataloging', 'title' => 'Cataloging Foundations Drill A', 'submitted_at' => '2026-06-02 09:20:00'],
            'indexing_a' => ['bank_key' => 'indexing', 'title' => 'Indexing Practice Quiz A', 'submitted_at' => '2026-06-16 10:05:00'],
            'reference_a' => ['bank_key' => 'reference_services', 'title' => 'Reference Services Case Drill A', 'submitted_at' => '2026-06-30 14:15:00'],
            'it_a' => ['bank_key' => 'information_technology', 'title' => 'Information Technology Review Quiz A', 'submitted_at' => '2026-07-14 16:30:00'],
            'library_a' => ['bank_key' => 'library_management', 'title' => 'Library Management Situational Quiz A', 'submitted_at' => '2026-07-28 08:45:00'],
            'selection_a' => ['bank_key' => 'selection_acquisition', 'title' => 'Selection and Acquisition Practice A', 'submitted_at' => '2026-08-11 11:10:00'],
            'cataloging_b' => ['bank_key' => 'cataloging', 'title' => 'Cataloging Foundations Drill B', 'submitted_at' => '2026-08-18 09:25:00'],
            'reference_b' => ['bank_key' => 'reference_services', 'title' => 'Reference Services Case Drill B', 'submitted_at' => '2026-08-25 13:40:00'],
        ];
    }

    /**
     * How many of each exam's 10 items this student gets right, in the same
     * order as examBlueprints() above. Kept deliberately uneven (not a flat
     * repeating number) so the trend chart and up/down indicators look like
     * a real student's history instead of an obviously synthetic flat line.
     *
     * @return array<string, int>
     */
    private function failingStudentScores(): array
    {
        return [
            'cataloging_a' => 3,
            'indexing_a' => 4,
            'reference_a' => 2,
            'it_a' => 5,
            'library_a' => 3,
            'selection_a' => 4,
            'cataloging_b' => 4,
            'reference_b' => 5,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function averageStudentScores(): array
    {
        return [
            'cataloging_a' => 7,
            'indexing_a' => 8,
            'reference_a' => 6,
            'it_a' => 9,
            'library_a' => 7,
            'selection_a' => 8,
            'cataloging_b' => 8,
            'reference_b' => 8,
        ];
    }

    /**
     * @param  array{label: string, topics: list<string>}  $blueprint
     */
    private function seedQuestionBank(User $teacher, string $key, array $blueprint): QuestionBank
    {
        $bank = QuestionBank::query()->updateOrCreate([
            'created_by' => $teacher->id,
            'title' => "{$blueprint['label']} Demo Bank",
        ], [
            'subject' => $blueprint['label'],
            'source_filename' => "{$key}-demo.docx",
            'total_items' => count($blueprint['topics']),
        ]);

        $labels = ['A', 'B', 'C', 'D'];
        $activeItemNumbers = [];

        foreach ($blueprint['topics'] as $index => $topic) {
            $itemNumber = $index + 1;
            $activeItemNumbers[] = $itemNumber;
            $correctIndex = ($index + strlen($key)) % count($labels);

            // Real Library uploads get topic_id from the AI item-mapping
            // pipeline (QuestionMappingService) -- this demo data needs the
            // same field populated directly so "What To Review Next" can
            // name an actual weak topic, not just fall back to "Uncategorized".
            $topicRow = Topic::query()->firstOrCreate(
                ['subject' => $blueprint['label'], 'name' => $topic['topic']],
                ['created_by' => $teacher->id],
            );

            // Resolved the same way QuestionMappingService resolves a real
            // upload's mapping, so demo rows are indistinguishable in shape
            // from classified ones.
            $subtopicRow = Subtopic::query()->firstOrCreate(
                ['topic_id' => $topicRow->id,
                'subtopic_id' => $subtopicRow->id, 'name' => $topic['subtopic']],
                ['created_by' => $teacher->id],
            );

            $optionTexts = [
                'Skip the documented procedure and rely on memory alone.',
                'Delay the review until after the output is already released.',
                "Follow the standard procedure for {$topic['subtopic']} before finalizing the task.",
                'Use a random shortcut instead of recording the required details.',
            ];

            $question = QuestionBankQuestion::query()->updateOrCreate([
                'question_bank_id' => $bank->id,
                'item_number' => $itemNumber,
            ], [
                'subject' => $blueprint['label'],
                'topic_id' => $topicRow->id,
                'question_text' => "{$blueprint['label']} demo item {$itemNumber}: Which action best addresses {$topic['subtopic']}?",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => $labels[$correctIndex],
                'answer_text' => $optionTexts[2],
            ]);

            foreach ($labels as $labelIndex => $label) {
                QuestionBankOption::query()->updateOrCreate([
                    'question_bank_question_id' => $question->id,
                    'option_label' => $label,
                ], [
                    'sort_order' => $labelIndex + 1,
                    'option_text' => $labelIndex === $correctIndex ? $optionTexts[2] : $optionTexts[$labelIndex % 2],
                    'is_correct' => $labelIndex === $correctIndex,
                ]);
            }
        }

        QuestionBankQuestion::query()
            ->where('question_bank_id', $bank->id)
            ->whereNotIn('item_number', $activeItemNumbers)
            ->delete();

        return $bank->fresh(['questions.options']);
    }

    private function seedRoom(User $teacher): Room
    {
        $room = Room::query()->updateOrCreate([
            'code' => 'ANALYTICDEMO',
        ], [
            'name' => 'Analytics Demo Room',
            'created_by' => $teacher->id,
        ]);

        $createdAt = CarbonImmutable::parse('2026-05-25 08:00:00');

        DB::table('rooms')->where('id', $room->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $room->fresh();
    }

    /**
     * @param  list<User>  $students
     */
    private function seedRoomMembers(Room $room, array $students): void
    {
        $joinedAt = CarbonImmutable::parse('2026-05-26 09:00:00');

        foreach ($students as $student) {
            DB::table('room_user')->updateOrInsert([
                'room_id' => $room->id,
                'user_id' => $student->id,
            ], [
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ]);
        }
    }

    /**
     * @param  array{bank_key: string, title: string, submitted_at: string}  $blueprint
     * @param  Collection<string, QuestionBank>  $questionBanks
     */
    private function seedExam(User $teacher, string $key, array $blueprint, Collection $questionBanks, Room $room): Exam
    {
        $bank = $questionBanks->get($blueprint['bank_key']);
        $submittedAt = CarbonImmutable::parse($blueprint['submitted_at']);
        $scheduleStartAt = $submittedAt->subDays(3);

        $exam = Exam::query()->updateOrCreate([
            'created_by' => $teacher->id,
            'title' => $blueprint['title'],
        ], [
            'subject' => $bank->subject,
            'description' => "Demo exam seeded for Analytics testing ({$key}).",
            'question_bank_id' => $bank->id,
            'total_items' => (int) $bank->total_items,
            'duration_minutes' => 25,
            'scheduled_at' => $scheduleStartAt,
            'schedule_start_at' => $scheduleStartAt,
            'schedule_end_at' => $submittedAt->addDays(3),
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
        ]);

        $exam->syncQuestionBanks([$bank->id]);
        $exam->rooms()->sync([
            $room->id => [
                'assigned_by' => $teacher->id,
                'created_at' => $scheduleStartAt,
                'updated_at' => $scheduleStartAt,
            ],
        ]);

        return $exam->fresh(['questionBanks']);
    }

    /**
     * @param  Collection<string, Exam>  $exams
     * @param  Collection<string, QuestionBank>  $questionBanks
     * @param  array<string, int>  $correctCountsByExamKey
     */
    private function seedAttemptHistory(
        User $student,
        Collection $exams,
        Collection $questionBanks,
        Room $room,
        array $correctCountsByExamKey,
        ExamAttemptService $attemptService,
    ): void {
        ExamAttempt::query()
            ->where('user_id', $student->id)
            ->whereIn('exam_id', $exams->pluck('id')->filter()->all())
            ->delete();

        foreach ($this->examBlueprints() as $examKey => $blueprint) {
            $exam = $exams->get($examKey);
            $bank = $questionBanks->get($blueprint['bank_key']);
            $correctCount = $correctCountsByExamKey[$examKey] ?? 0;

            if (!$exam || !$bank) {
                continue;
            }

            $submittedAt = CarbonImmutable::parse($blueprint['submitted_at']);
            $startedAt = $submittedAt->subMinutes(20);
            $questions = $bank->questions->sortBy('item_number')->values();

            // Rotates which items land correct/wrong per exam+student instead
            // of always "the first N items" -- otherwise the same trailing
            // topic in every subject's list would show up as everyone's
            // weakest topic regardless of score, which is a giveaway this is
            // synthetic data rather than a real per-student pattern.
            $rotationOffset = ($questions->count() > 0)
                ? (crc32($examKey) + $student->id) % $questions->count()
                : 0;

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

            foreach ($questions as $index => $question) {
                $itemNumber = $index + 1;
                $isCorrect = (($index + $rotationOffset) % max(1, $questions->count())) < $correctCount;

                ExamAttemptQuestion::query()->create([
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                    'item_number' => $itemNumber,
                    'is_bookmarked' => false,
                ]);

                $options = $question->options->sortBy('sort_order')->values();
                $selectedOption = $isCorrect
                    ? $options->first(fn (QuestionBankOption $option) => $option->is_correct)
                    : $options->first(fn (QuestionBankOption $option) => !$option->is_correct);

                if (!$selectedOption) {
                    continue;
                }

                ExamAttemptAnswer::query()->create([
                    'exam_attempt_id' => $attempt->id,
                    'question_bank_question_id' => $question->id,
                    'question_bank_option_id' => $selectedOption->id,
                    'answer_text' => null,
                    'is_correct' => $isCorrect,
                    'answered_at' => $startedAt->addSeconds($itemNumber * 60),
                ]);
            }

            $attemptService->refreshMetrics($attempt, true);
        }
    }
}
