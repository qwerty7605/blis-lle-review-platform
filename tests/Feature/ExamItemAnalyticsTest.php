<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/exams/{exam}/item-analytics feeds the Item-Level Analytics list on
 * the teacher Dashboard. The page went on calling it after the route was lost
 * in a refactor, so choosing an exam there only ever answered 404.
 */
class ExamItemAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_are_ranked_by_how_often_the_class_missed_them(): void
    {
        [$staff, $room, $exam, $questions] = $this->scenario();

        $this->makeAttempt($exam, $room, $this->makeStudent(), $questions, ['correct', 'wrong', 'blank']);
        $this->makeAttempt($exam, $room, $this->makeStudent(), $questions, ['correct', 'wrong', 'correct']);

        Sanctum::actingAs($staff);

        $items = $this->getJson("/api/exams/{$exam->id}/item-analytics")
            ->assertOk()
            ->json('item_analytics');

        $this->assertSame(
            [$questions[1]->id, $questions[2]->id, $questions[0]->id],
            array_column($items, 'question_id'),
            'most-missed first',
        );

        $this->assertSame('Question 2', $items[0]['question_text']);
        $this->assertSame(2, $items[0]['total_attempts']);
        $this->assertEquals(100, $items[0]['failure_rate_percent']);

        // Left blank by one student: served twice, right once. A blank is a
        // miss here exactly as it is in that student's score.
        $this->assertSame(2, $items[1]['total_attempts']);
        $this->assertSame(1, $items[1]['correct_answers']);
        $this->assertEquals(50, $items[1]['failure_rate_percent']);

        $this->assertEquals(0, $items[2]['failure_rate_percent']);
        $this->assertEquals(100, $items[2]['success_rate_percent']);
    }

    public function test_it_counts_the_same_attempts_as_the_rest_of_the_dashboard(): void
    {
        [$staff, $room, $exam, $questions] = $this->scenario();

        // A retake replaces the attempt before it rather than adding to it.
        $retaker = $this->makeStudent();
        $this->makeAttempt($exam, $room, $retaker, $questions, ['wrong', 'wrong', 'wrong']);
        $this->makeAttempt($exam, $room, $retaker, $questions, ['correct', 'correct', 'correct']);

        // Still sitting the exam: nothing to grade yet.
        $this->makeAttempt($exam, $room, $this->makeStudent(), $questions, ['wrong', 'blank', 'blank'], false);

        // Archived students are out of every class-wide figure on the page.
        $graduate = $this->makeStudent(['archived_at' => now()]);
        $this->makeAttempt($exam, $room, $graduate, $questions, ['wrong', 'wrong', 'wrong']);

        Sanctum::actingAs($staff);

        $items = $this->getJson("/api/exams/{$exam->id}/item-analytics")
            ->assertOk()
            ->json('item_analytics');

        $this->assertCount(3, $items);

        foreach ($items as $item) {
            $this->assertSame(1, $item['total_attempts']);
            $this->assertEquals(0, $item['failure_rate_percent']);
        }
    }

    public function test_an_exam_nobody_has_submitted_has_nothing_to_rank(): void
    {
        [$staff, , $exam] = $this->scenario();

        Sanctum::actingAs($staff);

        $this->getJson("/api/exams/{$exam->id}/item-analytics")
            ->assertOk()
            ->assertExactJson(['item_analytics' => []]);
    }

    public function test_only_the_teacher_who_owns_the_exam_can_read_it(): void
    {
        [, , $exam] = $this->scenario();

        $outsiders = [
            User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]),
            User::factory()->create(['role' => User::ROLE_ADMIN]),
            $this->makeStudent(),
        ];

        foreach ($outsiders as $outsider) {
            Sanctum::actingAs($outsider);

            $this->getJson("/api/exams/{$exam->id}/item-analytics")->assertForbidden();
        }
    }

    /** @return array{0: User, 1: Room, 2: Exam, 3: list<QuestionBankQuestion>} */
    private function scenario(): array
    {
        $staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $room = Room::create(['name' => 'BLIS 4A', 'code' => 'ITEM4A', 'created_by' => $staff->id]);

        $bank = QuestionBank::create([
            'title' => 'Cataloging Set',
            'subject' => 'Cataloging and Classification',
            'source_filename' => 'cataloging.docx',
            'total_items' => 3,
            'created_by' => $staff->id,
        ]);

        $questions = [];

        foreach ([1, 2, 3] as $itemNumber) {
            $question = QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
                'subject' => 'Cataloging and Classification',
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

            $questions[] = $question->load('options');
        }

        $exam = Exam::create([
            'title' => 'Cataloging Quiz',
            'subject' => 'Cataloging and Classification',
            'question_bank_id' => $bank->id,
            'total_items' => 3,
            'duration_minutes' => 45,
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'one_take_only' => false,
            'shuffle_questions' => false,
            'created_by' => $staff->id,
        ]);

        $exam->rooms()->attach($room->id, [
            'assigned_by' => $staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$staff, $room, $exam, $questions];
    }

    private function makeStudent(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => User::ROLE_STUDENT], $attributes));
    }

    /**
     * @param  list<QuestionBankQuestion>  $questions
     * @param  list<string>  $outcomes  'correct', 'wrong' or 'blank', one per question
     */
    private function makeAttempt(
        Exam $exam,
        Room $room,
        User $student,
        array $questions,
        array $outcomes,
        bool $submitted = true,
    ): ExamAttempt {
        $correct = count(array_keys($outcomes, 'correct', true));

        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => $submitted ? ExamAttempt::STATUS_SUBMITTED : ExamAttempt::STATUS_IN_PROGRESS,
            'total_items' => count($questions),
            'duration_minutes' => 45,
            'answered_count' => count($outcomes) - count(array_keys($outcomes, 'blank', true)),
            'correct_answers' => $correct,
            'score_percent' => $submitted ? round(($correct / count($questions)) * 100, 2) : null,
            'started_at' => now()->subHour(),
            'submitted_at' => $submitted ? now() : null,
        ]);

        foreach ($questions as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'item_number' => $index + 1,
                'is_bookmarked' => false,
            ]);

            if ($outcomes[$index] === 'blank') {
                continue;
            }

            $option = $question->options->firstWhere('is_correct', $outcomes[$index] === 'correct');

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_bank_question_id' => $question->id,
                'question_bank_option_id' => $option->id,
                'answer_text' => $option->option_text,
                'is_correct' => $outcomes[$index] === 'correct',
                'answered_at' => now(),
            ]);
        }

        return $attempt;
    }
}
