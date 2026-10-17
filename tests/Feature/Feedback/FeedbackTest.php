<?php

namespace Tests\Feature\Feedback;

use App\Mail\FeedbackForDevelopers;
use App\Models\Feedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Feedback from the Settings page (routes/api/feedback.php): a problem goes
 * to the developers at once, anything else only when the administrator
 * sends it on; the sender sees the status and the reply.
 */
class FeedbackTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private const DEVELOPERS = 'developers@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.feedback.developer_email' => self::DEVELOPERS]);
        Mail::fake();
    }

    private function send(array $overrides = [])
    {
        return $this->postJson('/api/feedback', [
            'type' => Feedback::TYPE_IDEA,
            'rating' => 4,
            'message' => 'A dark theme would help in the evening.',
            'may_contact' => false,
            'context' => ['page' => '/dashboard/rooms', 'app_version' => '/assets/index-abc.js', 'screen' => '390x844'],
            ...$overrides,
        ]);
    }

    public function test_a_problem_report_is_emailed_to_the_developers_as_it_arrives(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/129.0 Mobile Safari/537.36')
            ->send(['type' => Feedback::TYPE_PROBLEM, 'message' => 'The timer froze on question 12.'])
            ->assertCreated()
            ->assertJsonPath('feedback.status', Feedback::STATUS_NEW)
            ->assertJsonPath('feedback.sent_to_developers', true);

        $feedback = Feedback::firstOrFail();
        $this->assertSame('/dashboard/rooms', $feedback->context['page']);
        $this->assertSame('390x844', $feedback->context['screen']);
        $this->assertSame('student', $feedback->context['role']);
        $this->assertNotEmpty($feedback->context['device']);
        $this->assertNotNull($feedback->developer_emailed_at);

        Mail::assertSent(FeedbackForDevelopers::class, fn ($mail) => $mail->hasTo(self::DEVELOPERS) && $mail->feedback->is($feedback));
    }

    public function test_an_idea_waits_for_the_administrator_before_the_developers_see_it(): void
    {
        Sanctum::actingAs($this->makeTeacher());
        $this->send()->assertCreated()->assertJsonPath('feedback.sent_to_developers', false);
        Mail::assertNothingSent();

        $feedback = Feedback::firstOrFail();
        Sanctum::actingAs($admin = $this->makeAdmin());

        $this->patchJson("/api/admin/feedback/{$feedback->id}", ['action' => 'send_to_developers', 'developer_note' => 'Students asked for this too.'])
            ->assertOk()
            ->assertJsonPath('email_sent', true)
            ->assertJsonPath('feedback.status', Feedback::STATUS_PLANNED)
            ->assertJsonPath('feedback.reviewer.id', $admin->id);

        Mail::assertSent(FeedbackForDevelopers::class, function (FeedbackForDevelopers $mail) {
            $html = $mail->render();

            return str_contains($html, 'Students asked for this too.') && str_contains($html, 'A dark theme would help');
        });
    }

    public function test_the_developers_get_the_sender_s_name_only_if_they_agreed_to_be_contacted(): void
    {
        $student = $this->makeStudent(['name' => 'Ana Reyes', 'email' => 'ana@lnu.edu.ph']);
        Sanctum::actingAs($student);

        $this->send(['type' => Feedback::TYPE_PROBLEM, 'may_contact' => false])->assertCreated();
        $this->send(['type' => Feedback::TYPE_PROBLEM, 'may_contact' => true, 'message' => 'Second report about the timer.'])->assertCreated();

        [$private, $open] = Feedback::query()->orderBy('id')->get()->all();

        Mail::assertSent(FeedbackForDevelopers::class, function (FeedbackForDevelopers $mail) use ($private) {
            return !$mail->feedback->is($private)
                || (!str_contains($mail->render(), 'ana@lnu.edu.ph') && $mail->replyTo === []);
        });
        Mail::assertSent(FeedbackForDevelopers::class, function (FeedbackForDevelopers $mail) use ($open) {
            return $mail->feedback->is($open)
                && str_contains($mail->render(), 'ana@lnu.edu.ph')
                && $mail->hasReplyTo('ana@lnu.edu.ph');
        });
    }

    public function test_the_sender_sees_the_reply_or_the_reason_it_was_turned_down(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $this->send(['type' => Feedback::TYPE_QUESTION, 'message' => 'Where do I see my old exams?'])->assertCreated();
        $this->send(['message' => 'Please add a music player to the exam.'])->assertCreated();
        [$question, $idea] = Feedback::query()->orderBy('id')->get()->all();

        Sanctum::actingAs($this->makeAdmin());
        $this->patchJson("/api/admin/feedback/{$question->id}", ['action' => 'answer', 'reply' => 'Analytics, then Exam History.'])
            ->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_ANSWERED);
        $this->patchJson("/api/admin/feedback/{$idea->id}", ['action' => 'decline', 'reply' => 'It would distract during exams.'])
            ->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_DECLINED);
        Mail::assertNothingSent();

        Sanctum::actingAs($student);
        $mine = collect($this->getJson('/api/feedback')->assertOk()->json('feedback'))->keyBy('id');

        $this->assertSame('Analytics, then Exam History.', $mine[$question->id]['admin_reply']);
        $this->assertSame(Feedback::STATUS_DECLINED, $mine[$idea->id]['status']);
        $this->assertSame('It would distract during exams.', $mine[$idea->id]['admin_reply']);
        // The note to the developers and who reviewed it stay with the administrator.
        $this->assertArrayNotHasKey('developer_note', $mine[$idea->id]);
        $this->assertArrayNotHasKey('reviewer', $mine[$idea->id]);
    }

    public function test_a_problem_is_closed_as_fixed_or_as_not_a_problem_and_can_be_reopened(): void
    {
        Sanctum::actingAs($this->makeStudent());
        $this->send(['type' => Feedback::TYPE_PROBLEM])->assertCreated();
        $feedback = Feedback::firstOrFail();

        Sanctum::actingAs($this->makeAdmin());
        $url = "/api/admin/feedback/{$feedback->id}";

        $this->patchJson($url, ['action' => 'send_to_developers'])->assertStatus(422)->assertJsonPath('message', 'The developers already have this.');
        $this->patchJson($url, ['action' => 'reopen'])->assertStatus(422)->assertJsonPath('message', 'This is still open.');
        $this->patchJson($url, ['action' => 'done', 'reply' => 'Fixed in today\'s update.'])->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_DONE);
        $this->patchJson($url, ['action' => 'done'])->assertStatus(422);
        // The developers already have it, so it reopens as theirs.
        $this->patchJson($url, ['action' => 'reopen'])->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_PLANNED);
        $this->patchJson($url, ['action' => 'decline'])->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_DECLINED);
        $this->patchJson($url, ['action' => 'decline'])->assertStatus(422);
    }

    public function test_closed_feedback_is_reopened_before_it_goes_to_the_developers_and_a_reply_needs_words(): void
    {
        Sanctum::actingAs($this->makeStudent());
        $this->send()->assertCreated();
        $feedback = Feedback::firstOrFail();

        Sanctum::actingAs($this->makeAdmin());
        $url = "/api/admin/feedback/{$feedback->id}";

        $this->patchJson($url, ['action' => 'answer', 'reply' => '   '])->assertStatus(422)->assertJsonPath('message', 'Write a reply first.');
        $this->patchJson($url, ['action' => 'decline'])->assertOk();
        $this->patchJson($url, ['action' => 'send_to_developers'])->assertStatus(422)->assertJsonPath('message', 'Reopen it before sending it to the developers.');
        $this->patchJson($url, ['action' => 'reopen'])->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_NEW);
        $this->patchJson($url, ['action' => 'send_to_developers'])->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_PLANNED);
    }

    public function test_a_failed_email_is_recorded_and_can_be_sent_again(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP is down'));
        Sanctum::actingAs($this->makeStudent());

        $this->send(['type' => Feedback::TYPE_PROBLEM])->assertCreated()->assertJsonPath('feedback.sent_to_developers', false);

        $feedback = Feedback::firstOrFail();
        $this->assertSame('SMTP is down', $feedback->developer_email_error);

        Mail::swap(new \Illuminate\Support\Testing\Fakes\MailFake(new \Illuminate\Mail\MailManager(app())));
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/feedback')->assertJsonPath('feedback.0.developer_email_error', 'SMTP is down');
        $this->patchJson("/api/admin/feedback/{$feedback->id}", ['action' => 'send_to_developers'])->assertOk()->assertJsonPath('email_sent', true);
        $this->assertNull($feedback->fresh()->developer_email_error);
    }

    public function test_with_no_developer_address_feedback_stays_in_the_system(): void
    {
        config(['services.feedback.developer_email' => '']);
        Sanctum::actingAs($this->makeStudent());
        $this->send(['type' => Feedback::TYPE_PROBLEM])->assertCreated();

        Mail::assertNothingSent();
        $this->assertSame('No developer email address is set.', Feedback::firstOrFail()->developer_email_error);

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson('/api/admin/feedback')->assertOk()->assertJsonPath('developer_email_set', false);
    }

    public function test_the_administrator_s_list_filters_and_counts_by_status(): void
    {
        Sanctum::actingAs($this->makeStudent());
        $this->send()->assertCreated();
        $this->send(['type' => Feedback::TYPE_PROBLEM])->assertCreated();
        $this->send(['type' => Feedback::TYPE_QUESTION])->assertCreated();
        Feedback::query()->where('type', Feedback::TYPE_QUESTION)->update(['status' => Feedback::STATUS_ANSWERED]);

        Sanctum::actingAs($this->makeAdmin());

        $all = $this->getJson('/api/admin/feedback')->assertOk();
        $this->assertSame(2, $all->json('counts.new'));
        $this->assertSame(1, $all->json('counts.answered'));
        $this->assertSame(3, $all->json('meta.total'));

        $this->getJson('/api/admin/feedback?status=new&type=problem')->assertOk()->assertJsonCount(1, 'feedback')->assertJsonPath('feedback.0.type', 'problem');

        $question = Feedback::query()->where('type', Feedback::TYPE_QUESTION)->firstOrFail();
        $this->getJson("/api/admin/feedback/{$question->id}")->assertOk()->assertJsonPath('feedback.status', Feedback::STATUS_ANSWERED);
    }

    public function test_feedback_is_checked_before_it_is_kept(): void
    {
        Sanctum::actingAs($this->makeStudent());

        $this->send(['message' => 'too short'])->assertStatus(422)->assertJsonValidationErrors(['message']);
        $this->send(['type' => 'complaint'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->send(['rating' => 6])->assertStatus(422)->assertJsonValidationErrors(['rating']);
        $this->send(['rating' => null])->assertCreated();
    }

    public function test_one_account_can_send_five_an_hour(): void
    {
        Sanctum::actingAs($this->makeStudent());

        foreach (range(1, 5) as $ignored) {
            $this->send()->assertCreated();
        }

        $this->send()->assertStatus(429);
        $this->assertSame(5, Feedback::count());
    }

    public function test_people_see_only_their_own_and_only_an_administrator_sees_everyone_s(): void
    {
        Sanctum::actingAs($this->makeStudent());
        $this->send()->assertCreated();
        $feedback = Feedback::firstOrFail();

        Sanctum::actingAs($teacher = $this->makeTeacher());
        $this->getJson('/api/feedback')->assertOk()->assertJsonCount(0, 'feedback');
        $this->getJson('/api/admin/feedback')->assertForbidden();
        $this->getJson("/api/admin/feedback/{$feedback->id}")->assertForbidden();
        $this->patchJson("/api/admin/feedback/{$feedback->id}", ['action' => 'done'])->assertForbidden();

        $teacher->forceFill(['is_active' => false])->save();
        $this->send()->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        $this->getJson('/api/feedback')->assertForbidden();
    }

    public function test_feedback_outlives_the_account_that_sent_it(): void
    {
        $student = $this->makeStudent();
        Sanctum::actingAs($student);
        $this->send(['type' => Feedback::TYPE_PROBLEM])->assertCreated();

        $student->tokens()->delete();
        $student->delete();

        Sanctum::actingAs($this->makeAdmin());
        $this->getJson('/api/admin/feedback')->assertOk()->assertJsonPath('feedback.0.user', null);
    }
}
