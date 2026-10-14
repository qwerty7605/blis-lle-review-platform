<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamAttemptAnswer;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Exam routes (routes/api/exams.php) left out by the other exam tests: the
 * checks on creating and editing an exam, deleting someone else's, and the
 * live dashboard's per-student answers.
 */
class ExamRoutesGapsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        Sanctum::actingAs($this->teacher);
    }

    private function examBody(array $overrides = []): array
    {
        return [
            'title' => 'Reference Quiz',
            'question_bank_ids' => [$this->makeBank($this->teacher, 3)->id],
            'total_items' => 3,
            'duration_minutes' => 30,
            ...$overrides,
        ];
    }

    public function test_an_exam_cannot_end_before_it_starts(): void
    {
        $this->postJson('/api/exams', $this->examBody([
            'schedule_start_at' => now()->addDays(2)->toDateTimeString(),
            'schedule_end_at' => now()->addDay()->toDateTimeString(),
        ]))->assertStatus(422)->assertJsonPath('message', 'Schedule end must be after or equal to schedule start.');
    }

    public function test_an_exam_cannot_ask_for_more_items_than_its_sets_hold(): void
    {
        $this->postJson('/api/exams', $this->examBody(['total_items' => 10]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selected question sets do not have enough questions for the item count.');
    }

    public function test_an_exam_given_to_someone_else_s_room_is_not_kept(): void
    {
        $othersRoom = $this->makeRoom($this->makeTeacher());

        $this->postJson('/api/exams', $this->examBody(['room_ids' => [$othersRoom->id]]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'One or more selected rooms are not accessible.');

        $this->assertSame(0, Exam::count());
    }

    public function test_editing_an_exam_keeps_the_same_checks(): void
    {
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 3));
        $url = "/api/exams/{$exam->id}";

        $this->patchJson($url, [
            'schedule_start_at' => now()->addDays(2)->toDateTimeString(),
            'schedule_end_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonPath('message', 'Schedule end must be after or equal to schedule start.');

        $this->patchJson($url, ['total_items' => 50])
            ->assertStatus(422)->assertJsonPath('message', 'Selected question sets do not have enough questions for the item count.');

        $othersBank = $this->makeBank($this->makeTeacher(), 3);
        $this->patchJson($url, ['question_bank_ids' => [$othersBank->id]])
            ->assertStatus(422)->assertJsonPath('message', 'One or more selected question sets are not accessible.');

        $othersRoom = $this->makeRoom($this->makeTeacher());
        $this->patchJson($url, ['room_ids' => [$othersRoom->id]])
            ->assertStatus(422)->assertJsonPath('message', 'One or more selected rooms are not accessible.');

        $this->patchJson($url, ['total_items' => 2, 'schedule_start_at' => now()->addDay()->toDateTimeString()])->assertOk();
        $this->assertSame(2, $exam->fresh()->total_items);
    }

    public function test_only_the_owner_or_an_administrator_deletes_an_exam(): void
    {
        $exam = $this->makeExam($this->teacher);

        Sanctum::actingAs($this->makeTeacher());
        $this->deleteJson("/api/exams/{$exam->id}")->assertForbidden();
        Sanctum::actingAs($this->makeStudent());
        $this->deleteJson("/api/exams/{$exam->id}")->assertForbidden();
        $this->postJson('/api/exams', [])->assertForbidden();
        $this->patchJson("/api/exams/{$exam->id}", [])->assertForbidden();

        $this->assertNotNull($exam->fresh());
    }

    public function test_the_live_dashboard_shows_each_student_s_answers_by_item(): void
    {
        $ana = $this->makeStudent(['name' => 'Ana']);
        $room = $this->makeRoom($this->teacher, [$ana]);
        $bank = $this->makeBank($this->teacher, 3);
        $exam = $this->makeExam($this->teacher, $bank, [$room]);
        $attempt = $this->makeOpenAttempt($exam, $room, $ana, [true, false]);
        // The second choice has lost its letter; the third is a written answer.
        $attempt->answers()->where('is_correct', false)->first()->selectedOption->update(['option_label' => '']);
        ExamAttemptAnswer::create([
            'exam_attempt_id' => $attempt->id,
            'question_bank_question_id' => $bank->questions[2]->id,
            'answer_text' => 'my own words',
            'is_correct' => null,
            'answered_at' => now(),
        ]);

        $response = $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")->assertOk();

        $row = collect($response->json('rows'))->first();
        $responses = collect($row['items'] ?? [])->pluck('response')->all();
        $this->assertSame(['A. Correct answer 1', 'Wrong answer 2b', 'my own words'], $responses);
    }

    public function test_the_live_dashboard_of_an_exam_with_no_items_still_has_one_column(): void
    {
        $room = $this->makeRoom($this->teacher, [$this->makeStudent()]);
        $exam = $this->makeExam($this->teacher, null, [$room], ['total_items' => 0]);

        $response = $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$room->id}")->assertOk();

        $this->assertCount(1, $response->json('item_summary'));
    }

    public function test_a_teacher_cannot_watch_their_exam_in_another_teacher_s_room(): void
    {
        $othersRoom = $this->makeRoom($this->makeTeacher());
        $exam = $this->makeExam($this->teacher, null, [$othersRoom]);

        $this->getJson("/api/exams/{$exam->id}/live-dashboard?room_id={$othersRoom->id}")->assertForbidden();
    }

    public function test_a_disabled_teacher_is_signed_out_of_every_exam_route(): void
    {
        $exam = $this->makeExam($this->teacher);
        $this->teacher->forceFill(['is_active' => false])->save();

        foreach ([
            ['GET', '/api/exams'],
            ['POST', '/api/exams'],
            ['PATCH', "/api/exams/{$exam->id}"],
            ['DELETE', "/api/exams/{$exam->id}"],
            ['POST', "/api/exams/{$exam->id}/archive"],
            ['GET', "/api/exams/{$exam->id}/item-analytics"],
            ['GET', "/api/exams/{$exam->id}/live-dashboard"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
