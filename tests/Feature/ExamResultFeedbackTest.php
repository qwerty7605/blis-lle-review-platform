<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExamResultFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_results_still_return_targeted_feedback_and_practice_recommendation(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeExamContext('hidden');
        $topic = Topic::create([
            'subject' => 'Cataloging',
            'name' => 'Dewey Decimal Classification',
            'created_by' => $staff->id,
        ]);

        $questions = [
            $this->makeAssessmentQuestion($bank, 'Cataloging', $topic, 1, 'Question 1'),
            $this->makeAssessmentQuestion($bank, 'Cataloging', $topic, 2, 'Question 2'),
            $this->makeAssessmentQuestion($bank, 'Cataloging', $topic, 3, 'Question 3'),
        ];

        $remedial = $this->makeRemedialQuestion($bank, 'Cataloging', $topic, 4, 'Remedial Topic Question');
        $this->assertNotNull($remedial);

        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, $questions, [true, false, false]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/student/exam-attempts/{$attempt->id}");

        $response
            ->assertOk()
            ->assertJsonPath('exam.results_visibility_mode', 'hidden')
            ->assertJsonPath('questions.0.correct_answer', null)
            ->assertJsonPath('questions.0.answer.is_correct', null)
            ->assertJsonPath('feedback.subject_breakdown.0.subject', 'Cataloging')
            ->assertJsonPath('feedback.topic_weakness.label', 'Cataloging • Dewey Decimal Classification')
            ->assertJsonPath('feedback.readiness_level.label', 'Needs Reinforcement')
            ->assertJsonPath('feedback.primary_focus.basis', 'topic')
            ->assertJsonPath('feedback.primary_focus.topic', 'Dewey Decimal Classification')
            ->assertJsonPath('feedback.primary_focus.subject', 'Cataloging')
            ->assertJsonPath('feedback.recommended_practice_area.label', 'Cataloging • Dewey Decimal Classification')
            ->assertJsonPath('feedback.practice.can_start', true)
            ->assertJsonPath('feedback.practice.available_questions', 1);

        $this->assertArrayNotHasKey('explanation', $response->json('questions.0'));
        $this->assertNotEmpty($response->json('feedback.recommended_actions'));
    }

    public function test_feedback_falls_back_to_subject_when_topic_evidence_is_too_small(): void
    {
        [$staff, $student, $room, $bank, $exam] = $this->makeExamContext('visible');
        $topic = Topic::create([
            'subject' => 'Cataloging',
            'name' => 'Authority Control',
            'created_by' => $staff->id,
        ]);

        $questions = [
            $this->makeAssessmentQuestion($bank, 'Cataloging', $topic, 1, 'Question 1'),
            $this->makeAssessmentQuestion($bank, 'Cataloging', $topic, 2, 'Question 2'),
            $this->makeAssessmentQuestion($bank, 'Cataloging', null, 3, 'Question 3'),
        ];

        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, $questions, [false, false, false]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/student/exam-attempts/{$attempt->id}");

        $response
            ->assertOk()
            ->assertJsonPath('feedback.primary_focus.basis', 'subject')
            ->assertJsonPath('feedback.primary_focus.subject', 'Cataloging')
            ->assertJsonPath('feedback.primary_focus.topic', null);
    }

    /**
     * @return array{0: User, 1: User, 2: Room, 3: QuestionBank, 4: Exam}
     */
    private function makeExamContext(string $resultsVisibilityMode): array
    {
        $staff = $this->makeUser('teacher-feedback@example.com', User::ROLE_STAFF_MASTER_EXAMINER);
        $student = $this->makeUser('student-feedback@example.com', User::ROLE_STUDENT, '2301999');
        $room = Room::create([
            'name' => 'BLIS 1A',
            'code' => 'FBK001',
            'created_by' => $staff->id,
        ]);
        $bank = QuestionBank::create([
            'title' => 'Cataloging Set',
            'subject' => 'Cataloging',
            'source_filename' => 'cataloging.docx',
            'total_items' => 3,
            'created_by' => $staff->id,
        ]);
        $exam = Exam::create([
            'title' => 'Cataloging Quiz',
            'subject' => 'Cataloging',
            'description' => null,
            'question_bank_id' => $bank->id,
            'total_items' => 3,
            'duration_minutes' => 45,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => true,
            'shuffle_questions' => false,
            'results_visibility_mode' => $resultsVisibilityMode,
            'created_by' => $staff->id,
        ]);

        return [$staff, $student, $room, $bank, $exam];
    }

    /**
     * @param  array<int, bool>  $correctness
     */
    private function makeSubmittedAttempt(
        Exam $exam,
        Room $room,
        User $student,
        array $questions,
        array $correctness,
    ): ExamAttempt {
        $answeredCount = count($questions);
        $correctAnswers = count(array_filter($correctness));
        $scorePercent = round(($correctAnswers / max(1, count($questions))) * 100, 2);

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => count($questions),
            'duration_minutes' => 45,
            'answered_count' => $answeredCount,
            'correct_answers' => $correctAnswers,
            'score_percent' => $scorePercent,
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

            $selectedLabel = !empty($correctness[$index]) ? 'A' : 'B';
            /** @var QuestionBankOption|null $selectedOption */
            $selectedOption = $question->options->firstWhere('option_label', $selectedLabel);

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

    private function makeAssessmentQuestion(
        QuestionBank $bank,
        string $subject,
        ?Topic $topic,
        int $itemNumber,
        string $questionText,
    ): QuestionBankQuestion {
        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => $subject,
            'topic_id' => $topic?->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_FACULTY,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => false,
            'source_question_id' => null,
            'item_number' => $itemNumber,
            'question_text' => $questionText,
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Correct answer',
        ]);

        $question->options()->createMany([
            [
                'sort_order' => 1,
                'option_label' => 'A',
                'option_text' => 'Correct answer',
                'is_correct' => true,
            ],
            [
                'sort_order' => 2,
                'option_label' => 'B',
                'option_text' => 'Incorrect answer',
                'is_correct' => false,
            ],
        ]);

        return $question->load('options');
    }

    private function makeRemedialQuestion(
        QuestionBank $bank,
        string $subject,
        ?Topic $topic,
        int $itemNumber,
        string $questionText,
    ): QuestionBankQuestion {
        return QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => $subject,
            'topic_id' => $topic?->id,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION,
            'source_question_id' => null,
            'item_number' => $itemNumber,
            'question_text' => $questionText,
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'Practice answer',
        ]);
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
