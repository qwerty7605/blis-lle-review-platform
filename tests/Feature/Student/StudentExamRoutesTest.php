<?php

namespace Tests\Feature\Student;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * A student taking an exam (routes/api/student.php): starting it, saving and
 * clearing answers, bookmarking, and handing it in, with every refusal those
 * routes can give.
 */
class StudentExamRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    private QuestionBank $bank;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
        $this->bank = $this->makeBank($this->teacher, 3);
        $this->exam = $this->makeExam($this->teacher, $this->bank, [$this->room]);

        Sanctum::actingAs($this->student);
    }

    private function start(?Exam $exam = null, ?Room $room = null)
    {
        $exam ??= $this->exam;
        $room ??= $this->room;

        return $this->postJson("/api/student/exams/{$exam->id}/start", ['room_id' => $room->id]);
    }

    private function startedAttempt(): ExamAttempt
    {
        return ExamAttempt::findOrFail($this->start()->assertCreated()->json('attempt.id'));
    }

    private function question(int $index = 0): QuestionBankQuestion
    {
        return $this->bank->questions[$index];
    }

    private function save(ExamAttempt $attempt, array $body)
    {
        return $this->patchJson("/api/student/exam-attempts/{$attempt->id}/answers", $body);
    }

    // --- Starting ----------------------------------------------------------

    public function test_staff_cannot_start_an_exam_attempt(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->start()->assertForbidden()->assertJsonPath('message', 'Only student accounts can start exam attempts.');
    }

    public function test_a_student_outside_the_room_cannot_start_its_exam(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->start()->assertForbidden()->assertJsonPath('message', 'You are not a member of the selected room.');
    }

    public function test_an_exam_not_given_to_the_room_cannot_be_started_there(): void
    {
        $other = $this->makeExam($this->teacher, $this->bank);

        $this->start($other)->assertStatus(422)->assertJsonPath('message', 'This exam is not assigned to the selected room.');
    }

    public function test_an_exam_cannot_be_started_before_it_opens(): void
    {
        $this->exam->update(['schedule_start_at' => now()->addDay()]);

        $this->start()
            ->assertStatus(422)
            ->assertJsonPath('message', 'This exam is not yet available based on schedule.');
    }

    public function test_an_exam_whose_set_has_no_questions_cannot_be_started(): void
    {
        $emptyBank = QuestionBank::create(['title' => 'Empty', 'subject' => 'Cataloging', 'total_items' => 0, 'created_by' => $this->teacher->id]);
        $exam = $this->makeExam($this->teacher, $emptyBank, [$this->room], ['total_items' => 5]);

        $this->start($exam)->assertStatus(422)->assertJsonPath('message', 'No questions are available for this exam.');
    }

    public function test_a_shuffled_exam_deals_every_question_once(): void
    {
        $this->exam->update(['shuffle_questions' => true]);

        $attempt = $this->startedAttempt();

        $this->assertEqualsCanonicalizing(
            $this->bank->questions->pluck('id')->all(),
            $attempt->attemptQuestions()->pluck('question_bank_question_id')->all(),
        );
    }

    // --- Reading an attempt ------------------------------------------------

    public function test_an_attempt_can_be_read_only_by_the_student_who_made_it(): void
    {
        $attempt = $this->startedAttempt();

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertOk()->assertJsonPath('attempt.id', $attempt->id);

        Sanctum::actingAs($this->teacher);
        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertForbidden();

        Sanctum::actingAs($this->makeStudent());
        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertForbidden();
    }

    // --- Saving answers ----------------------------------------------------

    public function test_choosing_an_answer_saves_it_and_marks_it(): void
    {
        $attempt = $this->startedAttempt();
        $question = $this->question();
        $wrong = $question->options->firstWhere('option_label', 'B');

        $this->save($attempt, ['question_id' => $question->id, 'selected_option_id' => $wrong->id])
            ->assertOk()
            ->assertJsonPath('message', 'Answer saved.');

        $answer = ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->firstOrFail();
        $this->assertFalse($answer->is_correct);
        $this->assertSame($wrong->option_text, $answer->answer_text);
    }

    public function test_clearing_a_choice_removes_the_answer(): void
    {
        $attempt = $this->startedAttempt();
        $question = $this->question();
        $this->save($attempt, ['question_id' => $question->id, 'selected_option_id' => $question->options->first()->id])->assertOk();

        $this->save($attempt, ['question_id' => $question->id, 'selected_option_id' => null])->assertOk();

        $this->assertSame(0, ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->count());
    }

    public function test_a_choice_from_another_question_is_refused(): void
    {
        $attempt = $this->startedAttempt();

        $this->save($attempt, [
            'question_id' => $this->question(0)->id,
            'selected_option_id' => $this->question(1)->options->first()->id,
        ])->assertStatus(422)->assertJsonPath('message', 'Selected option is not valid for this question.');
    }

    public function test_a_question_that_is_not_in_the_attempt_is_refused(): void
    {
        $attempt = $this->startedAttempt();
        $outsider = $this->makeBank($this->teacher, 1)->questions->first();

        $this->save($attempt, ['question_id' => $outsider->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected question is not part of this attempt.');
    }

    public function test_written_answers_are_marked_by_the_expected_text_or_the_answer_letter(): void
    {
        $bank = $this->makeBank($this->teacher, 3, ['subject' => 'Cataloging'], [
            'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
            'answer_label' => 'MARC',
            'answer_text' => 'Machine Readable   Cataloging',
        ]);
        $exam = $this->makeExam($this->teacher, $bank, [$this->room]);
        $attempt = ExamAttempt::findOrFail($this->start($exam)->assertCreated()->json('attempt.id'));
        [$first, $second, $third] = $bank->questions;

        // Spacing and capitals do not matter; the label counts too.
        $this->save($attempt, ['question_id' => $first->id, 'answer_text' => ' machine readable cataloging '])->assertOk();
        $this->save($attempt, ['question_id' => $second->id, 'answer_text' => 'marc'])->assertOk();
        $this->save($attempt, ['question_id' => $third->id, 'answer_text' => 'Dublin Core'])->assertOk();

        $marks = ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->pluck('is_correct', 'question_bank_question_id');
        $this->assertTrue((bool) $marks[$first->id]);
        $this->assertTrue((bool) $marks[$second->id]);
        $this->assertFalse((bool) $marks[$third->id]);

        // Emptying the box takes the answer away.
        $this->save($attempt, ['question_id' => $third->id, 'answer_text' => '   '])->assertOk();
        $this->assertFalse(ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->where('question_bank_question_id', $third->id)->exists());
    }

    public function test_a_written_answer_to_a_question_with_no_expected_answer_is_wrong(): void
    {
        $bank = $this->makeBank($this->teacher, 1, [], [
            'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
            'answer_label' => null,
            'answer_text' => null,
        ]);
        $exam = $this->makeExam($this->teacher, $bank, [$this->room]);
        $attempt = ExamAttempt::findOrFail($this->start($exam)->assertCreated()->json('attempt.id'));

        $this->save($attempt, ['question_id' => $bank->questions->first()->id, 'answer_text' => 'anything'])->assertOk();

        $this->assertFalse((bool) ExamAttemptAnswer::where('exam_attempt_id', $attempt->id)->value('is_correct'));
    }

    public function test_answers_cannot_be_saved_to_a_handed_in_attempt_or_by_someone_else(): void
    {
        $attempt = $this->startedAttempt();
        $body = ['question_id' => $this->question()->id, 'selected_option_id' => $this->question()->options->first()->id];

        Sanctum::actingAs($this->teacher);
        $this->save($attempt, $body)->assertForbidden();
        Sanctum::actingAs($this->makeStudent());
        $this->save($attempt, $body)->assertForbidden();

        Sanctum::actingAs($this->student);
        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")->assertOk();
        $this->save($attempt, $body)->assertStatus(422)->assertJsonPath('message', 'This attempt is already submitted.');
    }

    // --- Bookmarks ---------------------------------------------------------

    public function test_a_question_can_be_bookmarked_and_unbookmarked(): void
    {
        $attempt = $this->startedAttempt();
        $url = "/api/student/exam-attempts/{$attempt->id}/questions/{$this->question()->id}/bookmark";

        $this->patchJson($url, ['is_bookmarked' => true])->assertOk()->assertJsonPath('message', 'Question bookmarked.');
        $this->assertTrue((bool) $attempt->attemptQuestions()->where('question_bank_question_id', $this->question()->id)->value('is_bookmarked'));

        $this->patchJson($url, ['is_bookmarked' => false])->assertOk()->assertJsonPath('message', 'Bookmark removed.');
    }

    public function test_bookmarking_is_refused_where_it_does_not_apply(): void
    {
        $attempt = $this->startedAttempt();
        $outsider = $this->makeBank($this->teacher, 1)->questions->first();

        $this->patchJson("/api/student/exam-attempts/{$attempt->id}/questions/{$outsider->id}/bookmark", ['is_bookmarked' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected question is not part of this attempt.');

        $url = "/api/student/exam-attempts/{$attempt->id}/questions/{$this->question()->id}/bookmark";
        Sanctum::actingAs($this->teacher);
        $this->patchJson($url, ['is_bookmarked' => true])->assertForbidden();
        Sanctum::actingAs($this->makeStudent());
        $this->patchJson($url, ['is_bookmarked' => true])->assertForbidden();

        Sanctum::actingAs($this->student);
        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")->assertOk();
        $this->patchJson($url, ['is_bookmarked' => true])->assertStatus(422)->assertJsonPath('message', 'This attempt is already submitted.');
    }

    // --- Handing in --------------------------------------------------------

    public function test_handing_in_scores_the_attempt_and_a_second_hand_in_changes_nothing(): void
    {
        $attempt = $this->startedAttempt();
        $this->save($attempt, ['question_id' => $this->question()->id, 'selected_option_id' => $this->question()->options->first()->id]);

        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")
            ->assertOk()
            ->assertJsonPath('message', 'Attempt submitted successfully.');
        $this->assertSame(ExamAttempt::STATUS_SUBMITTED, $attempt->fresh()->status);
        $this->assertSame(1, $attempt->fresh()->correct_answers);

        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")
            ->assertOk()
            ->assertJsonPath('message', 'Attempt already submitted.');
    }

    public function test_only_the_student_who_made_the_attempt_can_hand_it_in(): void
    {
        $attempt = $this->startedAttempt();

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")->assertForbidden();
        Sanctum::actingAs($this->makeStudent());
        $this->postJson("/api/student/exam-attempts/{$attempt->id}/submit")->assertForbidden();
    }

    // --- Student-only pages ------------------------------------------------

    public function test_staff_are_kept_out_of_the_student_s_own_pages(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson('/api/student/dashboard')->assertForbidden()->assertJsonPath('message', 'Only student accounts can access this dashboard.');
        $this->getJson('/api/student/analytics')->assertForbidden()->assertJsonPath('message', 'Only student accounts can access analytics.');
    }

    public function test_the_dashboard_shows_an_exam_under_way_as_in_progress(): void
    {
        $attempt = $this->startedAttempt();

        $exams = collect($this->getJson('/api/student/dashboard')->assertOk()->json('rooms'))
            ->flatMap(fn (array $room) => $room['assigned_exams']);
        $row = $exams->firstWhere('id', $this->exam->id);

        $this->assertSame('in_progress', $row['student_attempt_state']);
        $this->assertSame($attempt->id, $row['student_attempt_id']);
    }

    public function test_a_disabled_student_is_signed_out_of_every_exam_route(): void
    {
        $attempt = $this->startedAttempt();
        $this->student->forceFill(['is_active' => false])->save();

        $requests = [
            ['GET', '/api/student/dashboard'],
            ['GET', '/api/student/analytics/overview'],
            ['GET', '/api/student/analytics'],
            ['POST', "/api/student/exams/{$this->exam->id}/start"],
            ['GET', "/api/student/exam-attempts/{$attempt->id}"],
            ['PATCH', "/api/student/exam-attempts/{$attempt->id}/answers"],
            ['PATCH', "/api/student/exam-attempts/{$attempt->id}/questions/1/bookmark"],
            ['POST', "/api/student/exam-attempts/{$attempt->id}/submit"],
            ['GET', '/api/students/directory'],
            ['GET', "/api/students/{$this->student->id}/analytics"],
        ];

        foreach ($requests as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
