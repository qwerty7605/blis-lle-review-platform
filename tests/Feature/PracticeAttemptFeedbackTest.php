<?php

namespace Tests\Feature;

use App\Models\PracticeAttempt;
use App\Models\PracticeAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PracticeAttemptFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_start_practice_from_approved_ai_pool_when_no_exam_weakness_data_exists(): void
    {
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
        ]);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'student_id' => '2302999',
            'is_active' => true,
        ]);
        $bank = QuestionBank::create([
            'title' => 'Practice Fallback Bank',
            'subject' => 'Indexing',
            'source_filename' => 'practice-fallback.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'How PRECIS differs from unrelated tools',
            'difference_from_source' => 'Changes recall into a comparison task.',
            'explanation' => null,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'It is an indexing system formerly used by the British National Bibliography.',
        ]);

        $question->options()->createMany([
            ['sort_order' => 1, 'option_label' => 'A', 'option_text' => 'It is an indexing system formerly used by the British National Bibliography.', 'is_correct' => true],
            ['sort_order' => 2, 'option_label' => 'B', 'option_text' => 'It is a barcode format.', 'is_correct' => false],
            ['sort_order' => 3, 'option_label' => 'C', 'option_text' => 'It is a shelving repair tool.', 'is_correct' => false],
            ['sort_order' => 4, 'option_label' => 'D', 'option_text' => 'It is a circulation fine policy.', 'is_correct' => false],
        ]);

        Sanctum::actingAs($student);

        $this->getJson('/api/student/review-bot/overview')
            ->assertOk()
            ->assertJsonPath('weak_area.basis', 'subject')
            ->assertJsonPath('weak_area.subject', 'Indexing')
            ->assertJsonPath('available_questions', 1);

        $this->postJson('/api/student/review-bot/start', [
            'question_count' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('attempt.status', PracticeAttempt::STATUS_IN_PROGRESS)
            ->assertJsonPath('attempt.total_items', 1)
            ->assertJsonPath('questions.0.question_id', $question->id);
    }

    public function test_remedial_practice_reveals_correct_answer_after_answering(): void
    {
        $staff = User::factory()->create([
            'role' => User::ROLE_STAFF_MASTER_EXAMINER,
            'is_active' => true,
        ]);
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'student_id' => '2302888',
            'is_active' => true,
        ]);
        $bank = QuestionBank::create([
            'title' => 'Practice Feedback Bank',
            'subject' => 'Indexing',
            'source_filename' => 'practice-feedback.docx',
            'total_items' => 1,
            'created_by' => $staff->id,
        ]);

        $question = QuestionBankQuestion::create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
            'subject' => 'Indexing',
            'topic_id' => null,
            'difficulty' => QuestionBankQuestion::DIFFICULTY_MEDIUM,
            'source' => QuestionBankQuestion::SOURCE_AI,
            'status' => QuestionBankQuestion::STATUS_APPROVED,
            'ai_generated' => true,
            'item_style' => QuestionBankQuestion::ITEM_STYLE_COMPARISON,
            'cognitive_level' => 'analysis',
            'tested_concept' => 'How PRECIS differs from unrelated tools',
            'difference_from_source' => 'Changes recall into a comparison task.',
            'explanation' => null,
            'source_question_id' => null,
            'item_number' => 1,
            'question_text' => 'Which statement best distinguishes PRECIS from unrelated library tools?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => 'It is an indexing system formerly used by the British National Bibliography.',
        ]);

        $question->options()->createMany([
            [
                'sort_order' => 1,
                'option_label' => 'A',
                'option_text' => 'It is an indexing system formerly used by the British National Bibliography.',
                'is_correct' => true,
            ],
            [
                'sort_order' => 2,
                'option_label' => 'B',
                'option_text' => 'It is a barcode format.',
                'is_correct' => false,
            ],
            [
                'sort_order' => 3,
                'option_label' => 'C',
                'option_text' => 'It is a shelving repair tool.',
                'is_correct' => false,
            ],
            [
                'sort_order' => 4,
                'option_label' => 'D',
                'option_text' => 'It is a circulation fine policy.',
                'is_correct' => false,
            ],
        ]);

        $attempt = PracticeAttempt::create([
            'user_id' => $student->id,
            'status' => PracticeAttempt::STATUS_IN_PROGRESS,
            'target_type' => PracticeAttempt::TARGET_SUBJECT,
            'subject' => 'Indexing',
            'topic_id' => null,
            'total_items' => 1,
            'answered_count' => 0,
            'correct_answers' => 0,
            'started_at' => now(),
        ]);

        PracticeAttemptQuestion::create([
            'practice_attempt_id' => $attempt->id,
            'question_bank_question_id' => $question->id,
            'item_number' => 1,
        ]);

        Sanctum::actingAs($student);

        $this->getJson("/api/student/review-bot/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('questions.0.correct_answer', null)
            ->assertJsonPath('questions.0.explanation', null)
            ->assertJsonPath('questions.0.options.0.is_correct', null);

        $selectedOptionId = (int) $question->options()->where('option_label', 'B')->value('id');

        $this->patchJson("/api/student/review-bot/attempts/{$attempt->id}/answers", [
            'question_id' => $question->id,
            'selected_option_id' => $selectedOptionId,
        ])
            ->assertOk()
            ->assertJsonPath('questions.0.answer.is_correct', false)
            ->assertJsonPath('questions.0.correct_answer.label', 'A')
            ->assertJsonPath('questions.0.explanation', null)
            ->assertJsonPath('questions.0.options.0.is_correct', true);
    }
}
