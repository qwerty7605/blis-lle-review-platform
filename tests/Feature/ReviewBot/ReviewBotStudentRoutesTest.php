<?php

namespace Tests\Feature\ReviewBot;

use App\Models\AuditLog;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The student's Review Bot routes (routes/api/review-bot.php): the subject
 * catalog, starting a practice session by subject or from the weak area,
 * handing it in, and reading it back.
 */
class ReviewBotStudentRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Cataloging and Classification';

    private User $teacher;

    private User $student;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->topic = $this->makeTopic(self::SUBJECT, 'Authority Control', $this->teacher);
        $this->publishReviewQuestions(5);

        Sanctum::actingAs($this->student);
    }

    private function publishReviewQuestions(int $count): void
    {
        $bank = QuestionBank::create([
            'title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . self::SUBJECT,
            'subject' => self::SUBJECT,
            'total_items' => 0,
            'created_by' => $this->teacher->id,
        ]);

        for ($number = 1; $number <= $count; $number++) {
            $this->makeQuestion($bank, $number, [
                'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'subject' => self::SUBJECT,
                'topic_id' => $this->topic->id,
                'difficulty' => 'medium',
            ]);
        }
    }

    private function beginSession(array $body = [])
    {
        return $this->postJson('/api/student/review-bot/sessions', [
            'subject' => self::SUBJECT,
            'question_count' => 3,
            ...$body,
        ]);
    }

    public function test_the_catalog_lists_the_subjects_and_has_no_weak_area_without_exam_history(): void
    {
        $response = $this->getJson('/api/student/review-bot/catalog')->assertOk();

        $entry = collect($response->json('subjects'))->firstWhere('subject', self::SUBJECT);
        $this->assertSame(5, $entry['question_count']);
        $this->assertNull($response->json('weak_area'));
    }

    public function test_a_session_by_subject_starts_hands_in_and_reads_back(): void
    {
        $start = $this->beginSession()->assertCreated();

        $attemptId = $start->json('attempt.id');
        $this->assertCount(3, $start->json('questions'));
        $this->assertTrue(AuditLog::where('action', 'review-bot.session.start')->exists());

        $answers = collect($start->json('questions'))->map(fn (array $question) => [
            'question_id' => $question['question_id'],
            'option_id' => QuestionBankQuestion::find($question['question_id'])->options()->where('is_correct', true)->value('id'),
        ])->all();

        $result = $this->postJson("/api/student/review-bot/sessions/{$attemptId}/submit", ['answers' => $answers])->assertOk();
        $this->assertEquals(100, $result->json('score_percent'));
        $this->assertTrue(AuditLog::where('action', 'review-bot.session.submit')->exists());

        $this->getJson("/api/student/review-bot/sessions/{$attemptId}")
            ->assertOk()
            ->assertJsonPath('attempt.id', $attemptId);
    }

    public function test_a_session_cannot_be_handed_in_twice(): void
    {
        $start = $this->beginSession()->assertCreated();
        $attemptId = $start->json('attempt.id');
        $answers = [['question_id' => $start->json('questions.0.question_id'), 'option_id' => null]];

        $this->postJson("/api/student/review-bot/sessions/{$attemptId}/submit", ['answers' => $answers])->assertOk();
        $this->postJson("/api/student/review-bot/sessions/{$attemptId}/submit", ['answers' => $answers])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This review session was already submitted.');
    }

    public function test_a_subject_with_no_published_questions_cannot_start(): void
    {
        $this->beginSession(['subject' => 'Information Technology'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No published Review Bot questions match this filter yet. Try a broader subject/topic, or check back after your teacher publishes more.');
    }

    public function test_a_session_needs_a_subject_and_a_sensible_question_count(): void
    {
        $this->postJson('/api/student/review-bot/sessions', ['question_count' => 3])->assertJsonValidationErrors('subject');
        $this->beginSession(['question_count' => 2])->assertJsonValidationErrors('question_count');
        $this->beginSession(['question_count' => 31])->assertJsonValidationErrors('question_count');
        $this->beginSession(['difficulty' => 'impossible'])->assertJsonValidationErrors('difficulty');
    }

    public function test_the_weak_area_session_needs_graded_exams_first(): void
    {
        $this->postJson('/api/student/review-bot/sessions', ['use_weak_area' => true, 'question_count' => 3])
            ->assertStatus(422)
            ->assertJsonPath('message', 'We need at least a couple of graded exams before we can detect your weak areas.');
    }

    public function test_the_weak_area_session_practises_what_the_student_missed_in_exams(): void
    {
        $room = $this->makeRoom($this->teacher, [$this->student]);
        $bank = $this->makeBank($this->teacher, 3, ['subject' => self::SUBJECT], ['topic_id' => $this->topic->id]);
        $exam = $this->makeExam($this->teacher, $bank, [$room]);
        $this->makeSubmittedAttempt($exam, $room, $this->student, [false, false, false]);

        $weakArea = $this->getJson('/api/student/review-bot/catalog')->assertOk()->json('weak_area');
        $this->assertSame('Authority Control', $weakArea['topic']);

        $this->postJson('/api/student/review-bot/sessions', ['use_weak_area' => true, 'question_count' => 3])->assertCreated();

        $this->assertSame(ReviewBotAttempt::SOURCE_AUTO_WEAK_AREA, ReviewBotAttempt::query()->latest('id')->value('source'));
    }

    public function test_only_the_student_who_owns_a_session_can_hand_it_in_or_read_it(): void
    {
        $attemptId = $this->beginSession()->assertCreated()->json('attempt.id');
        $body = ['answers' => [['question_id' => 1]]];

        Sanctum::actingAs($this->makeStudent());
        $this->postJson("/api/student/review-bot/sessions/{$attemptId}/submit", $body)->assertForbidden();
        $this->getJson("/api/student/review-bot/sessions/{$attemptId}")->assertForbidden();

        Sanctum::actingAs($this->teacher);
        $this->getJson('/api/student/review-bot/catalog')->assertForbidden();
        $this->beginSession()->assertForbidden();
        $this->postJson("/api/student/review-bot/sessions/{$attemptId}/submit", $body)->assertForbidden();
        $this->getJson("/api/student/review-bot/sessions/{$attemptId}")->assertForbidden();
    }

    public function test_a_disabled_student_is_signed_out_of_the_review_bot(): void
    {
        $attemptId = $this->beginSession()->assertCreated()->json('attempt.id');
        $this->student->forceFill(['is_active' => false])->save();

        foreach ([
            ['GET', '/api/student/review-bot/catalog'],
            ['POST', '/api/student/review-bot/sessions'],
            ['POST', "/api/student/review-bot/sessions/{$attemptId}/submit"],
            ['GET', "/api/student/review-bot/sessions/{$attemptId}"],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }
}
