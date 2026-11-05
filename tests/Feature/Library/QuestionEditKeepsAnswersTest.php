<?php

namespace Tests\Feature\Library;

use App\Models\AuditLog;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Editing a question in the Library leaves the answers students gave to it
 * in exams pointing at the choices they picked, and a changed correct answer
 * marks those answers, and each attempt's score, again.
 */
class QuestionEditKeepsAnswersTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private QuestionBankQuestion $question;

    private ExamAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($this->teacher, [$student]);
        $bank = $this->makeBank($this->teacher, 2);
        $exam = $this->makeExam($this->teacher, $bank, [$room]);

        // Question 1 answered wrong (choice B), question 2 right (A): 50%.
        $this->attempt = $this->makeSubmittedAttempt($exam, $room, $student, [false, true]);
        $this->question = $bank->questions->sortBy('item_number')->first();

        Sanctum::actingAs($this->teacher);
    }

    public function test_editing_the_wording_keeps_the_choice_each_student_picked(): void
    {
        $pickedB = $this->option('B');

        $this->update('Which statement about item 1 is correct? (Reworded)', $this->choices(fn (array $option) => [
            ...$option,
            'text' => $option['label'] === 'B' ? 'Wrong answer 1b, reworded' : $option['text'],
        ]))->assertOk();

        $answer = $this->answer();
        $this->assertSame($pickedB->id, (int) $answer->question_bank_option_id);
        $this->assertSame('Wrong answer 1b, reworded', $pickedB->fresh()->option_text);
        $this->assertFalse((bool) $answer->is_correct);
        $this->assertSame('50.00', (string) $this->attempt->fresh()->score_percent);
    }

    public function test_a_choice_a_student_picked_cannot_be_removed(): void
    {
        $withoutB = collect($this->choices())->reject(fn (array $option) => $option['label'] === 'B')->values()->all();

        $this->update($this->question->question_text, $withoutB)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options']);

        $this->assertNotNull($this->option('B'));
        $this->assertSame($this->option('B')->id, (int) $this->answer()->question_bank_option_id);
    }

    public function test_a_choice_nobody_picked_can_be_removed_and_a_new_one_added(): void
    {
        // D goes; nobody picked it.
        $options = collect($this->choices())->reject(fn (array $option) => $option['label'] === 'D')->values()->all();
        $options[] = ['id' => null, 'label' => 'D', 'text' => 'A brand new choice', 'is_correct' => false];

        $this->update($this->question->question_text, $options)->assertOk();

        $labels = $this->question->options()->orderBy('sort_order')->pluck('option_label')->all();
        $this->assertSame(['A', 'B', 'C', 'D'], $labels);
        $this->assertSame('A brand new choice', $this->option('D')->option_text);
        $this->assertSame($this->option('B')->id, (int) $this->answer()->question_bank_option_id);
    }

    public function test_changing_the_correct_answer_marks_the_answers_and_scores_again(): void
    {
        $response = $this->update($this->question->question_text, $this->choices(fn (array $option) => [
            ...$option,
            'is_correct' => $option['label'] === 'B',
        ]))->assertOk()->assertJsonPath('regraded', 1);

        $this->assertStringContainsString('re-graded', $response->json('message'));

        $this->assertTrue((bool) $this->answer()->is_correct);
        $attempt = $this->attempt->fresh();
        $this->assertSame(2, (int) $attempt->correct_answers);
        $this->assertSame('100.00', (string) $attempt->score_percent);

        $audit = AuditLog::query()->where('action', 'library.question.regrade')->firstOrFail();
        $this->assertSame(1, $audit->metadata['answers']);
        $this->assertSame([$this->attempt->id], $audit->metadata['attempt_ids']);
    }

    /** @return array<int, array{id: int|null, label: string, text: string, is_correct: bool}> */
    private function choices(?callable $change = null): array
    {
        return $this->question->options()->orderBy('sort_order')->get()
            ->map(fn ($option): array => [
                'id' => $option->id,
                'label' => $option->option_label,
                'text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
            ])
            ->map(fn (array $option): array => $change ? $change($option) : $option)
            ->values()
            ->all();
    }

    private function update(string $text, array $options)
    {
        return $this->patchJson("/api/library/questions/{$this->question->id}", [
            'question_text' => $text,
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $options,
        ]);
    }

    private function option(string $label)
    {
        return $this->question->options()->where('option_label', $label)->first();
    }

    private function answer(): ExamAttemptAnswer
    {
        return ExamAttemptAnswer::query()
            ->where('exam_attempt_id', $this->attempt->id)
            ->where('question_bank_question_id', $this->question->id)
            ->firstOrFail();
    }
}
