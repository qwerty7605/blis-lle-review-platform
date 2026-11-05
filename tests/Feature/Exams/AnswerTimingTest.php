<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * With results Visible, the teacher chooses when a student sees the correct
 * answers: right after each attempt, or once they cannot sit the exam again
 * (their last attempt used, or the exam closed). Review Bot practice from
 * the exam opens at the same moment (see ReviewBotEligibilityTest).
 */
class AnswerTimingTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
    }

    public function test_answers_wait_for_the_last_attempt_when_the_teacher_says_so(): void
    {
        $exam = $this->exam(Exam::ANSWERS_AFTER_LAST_ATTEMPT);
        $first = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, false, true], now()->subHour());

        Sanctum::actingAs($this->student);

        $this->getJson("/api/student/exam-attempts/{$first->id}")
            ->assertOk()
            ->assertJsonPath('attempt.answers_held_back', true)
            ->assertJsonPath('questions.0.correct_answer', null)
            ->assertJsonPath('questions.0.options.0.is_correct', null);

        // The retake used: nothing is left to protect.
        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, false, true]);

        $this->getJson("/api/student/exam-attempts/{$first->id}")
            ->assertOk()
            ->assertJsonPath('attempt.answers_held_back', false)
            ->assertJsonPath('questions.0.correct_answer.label', 'A')
            ->assertJsonPath('questions.0.options.0.is_correct', true);
    }

    public function test_answers_show_once_the_exam_closes(): void
    {
        $exam = $this->exam(Exam::ANSWERS_AFTER_LAST_ATTEMPT, ['schedule_end_at' => now()->addDay()]);
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, false, true]);

        Sanctum::actingAs($this->student);
        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertJsonPath('questions.0.correct_answer', null);

        $exam->forceFill(['schedule_end_at' => now()->subMinute()])->save();

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertJsonPath('questions.0.correct_answer.label', 'A');
    }

    public function test_answers_after_each_attempt_show_at_once(): void
    {
        $exam = $this->exam(Exam::ANSWERS_AFTER_EACH_ATTEMPT);
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, false, true]);

        Sanctum::actingAs($this->student);

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('attempt.answers_held_back', false)
            ->assertJsonPath('questions.0.correct_answer.label', 'A');
    }

    public function test_hidden_results_never_show_the_answers(): void
    {
        $exam = $this->exam(Exam::ANSWERS_AFTER_EACH_ATTEMPT, ['results_visibility_mode' => 'hidden', 'one_take_only' => true]);
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, false, true]);

        Sanctum::actingAs($this->student);

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('attempt.answers_held_back', false)
            ->assertJsonPath('questions.0.correct_answer', null);
    }

    public function test_the_emailed_report_follows_the_same_rule(): void
    {
        $exam = $this->exam(Exam::ANSWERS_AFTER_LAST_ATTEMPT);
        $attempt = $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, false, false, true]);
        $reports = app(\App\Services\ReportExportService::class);

        $this->assertFalse($reports->answersShownToStudent($attempt));

        $this->makeSubmittedAttempt($exam, $this->room, $this->student, [true, true, false, true]);

        $this->assertTrue($reports->answersShownToStudent($attempt->fresh()));
    }

    public function test_a_new_exam_waits_for_the_last_attempt_unless_told_otherwise(): void
    {
        $bank = $this->makeBank($this->teacher, 4);

        Sanctum::actingAs($this->teacher);

        $made = $this->postJson('/api/exams', [
            'title' => 'Indexing Quiz',
            'question_bank_ids' => [$bank->id],
            'total_items' => 4,
            'duration_minutes' => 30,
            'results_visibility_mode' => 'visible',
        ])->assertCreated()->json('exam.id');

        $this->assertSame(Exam::ANSWERS_AFTER_LAST_ATTEMPT, Exam::query()->find($made)->answers_shown_after);

        $this->patchJson("/api/exams/{$made}", ['answers_shown_after' => Exam::ANSWERS_AFTER_EACH_ATTEMPT])->assertOk();
        $this->assertSame(Exam::ANSWERS_AFTER_EACH_ATTEMPT, Exam::query()->find($made)->answers_shown_after);

        $this->patchJson("/api/exams/{$made}", ['answers_shown_after' => 'sometimes'])->assertStatus(422);
    }

    /** @param  array<string, mixed>  $attributes */
    private function exam(string $timing, array $attributes = []): Exam
    {
        return $this->makeExam($this->teacher, $this->makeBank($this->teacher, 4), [$this->room], [
            'one_take_only' => false,
            'results_visibility_mode' => 'visible',
            'answers_shown_after' => $timing,
            ...$attributes,
        ]);
    }
}
