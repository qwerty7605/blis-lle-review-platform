<?php

namespace Tests\Support;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared builders for the test suite: people, rooms, question sets, exams and
 * attempts, each with sensible defaults that a test overrides only where it
 * matters to what it checks.
 */
trait BuildsBlisData
{
    protected function makeAdmin(array $attributes = []): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, ...$attributes]);
    }

    protected function makeTeacher(array $attributes = []): User
    {
        return User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER, ...$attributes]);
    }

    protected function makeStudent(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'student_id' => (string) random_int(2000000, 2999999),
            'year_level' => 4,
            ...$attributes,
        ]);
    }

    /** @param  array<int, User>  $students */
    protected function makeRoom(User $owner, array $students = [], array $attributes = []): Room
    {
        $room = Room::create([
            'name' => 'BLIS ' . Str::upper(Str::random(3)),
            'code' => Str::upper(Str::random(6)),
            'created_by' => $owner->id,
            ...$attributes,
        ]);

        foreach ($students as $student) {
            $room->members()->attach($student->id);
        }

        return $room;
    }

    protected function makeTopic(string $subject = 'Reference, Bibliography and User Services', string $name = 'Reference Interview', ?User $owner = null): Topic
    {
        return Topic::firstOrCreate(
            ['subject' => $subject, 'name' => $name],
            ['created_by' => $owner?->id],
        );
    }

    protected function makeSubtopic(Topic $topic, string $name = 'Open questions', ?User $owner = null): Subtopic
    {
        return Subtopic::firstOrCreate(
            ['topic_id' => $topic->id, 'name' => $name],
            ['created_by' => $owner?->id],
        );
    }

    /**
     * A question set of $count four-choice questions; choice A is correct.
     */
    protected function makeBank(User $owner, int $count = 3, array $attributes = [], array $questionAttributes = []): QuestionBank
    {
        $subject = $attributes['subject'] ?? 'Reference, Bibliography and User Services';

        $bank = QuestionBank::create([
            'title' => $subject . ' Set ' . Str::random(4),
            'subject' => $subject,
            'source_filename' => 'set.docx',
            'total_items' => $count,
            'created_by' => $owner->id,
            ...$attributes,
        ]);

        for ($number = 1; $number <= $count; $number++) {
            $this->makeQuestion($bank, $number, $questionAttributes);
        }

        return $bank->load('questions.options');
    }

    protected function makeQuestion(QuestionBank $bank, int $number, array $attributes = []): QuestionBankQuestion
    {
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => $bank->subject,
            'item_number' => $number,
            'question_text' => "Which statement about item {$number} is correct?",
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => "Correct answer {$number}",
            ...$attributes,
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => "Correct answer {$number}", 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => "Wrong answer {$number}b", 'is_correct' => false],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => "Wrong answer {$number}c", 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => "Wrong answer {$number}d", 'is_correct' => false],
        ]);

        return $question->load('options');
    }

    /** @param  array<int, Room>  $rooms */
    protected function makeExam(User $owner, ?QuestionBank $bank = null, array $rooms = [], array $attributes = []): Exam
    {
        $exam = Exam::create([
            'title' => 'Exam ' . Str::random(5),
            'subject' => $bank?->subject ?? 'Reference, Bibliography and User Services',
            'question_bank_id' => $bank?->id,
            'total_items' => $bank ? $bank->questions()->count() : 3,
            'duration_minutes' => 30,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'results_visibility_mode' => 'visible',
            'created_by' => $owner->id,
            ...$attributes,
        ]);

        if ($bank) {
            $exam->syncQuestionBanks([$bank->id]);
        }

        foreach ($rooms as $room) {
            $exam->rooms()->attach($room->id, [
                'assigned_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $exam;
    }

    /**
     * A handed-in attempt. $answers holds one entry per question of the exam's
     * set, in order: true = correct, false = wrong, null = left blank.
     *
     * @param  array<int, bool|null>  $answers
     */
    protected function makeSubmittedAttempt(Exam $exam, Room $room, User $student, array $answers, ?Carbon $submittedAt = null): ExamAttempt
    {
        $submittedAt ??= now();
        $questions = $this->examQuestions($exam);
        $correct = count(array_filter($answers, fn ($answer) => $answer === true));
        $answered = count(array_filter($answers, fn ($answer) => $answer !== null));
        $total = max(1, count($answers));

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => count($answers),
            'duration_minutes' => (int) $exam->duration_minutes,
            'answered_count' => $answered,
            'correct_answers' => $correct,
            'score_percent' => round($correct / $total * 100, 2),
            'started_at' => $submittedAt->copy()->subMinutes(20),
            'expires_at' => $submittedAt->copy()->addMinutes(10),
            'submitted_at' => $submittedAt,
        ]);

        $this->fillAttempt($attempt, $questions, $answers);

        return $attempt;
    }

    /** @param  array<int, bool|null>  $answers */
    protected function makeOpenAttempt(Exam $exam, Room $room, User $student, array $answers = [], ?Carbon $startedAt = null): ExamAttempt
    {
        $startedAt ??= now()->subMinutes(5);
        $questions = $this->examQuestions($exam);

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => $questions->count(),
            'duration_minutes' => (int) $exam->duration_minutes,
            'answered_count' => 0,
            'correct_answers' => 0,
            'started_at' => $startedAt,
            'expires_at' => $startedAt->copy()->addMinutes((int) $exam->duration_minutes),
        ]);

        $this->fillAttempt($attempt, $questions, array_pad($answers, $questions->count(), null));

        return $attempt;
    }

    /**
     * Lets $student practise these Review Bot questions, as
     * ReviewBotEligibility asks: each one without a source item gets one, and
     * the student a submitted sitting that dealt every source, of an exam with
     * its results shown and one take only.
     *
     * @param  iterable<QuestionBankQuestion>  $reviewQuestions
     */
    protected function dealReviewBotSources(User $student, iterable $reviewQuestions, array $examAttributes = []): ExamAttempt
    {
        $owner = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $questions = collect($reviewQuestions)->values();
        $subject = $questions->first()?->subject ?? 'Reference, Bibliography and User Services';

        $bank = QuestionBank::create([
            'title' => 'Dealt items ' . Str::random(5),
            'subject' => $subject,
            'source_filename' => 'set.docx',
            'total_items' => $questions->count(),
            'created_by' => $owner->id,
        ]);

        $sourceIds = $questions->map(function (QuestionBankQuestion $question, int $index) use ($bank): int {
            if ($question->source_question_id) {
                return (int) $question->source_question_id;
            }

            $source = $this->makeQuestion($bank, $index + 1, [
                'subject' => $question->subject,
                'topic_id' => $question->topic_id,
                'subtopic_id' => $question->subtopic_id,
            ]);
            $question->forceFill(['source_question_id' => $source->id])->save();

            return (int) $source->id;
        })->unique()->values();

        $room = Room::create([
            'name' => 'Dealt ' . Str::upper(Str::random(3)),
            'code' => Str::upper(Str::random(6)),
            'created_by' => $owner->id,
        ]);
        $exam = $this->makeExam($owner, $bank, [$room], ['one_take_only' => true, ...$examAttributes]);
        $submittedAt = now()->subHour();

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => $sourceIds->count(),
            'duration_minutes' => 30,
            'answered_count' => 0,
            'correct_answers' => 0,
            'score_percent' => 0,
            'started_at' => $submittedAt->copy()->subMinutes(20),
            'expires_at' => $submittedAt->copy()->addMinutes(10),
            'submitted_at' => $submittedAt,
        ]);

        // Every item answered correctly, so the sitting gives the student no
        // weak area of its own.
        $sources = QuestionBankQuestion::query()->with('options')->findMany($sourceIds)->keyBy('id');

        foreach ($sourceIds as $index => $sourceId) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $sourceId,
                'item_number' => $index + 1,
                'is_bookmarked' => false,
            ]);

            $option = $sources->get($sourceId)?->options->firstWhere('is_correct', true);

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $sourceId,
                'question_bank_option_id' => $option?->id,
                'answer_text' => $option?->option_text,
                'is_correct' => true,
                'answered_at' => $submittedAt,
            ]);
        }

        $attempt->forceFill([
            'answered_count' => $sourceIds->count(),
            'correct_answers' => $sourceIds->count(),
            'score_percent' => 100,
        ])->save();

        return $attempt;
    }

    private function examQuestions(Exam $exam)
    {
        return QuestionBankQuestion::query()
            ->whereIn('question_bank_id', $exam->resolvedQuestionBankIds())
            ->with('options')
            ->orderBy('item_number')
            ->get();
    }

    private function fillAttempt(ExamAttempt $attempt, $questions, array $answers): void
    {
        foreach ($questions->values() as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $index + 1,
                'is_bookmarked' => false,
            ]);

            if (!array_key_exists($index, $answers) || $answers[$index] === null) {
                continue;
            }

            $option = $question->options->firstWhere('option_label', $answers[$index] ? 'A' : 'B');

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'question_bank_option_id' => $option?->id,
                'answer_text' => $option?->option_text,
                'is_correct' => (bool) $answers[$index],
                'answered_at' => $attempt->started_at,
            ]);
        }
    }
}
