<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Items per question set: every student gets the same mix (so many items
 * from each set), random within each set when the exam shuffles, and no
 * chosen set goes unused. Exams saved before it keep dealing from one pool.
 */
class ExamItemSplitTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->room = $this->makeRoom($this->teacher);

        Sanctum::actingAs($this->teacher);
    }

    public function test_a_new_exam_shares_its_items_out_by_the_sets_sizes(): void
    {
        $big = $this->makeBank($this->teacher, 10);
        $small = $this->makeBank($this->teacher, 2);

        $this->createExam([$big, $small], 6)->assertCreated()->assertJsonPath('exam.question_bank_items', [5, 1]);
    }

    public function test_every_student_gets_the_same_mix(): void
    {
        $first = $this->makeBank($this->teacher, 6);
        $second = $this->makeBank($this->teacher, 6);
        $examId = $this->createExam([$first, $second], 6, ['question_bank_items' => [4, 2], 'shuffle_questions' => true])
            ->assertCreated()
            ->json('exam.id');

        foreach (range(1, 3) as $ignored) {
            $dealt = $this->dealtBySet($examId, $this->student());

            $this->assertSame(4, $dealt[$first->id] ?? 0);
            $this->assertSame(2, $dealt[$second->id] ?? 0);
        }
    }

    public function test_without_shuffle_each_set_gives_its_first_items_in_order(): void
    {
        $first = $this->makeBank($this->teacher, 5);
        $second = $this->makeBank($this->teacher, 5);
        $examId = $this->createExam([$first, $second], 4, ['question_bank_items' => [2, 2], 'shuffle_questions' => false])->json('exam.id');

        $attemptId = $this->start($examId, $this->student());

        $dealt = ExamAttemptQuestion::query()
            ->where('exam_attempt_id', $attemptId)
            ->orderBy('item_number')
            ->with('question:id,question_bank_id,item_number')
            ->get()
            ->map(fn (ExamAttemptQuestion $item): string => $item->question->question_bank_id . ':' . $item->question->item_number)
            ->all();

        $this->assertSame(["{$first->id}:1", "{$first->id}:2", "{$second->id}:1", "{$second->id}:2"], $dealt);
    }

    public function test_counts_that_do_not_fit_are_refused(): void
    {
        $first = $this->makeBank($this->teacher, 5);
        $second = $this->makeBank($this->teacher, 3);

        $this->createExam([$first, $second], 6, ['question_bank_items' => [3, 2]])
            ->assertStatus(422)->assertJsonValidationErrors(['question_bank_items']);
        $this->createExam([$first, $second], 6, ['question_bank_items' => [2, 4]])
            ->assertStatus(422)->assertJsonValidationErrors(['question_bank_items']);
        $this->createExam([$first, $second], 6, ['question_bank_items' => [6]])
            ->assertStatus(422)->assertJsonValidationErrors(['question_bank_items']);

        $this->assertSame(0, Exam::query()->count());
    }

    public function test_once_students_have_started_the_split_is_fixed(): void
    {
        $first = $this->makeBank($this->teacher, 6);
        $second = $this->makeBank($this->teacher, 6);
        $examId = $this->createExam([$first, $second], 6, ['question_bank_items' => [3, 3]])->json('exam.id');
        $this->start($examId, $this->student());

        Sanctum::actingAs($this->teacher);

        $this->patchJson("/api/exams/{$examId}", ['question_bank_items' => [4, 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question_bank_items']);

        // A change elsewhere, with the split as it is or left out, still saves.
        $this->patchJson("/api/exams/{$examId}", ['title' => 'Renamed', 'question_bank_items' => [3, 3]])->assertOk();
        $this->patchJson("/api/exams/{$examId}", ['description' => 'Read every choice.'])
            ->assertOk()
            ->assertJsonPath('exam.question_bank_items', [3, 3]);
    }

    public function test_an_exam_saved_before_per_set_counts_keeps_its_one_pool_once_started(): void
    {
        $first = $this->makeBank($this->teacher, 5);
        $second = $this->makeBank($this->teacher, 5);
        $exam = $this->makeExam($this->teacher, $first, [$this->room], ['total_items' => 4, 'shuffle_questions' => false]);
        $exam->syncQuestionBanks([$first->id, $second->id]);
        $this->assertNull($exam->fresh()->itemSplit());

        // One pool, in order: the first set's first four.
        $dealt = $this->dealtBySet($exam->id, $this->student());
        $this->assertSame([$first->id => 4], $dealt);

        Sanctum::actingAs($this->teacher);

        // The form sends a split; started, the exam keeps the pool it had.
        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'Renamed', 'question_bank_items' => [2, 2]])->assertOk();
        $this->assertNull($exam->fresh()->itemSplit());
    }

    /** @param  list<QuestionBank>  $banks */
    private function createExam(array $banks, int $items, array $extra = [])
    {
        return $this->postJson('/api/exams', [
            'title' => 'Mixed sets',
            'question_bank_ids' => array_map(fn (QuestionBank $bank): int => $bank->id, $banks),
            'total_items' => $items,
            'duration_minutes' => 30,
            'results_visibility_mode' => 'visible',
            'room_ids' => [$this->room->id],
            ...$extra,
        ]);
    }

    private function student(): User
    {
        $student = $this->makeStudent();
        $this->room->members()->attach($student->id);

        return $student;
    }

    private function start(int $examId, User $student): int
    {
        Sanctum::actingAs($student);

        return (int) $this->postJson("/api/student/exams/{$examId}/start", ['room_id' => $this->room->id])
            ->assertSuccessful()
            ->json('attempt.id');
    }

    /** @return array<int, int> set id => items dealt from it */
    private function dealtBySet(int $examId, User $student): array
    {
        $attemptId = $this->start($examId, $student);

        return ExamAttemptQuestion::query()
            ->where('exam_attempt_id', $attemptId)
            ->with('question:id,question_bank_id')
            ->get()
            ->countBy(fn (ExamAttemptQuestion $item): int => (int) $item->question->question_bank_id)
            ->all();
    }
}
