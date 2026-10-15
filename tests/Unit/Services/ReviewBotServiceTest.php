<?php

namespace Tests\Unit\Services;

use App\Jobs\GenerateReviewBotQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\ReviewBotAdminService;
use App\Services\ReviewBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ReviewBotService, called directly: how a session widens past a thin
 * subtopic or difficulty and says so, what it recommends after a session,
 * and how subject names typed in different ways are matched.
 */
class ReviewBotServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const SUBJECT = 'Reference Services';

    private User $teacher;

    private User $student;

    private Topic $topic;

    private Subtopic $subtopic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->topic = $this->makeTopic(self::SUBJECT, 'Reference Interview', $this->teacher);
        $this->subtopic = $this->makeSubtopic($this->topic, 'Open questions', $this->teacher);
    }

    private function service(): ReviewBotService
    {
        return app(ReviewBotService::class);
    }

    private function exposed(): object
    {
        return new class(app(DashboardAnalyticsService::class), app(ReviewBotAdminService::class)) extends ReviewBotService {
            public function subjectLabel(mixed $subject): string
            {
                return $this->normalizeSubjectLabel($subject);
            }

            public function recommend(ReviewBotAttempt $attempt, float $score): array
            {
                return $this->buildRecommendation($attempt, $score);
            }

            public function note(string $requested, string $used, bool $relaxed, int $found, int $wanted): ?string
            {
                return $this->scopeNote($requested, $used, $relaxed, $found, $wanted);
            }
        };
    }

    /** Publishes Review Bot questions in the subject, under the given topic and subtopic. */
    private function publish(int $count, ?int $topicId = null, ?int $subtopicId = null, string $difficulty = 'medium'): void
    {
        $bank = app(ReviewBotAdminService::class)->reviewBotBankFor(self::SUBJECT, $this->teacher->id);
        $start = QuestionBankQuestion::where('question_bank_id', $bank->id)->count();

        for ($number = 1; $number <= $count; $number++) {
            $this->makeQuestion($bank, $start + $number, [
                'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                'status' => QuestionBankQuestion::STATUS_PUBLISHED,
                'subject' => self::SUBJECT,
                'topic_id' => $topicId,
                'subtopic_id' => $subtopicId,
                'difficulty' => $difficulty,
            ]);
        }
    }

    private function attempt(array $attributes = []): ReviewBotAttempt
    {
        return ReviewBotAttempt::create([
            'user_id' => $this->student->id,
            'subject' => self::SUBJECT,
            'source' => ReviewBotAttempt::SOURCE_MANUAL,
            'status' => ReviewBotAttempt::STATUS_IN_PROGRESS,
            'total_items' => 0,
            'answered_count' => 0,
            'correct_answers' => 0,
            'started_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_a_thin_subtopic_tops_up_from_its_topic_and_queues_more_content(): void
    {
        Queue::fake();
        $this->publish(1, $this->topic->id, $this->subtopic->id);
        $this->publish(4, $this->topic->id);

        $session = $this->service()->startSession($this->student, [
            'subject' => self::SUBJECT,
            'topic_id' => $this->topic->id,
            'subtopic_id' => $this->subtopic->id,
            'question_count' => 4,
        ]);

        $this->assertCount(4, $session['questions']);
        $this->assertSame('topic', $session['scope_used']);
        $this->assertStringContainsString('also draws from the wider topic', $session['scope_note']);
        Queue::assertPushed(GenerateReviewBotQuestionsJob::class);
    }

    public function test_a_difficulty_with_too_few_questions_is_relaxed_and_said_so(): void
    {
        Queue::fake();
        $this->publish(1, $this->topic->id, null, 'hard');
        $this->publish(3, $this->topic->id, null, 'easy');

        $session = $this->service()->startSession($this->student, [
            'subject' => self::SUBJECT,
            'topic_id' => $this->topic->id,
            'difficulty' => 'hard',
            'question_count' => 3,
        ]);

        $this->assertCount(3, $session['questions']);
        $this->assertStringContainsString('fall outside the difficulty you picked', $session['scope_note']);
    }

    public function test_the_scope_notes_name_each_kind_of_widening(): void
    {
        $service = $this->exposed();

        $this->assertNull($service->note('subject', 'subject', false, 5, 5));
        $this->assertStringContainsString('wider topic', $service->note('subtopic', 'topic', false, 5, 5));
        $this->assertStringContainsString('wider subject', $service->note('topic', 'subject', false, 5, 5));
        $this->assertStringContainsString('already worked through', $service->note('missed', 'subject', false, 5, 5));
        $this->assertStringContainsString('1 question instead of 5', $service->note('subject', 'subject', false, 1, 5));
        $this->assertStringContainsString('2 questions instead of 5', $service->note('subject', 'subject', true, 2, 5));
    }

    public function test_a_session_that_no_longer_exists_cannot_be_handed_in(): void
    {
        $attempt = $this->attempt();
        $attempt->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This review session no longer exists.');

        $this->service()->submitSession($attempt, []);
    }

    public function test_handing_in_an_empty_session_scores_zero_and_records_nothing(): void
    {
        $result = $this->service()->submitSession($this->attempt(), []);

        $this->assertSame(0, $result['total_items']);
        $this->assertSame(0, \App\Models\ReviewBotItemState::where('user_id', $this->student->id)->count());
    }

    public function test_the_advice_after_a_session_depends_on_the_score(): void
    {
        $service = $this->exposed();
        $attempt = $this->attempt(['topic_id' => $this->topic->id]);

        $this->assertSame('repeat_topic', $service->recommend($attempt, 40)['action']);
        $this->assertSame('keep_practicing', $service->recommend($attempt, 65)['action']);
        // Strong, and no other weak area known.
        $this->assertSame('keep_going', $service->recommend($attempt, 90)['action']);
    }

    public function test_a_strong_session_points_to_the_next_weak_area_from_exams(): void
    {
        $otherTopic = $this->makeTopic(self::SUBJECT, 'Ready Reference', $this->teacher);
        $room = $this->makeRoom($this->teacher, [$this->student]);
        $bank = $this->makeBank($this->teacher, 3, ['subject' => self::SUBJECT], ['topic_id' => $otherTopic->id]);
        $exam = $this->makeExam($this->teacher, $bank, [$room]);
        $this->makeSubmittedAttempt($exam, $room, $this->student, [false, false, false]);

        $advice = $this->exposed()->recommend($this->attempt(['topic_id' => $this->topic->id]), 90);

        $this->assertSame('next_topic', $advice['action']);
        $this->assertStringContainsString('Ready Reference', $advice['message']);
        $this->assertSame('Ready Reference', $advice['next_focus']['topic']);
    }

    public function test_subject_names_typed_in_different_ways_are_matched(): void
    {
        $service = $this->exposed();

        $this->assertSame('General', $service->subjectLabel('   '));
        $this->assertSame('Cataloging and Classification', $service->subjectLabel('Cataloguing & Classification'));
        $this->assertSame('Selection and Acquisition', $service->subjectLabel('acquisition/selection'));
        $this->assertSame('Information Technology', $service->subjectLabel('IT'));
        // Contains a known name.
        $this->assertSame('Reference Services', $service->subjectLabel('Intro to Reference Services 2'));
        // Nothing known: tidied up and kept.
        $this->assertSame('Archival Studies', $service->subjectLabel('archival   studies'));
    }
}
