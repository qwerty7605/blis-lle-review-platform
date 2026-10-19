<?php

namespace Tests\Unit\Support;

use App\Mail\ServerErrorAlert;
use App\Support\ErrorAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Server errors are emailed to the developers, once per error per quiet
 * period, without the request body, and never for ordinary 4xx answers.
 */
class ErrorAlertsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutDefer(); // run the after-response send straight away
        Cache::store('file')->flush();
        config([
            'services.error_alerts.email' => 'dev@example.com',
            'services.error_alerts.in_local' => true,
        ]);

        Route::post('/api/test-boom', fn () => throw new RuntimeException('Disk full'));
        Route::post('/api/test-invalid', fn () => throw ValidationException::withMessages(['x' => 'bad']));
    }

    public function test_a_server_error_is_emailed_once_per_quiet_period(): void
    {
        $this->postJson('/api/test-boom', ['password' => 'Secret12345!'])->assertStatus(500);
        $this->postJson('/api/test-boom')->assertStatus(500);

        Mail::assertSent(ServerErrorAlert::class, 1);
        Mail::assertSent(ServerErrorAlert::class, function (ServerErrorAlert $mail) {
            return $mail->hasTo('dev@example.com')
                && $mail->details['class'] === RuntimeException::class
                && $mail->details['message'] === 'Disk full'
                && $mail->details['request'] === 'POST api/test-boom'
                && !str_contains(json_encode($mail->details), 'Secret12345!');
        });
    }

    public function test_ordinary_client_errors_send_nothing(): void
    {
        $this->postJson('/api/test-invalid')->assertStatus(422);
        $this->getJson('/api/no-such-route')->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_without_an_address(): void
    {
        config(['services.error_alerts.email' => null, 'services.feedback.developer_email' => null]);

        $this->postJson('/api/test-boom')->assertStatus(500);

        Mail::assertNothingSent();
    }

    public function test_the_feedback_address_is_used_when_no_alert_address_is_set(): void
    {
        config(['services.error_alerts.email' => null, 'services.feedback.developer_email' => 'feedback@example.com']);

        ErrorAlerts::report(new RuntimeException('Queue down'));

        Mail::assertSent(ServerErrorAlert::class, fn (ServerErrorAlert $mail) => $mail->hasTo('feedback@example.com'));
    }

    public function test_local_runs_send_nothing_unless_asked(): void
    {
        config(['services.error_alerts.in_local' => false]);

        $this->postJson('/api/test-boom')->assertStatus(500);

        Mail::assertNothingSent();
    }
}
