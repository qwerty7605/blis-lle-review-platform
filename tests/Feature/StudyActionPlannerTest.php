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
 * The action plan behind "What To Review Next".
 *
 * The property worth protecting above the others: an action is only offered
 * when the thing it asks for exists. Suggesting a drill on an area with no
 * published questions sends the student to an empty session, which is worse
 * than saying nothing.
 */
class StudyActionPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_drill_is_only_offered_where_practice_is_published(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $covered = $this->makeTopic($staff, 'Descriptive Cataloging');
        $bare = $this->makeTopic($staff, 'Authority Control');

        $questions = [
            $this->makeQuestion($bank, $bare, 1),
            $this->makeQuestion($bank, $bare, 2),
            $this->makeQuestion($bank, $bare, 3),
            $this->makeQuestion($bank, $covered, 4),
            $this->makeQuestion($bank, $covered, 5),
            $this->makeQuestion($bank, $covered, 6),
        ];

        // Authority Control is the weaker of the two, but nothing is published
        // for it -- only Descriptive Cataloging has a set behind it.
        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false, false, false, false, true]);
        $this->publishReviewQuestions($bank, $covered, 5);

        $actions = app(DashboardAnalyticsService::class)->buildActionPlan($student);
        $drills = array_values(array_filter($actions, fn (array $a): bool => $a['cta_query'] !== null));

        $this->assertNotEmpty($drills);

        // Subject-level actions carry no topic_id at all, which is fine -- what
        // must never happen is a link pointing at the topic with nothing behind it.
        foreach ($drills as $drill) {
            $this->assertNotSame(
                (string) $bare->id,
                $drill['cta_query']['topic_id'] ?? null,
                'A drill was offered for an area with no published questions.',
            );
        }
    }

    public function test_the_weakest_area_without_practice_is_reported_rather_than_hidden(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $bare = $this->makeTopic($staff, 'Authority Control');

        $questions = [
            $this->makeQuestion($bank, $bare, 1),
            $this->makeQuestion($bank, $bare, 2),
            $this->makeQuestion($bank, $bare, 3),
        ];

        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false, false]);

        $actions = app(DashboardAnalyticsService::class)->buildActionPlan($student);
        $keys = array_column($actions, 'key');

        // Nothing is published anywhere, so the honest output is to say so
        // rather than to quietly recommend the next area down.
        $this->assertContains('practice_not_published', $keys);

        $flagged = collect($actions)->firstWhere('key', 'practice_not_published');
        $this->assertNull($flagged['cta_query']);
        $this->assertNull($flagged['cta_label']);
    }

    public function test_a_drill_is_never_larger_than_the_published_stock(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $topic, 1),
            $this->makeQuestion($bank, $topic, 2),
            $this->makeQuestion($bank, $topic, 3),
        ];

        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false, false]);

        // Only four published items exist, so a "10-item drill" would be a lie.
        $this->publishReviewQuestions($bank, $topic, 4);

        $actions = app(DashboardAnalyticsService::class)->buildActionPlan($student);
        $drill = collect($actions)->firstWhere('key', 'drill_weak_area');

        $this->assertNotNull($drill);
        $this->assertStringContainsString('4-item', $this->adviceText($drill));
        $this->assertStringContainsString('4-item', $drill['cta_label']);
    }

    public function test_ground_being_lost_is_ranked_above_the_weakest_area(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $slipping = $this->makeTopic($staff, 'Descriptive Cataloging');
        $weakest = $this->makeTopic($staff, 'Authority Control');

        // Mastery moves once per exam, so slipping takes two exams.
        // Descriptive Cataloging: 3 of 3, then 0 of 2 -> slipping.
        // Authority Control: 0 of 2, then 0 of 1 -> lower mastery outright.
        $first = [
            $this->makeQuestion($bank, $slipping, 1),
            $this->makeQuestion($bank, $slipping, 2),
            $this->makeQuestion($bank, $slipping, 3),
            $this->makeQuestion($bank, $weakest, 4),
            $this->makeQuestion($bank, $weakest, 5),
        ];
        $second = [
            $this->makeQuestion($bank, $slipping, 6),
            $this->makeQuestion($bank, $slipping, 7),
            $this->makeQuestion($bank, $weakest, 8),
        ];

        $laterExam = $exam->replicate();
        $laterExam->save();

        $this->makeSubmittedAttempt($exam, $room, $student, $first, [true, true, true, false, false], now()->subDays(2));
        $this->makeSubmittedAttempt($laterExam, $room, $student, $second, [false, false, false], now()->subDay());

        $this->publishReviewQuestions($bank, $slipping, 6);
        $this->publishReviewQuestions($bank, $weakest, 6);

        $actions = app(DashboardAnalyticsService::class)->buildActionPlan($student);

        $this->assertSame('reverse_slide', $actions[0]['key']);
        $this->assertStringContainsString('Descriptive Cataloging', $this->adviceText($actions[0]));
        $this->assertSame('urgent', $actions[0]['tone']);
    }

    public function test_the_same_area_is_never_suggested_twice(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $topic, 1),
            $this->makeQuestion($bank, $topic, 2),
            $this->makeQuestion($bank, $topic, 3),
            $this->makeQuestion($bank, $topic, 4),
        ];

        // Slipping and weakest are the same area here, so both rules fire on it.
        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [true, true, false, false]);
        $this->publishReviewQuestions($bank, $topic, 8);

        $actions = app(DashboardAnalyticsService::class)->buildActionPlan($student);
        $areaKeys = array_column($actions, 'area_key');

        $this->assertSame(array_unique($areaKeys), $areaKeys);
    }

    /**
     * The advice broken back down into one searchable string, so a test can
     * assert on what the student ends up reading without caring which block
     * of the breakdown a given phrase landed in.
     *
     * @param  array<string, mixed>  $action
     */
    private function adviceText(array $action): string
    {
        $parts = [$action['advice']['lead']];

        foreach ($action['advice']['blocks'] as $block) {
            $parts[] = $block['label'];
            $parts = array_merge($parts, $block['points']);
        }

        return implode(' ', $parts);
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

    /**
     * @return array{0: User, 1: User, 2: Room, 3: QuestionBank, 4: Exam}
     */
    private function makeContext(): array
    {
        $staff = $this->makeUser('teacher-actions@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student-actions@example.com', User::ROLE_STUDENT, '2301997');

        $room = Room::create([
            'name' => 'BLIS 1A',
            'code' => 'ACT001',
            'created_by' => $staff->id,
        ]);

        $bank = QuestionBank::create([
            'title' => 'Cataloging Set',
            'subject' => 'Cataloging',
            'source_filename' => 'cataloging.docx',
            'total_items' => 8,
            'created_by' => $staff->id,
        ]);

        $exam = Exam::create([
            'title' => 'Cataloging Quiz',
            'subject' => 'Cataloging',
            'description' => null,
            'question_bank_id' => $bank->id,
            'total_items' => 8,
            'duration_minutes' => 45,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => true,
            'shuffle_questions' => false,
            'results_visibility_mode' => 'visible',
            'created_by' => $staff->id,
        ]);

        return [$staff, $student, $room, $bank, $exam];
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
        ?\Illuminate\Support\Carbon $submittedAt = null,
    ): ExamAttempt {
        $correctAnswers = count(array_filter($correctness));
        $submittedAt ??= now();

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
            'started_at' => $submittedAt->copy()->subHour(),
            'submitted_at' => $submittedAt,
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
                'answered_at' => $submittedAt,
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
