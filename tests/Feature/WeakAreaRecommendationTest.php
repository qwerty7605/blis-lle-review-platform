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
 * The "What To Review Next" headline on the student Analytics page.
 *
 * What these guard against: ranking areas purely by percentage lets one
 * missed question (0% of 1) outrank a real, sustained weakness (33% of 6),
 * so the panel confidently sends the student to study the wrong thing.
 */
class WeakAreaRecommendationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_single_missed_item_does_not_outrank_a_sustained_weakness(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $thin = $this->makeTopic($staff, 'Authority Control');
        $sustained = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $thin, 1),
            $this->makeQuestion($bank, $sustained, 2),
            $this->makeQuestion($bank, $sustained, 3),
            $this->makeQuestion($bank, $sustained, 4),
            $this->makeQuestion($bank, $sustained, 5),
            $this->makeQuestion($bank, $sustained, 6),
            $this->makeQuestion($bank, $sustained, 7),
        ];

        // Authority Control: 0 of 1 (0%). Descriptive Cataloging: 2 of 6 (33%).
        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, true, true, false, false, false, false]);

        $weakArea = app(DashboardAnalyticsService::class)->buildWeakAreaRecommendation($student);

        $this->assertSame('topic', $weakArea['basis']);
        $this->assertSame('Descriptive Cataloging', $weakArea['topic']);
        $this->assertSame(6, $weakArea['attempted_count']);
    }

    public function test_ties_break_toward_the_area_with_more_evidence(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $fewer = $this->makeTopic($staff, 'Authority Control');
        $more = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $fewer, 1),
            $this->makeQuestion($bank, $fewer, 2),
            $this->makeQuestion($bank, $fewer, 3),
            $this->makeQuestion($bank, $more, 4),
            $this->makeQuestion($bank, $more, 5),
            $this->makeQuestion($bank, $more, 6),
            $this->makeQuestion($bank, $more, 7),
            $this->makeQuestion($bank, $more, 8),
        ];

        // Both sit at 0%; the one built on five answers is the firmer finding.
        $this->makeSubmittedAttempt($exam, $room, $student, $questions, array_fill(0, 8, false));

        $weakArea = app(DashboardAnalyticsService::class)->buildWeakAreaRecommendation($student);

        $this->assertSame('Descriptive Cataloging', $weakArea['topic']);
        $this->assertSame(5, $weakArea['attempted_count']);
    }

    public function test_a_student_with_only_thin_evidence_still_gets_a_recommendation(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $topic = $this->makeTopic($staff, 'Authority Control');

        $questions = [
            $this->makeQuestion($bank, $topic, 1),
            $this->makeQuestion($bank, $topic, 2),
        ];

        // Nothing clears the evidence bar, so the panel falls back rather than
        // going blank on a student who has only sat one short exam.
        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false]);

        $weakArea = app(DashboardAnalyticsService::class)->buildWeakAreaRecommendation($student);

        $this->assertNotSame([], $weakArea);
        $this->assertSame('Authority Control', $weakArea['topic']);
        $this->assertSame(2, $weakArea['attempted_count']);
    }

    public function test_the_weakest_area_is_chosen_by_traced_mastery_not_percent_correct(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $falling = $this->makeTopic($staff, 'Descriptive Cataloging');
        $climbing = $this->makeTopic($staff, 'Authority Control');

        // Mastery moves once per exam, so the two directions take two exams.
        // Descriptive Cataloging: 3 of 3, then 0 of 2 -> 60% overall.
        // Authority Control: 0 of 2, then 2 of 2 -> 50% overall.
        $first = [
            $this->makeQuestion($bank, $falling, 1),
            $this->makeQuestion($bank, $falling, 2),
            $this->makeQuestion($bank, $falling, 3),
            $this->makeQuestion($bank, $climbing, 4),
            $this->makeQuestion($bank, $climbing, 5),
        ];
        $second = [
            $this->makeQuestion($bank, $falling, 6),
            $this->makeQuestion($bank, $falling, 7),
            $this->makeQuestion($bank, $climbing, 8),
            $this->makeQuestion($bank, $climbing, 9),
        ];

        $laterExam = $exam->replicate();
        $laterExam->save();

        $this->makeSubmittedAttempt($exam, $room, $student, $first, [true, true, true, false, false], now()->subDays(2));
        $this->makeSubmittedAttempt($laterExam, $room, $student, $second, [false, false, true, true], now()->subDay());

        $weakArea = app(DashboardAnalyticsService::class)->buildWeakAreaRecommendation($student);

        // Percent correct would pick Authority Control, the lower score (50%
        // against 60%). Knowledge tracing picks the one the student is losing
        // rather than the one they are climbing into.
        $this->assertSame('Descriptive Cataloging', $weakArea['topic']);
        $this->assertSame('slipping', $weakArea['mastery']['trend']);
    }

    public function test_the_study_plan_does_not_lead_with_a_single_missed_item(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeContext();

        $thin = $this->makeTopic($staff, 'Authority Control');
        $sustained = $this->makeTopic($staff, 'Descriptive Cataloging');

        $questions = [
            $this->makeQuestion($bank, $thin, 1),
            $this->makeQuestion($bank, $sustained, 2),
            $this->makeQuestion($bank, $sustained, 3),
            $this->makeQuestion($bank, $sustained, 4),
            $this->makeQuestion($bank, $sustained, 5),
        ];

        $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false, false, false, true]);

        $plan = app(DashboardAnalyticsService::class)->buildStudyPlan($student);

        $this->assertNotEmpty($plan);
        $this->assertSame('Descriptive Cataloging', $plan[0]['label']);
    }

    /**
     * @return array{0: User, 1: User, 2: Room, 3: QuestionBank, 4: Exam}
     */
    private function makeContext(): array
    {
        $staff = $this->makeUser('teacher-weakarea@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student-weakarea@example.com', User::ROLE_STUDENT, '2301998');

        $room = Room::create([
            'name' => 'BLIS 1A',
            'code' => 'WKA001',
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
