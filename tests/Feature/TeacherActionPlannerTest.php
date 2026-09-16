<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\Topic;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The teacher-side "What To Do Next" panel.
 *
 * The ranking claim worth protecting: a weak topic with no published practice
 * outranks everything else, because students whose worst area it is are being
 * told on their own dashboard that nothing exists for them to practise. That
 * is the only state on the list where someone is actively stuck.
 */
class TeacherActionPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_weak_topic_with_no_practice_is_ranked_first(): void
    {
        [$staff, $room, $bank, $exam] = $this->makeContext();

        $uncovered = $this->makeTopic($staff, 'Authority Control');
        $covered = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $uncovered, 1),
            $this->makeQuestion($bank, $uncovered, 2),
            $this->makeQuestion($bank, $uncovered, 3),
            $this->makeQuestion($bank, $covered, 4),
            $this->makeQuestion($bank, $covered, 5),
            $this->makeQuestion($bank, $covered, 6),
        ];

        // Both topics are weak across the cohort; only one has practice behind it.
        foreach ($this->makeStudents($room, 3) as $student) {
            $this->makeSubmittedAttempt($exam, $room, $student, $questions, array_fill(0, 6, false));
        }

        $this->publishReviewQuestions($bank, $covered, 5);

        $actions = app(DashboardAnalyticsService::class)->buildTeacherActionPlan($staff);

        $this->assertSame('publish_practice', $actions[0]['key']);
        $this->assertStringContainsString('Authority Control', $actions[0]['advice']['lead']);
        $this->assertSame('dashboard-review-bot-generator', $actions[0]['cta_route']);
    }

    public function test_a_topic_that_already_has_practice_is_a_reteach_not_a_publish(): void
    {
        [$staff, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $topic, 1),
            $this->makeQuestion($bank, $topic, 2),
            $this->makeQuestion($bank, $topic, 3),
        ];

        foreach ($this->makeStudents($room, 3) as $student) {
            $this->makeSubmittedAttempt($exam, $room, $student, $questions, array_fill(0, 3, false));
        }

        $this->publishReviewQuestions($bank, $topic, 6);

        $actions = app(DashboardAnalyticsService::class)->buildTeacherActionPlan($staff);
        $keys = array_column($actions, 'key');

        // Practice exists, so more questions are not the answer -- the advice
        // should be about teaching the topic again, not publishing for it.
        $this->assertContains('reteach_topic', $keys);
        $this->assertNotContains('publish_practice', $keys);
    }

    public function test_an_item_almost_everyone_missed_is_flagged_as_possibly_miskeyed(): void
    {
        [$staff, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $topic, 1),
            $this->makeQuestion($bank, $topic, 2),
        ];

        // Six students: all get item 1 wrong, all get item 2 right. Item 1 is
        // the one a teacher should go and look at.
        foreach ($this->makeStudents($room, 6) as $student) {
            $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, true]);
        }

        $actions = app(DashboardAnalyticsService::class)->buildTeacherActionPlan($staff);
        $flagged = collect($actions)->firstWhere('key', 'check_answer_key');

        $this->assertNotNull($flagged);
        $this->assertStringContainsString('Item 1', $flagged['advice']['lead']);
        $this->assertSame('dashboard-library', $flagged['cta_route']);
    }

    public function test_a_merely_hard_item_answered_by_too_few_students_is_not_flagged(): void
    {
        [$staff, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');
        $questions = [$this->makeQuestion($bank, $topic, 1)];

        // Two students missing one item is not evidence of a bad answer key.
        foreach ($this->makeStudents($room, 2) as $student) {
            $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false]);
        }

        $actions = app(DashboardAnalyticsService::class)->buildTeacherActionPlan($staff);

        $this->assertNotContains('check_answer_key', array_column($actions, 'key'));
    }

    public function test_pending_machine_tags_are_surfaced(): void
    {
        [$staff, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');
        $question = $this->makeQuestion($bank, $topic, 1);
        $question->update(['tag_status' => QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW]);

        foreach ($this->makeStudents($room, 3) as $student) {
            $this->makeSubmittedAttempt($exam, $room, $student, [$question], [true]);
        }

        $actions = app(DashboardAnalyticsService::class)->buildTeacherActionPlan($staff);
        $tags = collect($actions)->firstWhere('key', 'confirm_tags');

        $this->assertNotNull($tags);
        $this->assertStringContainsString('1 of your questions', $tags['advice']['lead']);
    }

    /**
     * @return array{0: User, 1: Room, 2: QuestionBank, 3: Exam}
     */
    private function makeContext(): array
    {
        $staff = $this->makeUser('teacher-plan@example.com', User::ROLE_STAFF_MASTER_EXAMINER);

        $room = Room::create([
            'name' => 'BLIS 1A',
            'code' => 'TPL001',
            'created_by' => $staff->id,
        ]);

        $bank = QuestionBank::create([
            'title' => 'Cataloging Set',
            'subject' => 'Cataloging',
            'source_filename' => 'cataloging.docx',
            'total_items' => 6,
            'created_by' => $staff->id,
        ]);

        $exam = Exam::create([
            'title' => 'Cataloging Quiz',
            'subject' => 'Cataloging',
            'description' => null,
            'question_bank_id' => $bank->id,
            'total_items' => 6,
            'duration_minutes' => 45,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => true,
            'shuffle_questions' => false,
            'results_visibility_mode' => 'visible',
            'created_by' => $staff->id,
        ]);

        return [$staff, $room, $bank, $exam];
    }

    /**
     * @return list<User>
     */
    private function makeStudents(Room $room, int $count): array
    {
        $students = [];

        for ($index = 1; $index <= $count; $index++) {
            $student = $this->makeUser("student-plan{$index}@example.com", User::ROLE_STUDENT, '23019' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $room->members()->attach($student->id);
            $students[] = $student;
        }

        return $students;
    }

    /** Keeps item_number unique per bank across repeated calls. */
    private int $publishedSoFar = 0;

    private function publishReviewQuestions(QuestionBank $bank, Topic $topic, int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $this->publishedSoFar++;
            QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'subject' => 'Cataloging',
                'topic_id' => $topic->id,
                'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'item_number' => 900 + $this->publishedSoFar,
                'question_text' => "Review question {$index}",
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => 'A',
                'answer_text' => 'Correct answer',
            ]);
        }
    }

    private function makeTopic(User $staff, string $name): Topic
    {
        return Topic::create([
            'subject' => 'Cataloging',
            'name' => $name,
            'created_by' => $staff->id,
        ]);
    }

    private function makeQuestion(QuestionBank $bank, Topic $topic, int $itemNumber): QuestionBankQuestion
    {
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging',
            'topic_id' => $topic->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'item_number' => $itemNumber,
            'question_text' => "Question {$itemNumber}",
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Correct answer',
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'Correct answer', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'Incorrect answer', 'is_correct' => false],
        ]);

        return $question->load('options');
    }

    /**
     * @param  array<int, QuestionBankQuestion>  $questions
     * @param  array<int, bool>  $correctness
     */
    private function makeSubmittedAttempt(
        Exam $exam,
        Room $room,
        User $student,
        array $questions,
        array $correctness,
    ): ExamAttempt {
        $correctAnswers = count(array_filter($correctness));

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => count($questions),
            'duration_minutes' => 45,
            'answered_count' => count($questions),
            'correct_answers' => $correctAnswers,
            'score_percent' => round(($correctAnswers / max(1, count($questions))) * 100, 2),
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
        ]);

        foreach ($questions as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $index + 1,
                'is_bookmarked' => false,
            ]);

            $selectedOption = $question->options->firstWhere('option_label', !empty($correctness[$index]) ? 'A' : 'B');

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'question_bank_option_id' => $selectedOption?->id,
                'answer_text' => $selectedOption?->option_text,
                'is_correct' => !empty($correctness[$index]),
                'answered_at' => now(),
            ]);
        }

        return $attempt;
    }

    private function makeUser(string $email, string $role, ?string $studentId = null): User
    {
        return User::factory()->create([
            'name' => ucfirst(strtok($email, '@')) . ' User',
            'email' => $email,
            'role' => $role,
            'student_id' => $studentId,
            'is_active' => true,
        ]);
    }
}
