<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The Audit page (routes/api/admin.php, AuditLogBrowser): sign-ins are
 * logged, entries read as events with named targets, the filters narrow the
 * log, and the CSV export is itself logged and safe to open in a spreadsheet.
 */
class AuditLogPageTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function log(?User $actor, string $action, array $attributes = []): AuditLog
    {
        return AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'description' => $attributes['description'] ?? $action,
            ...$attributes,
        ]);
    }

    public function test_sign_ins_refusals_and_sign_outs_are_logged_without_the_password(): void
    {
        $student = $this->makeStudent(['email' => 'ana@lnu.edu.ph', 'password' => 'Secret12345!']);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36')
            ->postJson('/api/auth/login', ['identifier' => 'ana@lnu.edu.ph', 'password' => 'wrong-password'])
            ->assertStatus(401);
        $this->postJson('/api/auth/login', ['identifier' => 'nobody@lnu.edu.ph', 'password' => 'whatever'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['identifier' => 'ana@lnu.edu.ph', 'password' => 'Secret12345!'])->assertOk();

        $failed = AuditLog::where('action', 'auth.login_failed')->orderBy('id')->get();
        $this->assertCount(2, $failed);
        $this->assertSame($student->id, $failed[0]->actor_id);
        $this->assertSame('invalid_credentials', $failed[0]->metadata['reason']);
        $this->assertStringContainsString('Chrome', $failed[0]->user_agent);
        $this->assertNull($failed[1]->actor_id);
        $this->assertSame('nobody@lnu.edu.ph', $failed[1]->metadata['identifier']);
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->where('actor_id', $student->id)->count());

        $everything = AuditLog::all()->toJson();
        $this->assertStringNotContainsString('wrong-password', $everything);
        $this->assertStringNotContainsString('Secret12345!', $everything);

        Sanctum::actingAs($student);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'auth.logout')->count());
    }

    public function test_entries_read_as_events_with_named_targets(): void
    {
        $admin = $this->makeAdmin();
        $teacher = $this->makeTeacher(['name' => 'Ramon Villanueva']);
        $exam = $this->makeExam($teacher, null, [], ['title' => 'Cataloging Midterm']);
        $this->log($teacher, 'exam.create', ['target_type' => 'exam', 'target_id' => $exam->id]);
        $this->log($teacher, 'exam.delete', ['target_type' => 'exam', 'target_id' => 999999, 'metadata' => ['title' => 'Old Quiz']]);
        $this->log(null, 'auth.login_failed', ['metadata' => ['identifier' => 'typo@lnu.edu.ph']]);

        Sanctum::actingAs($admin);
        $logs = collect($this->getJson('/api/admin/audit-logs')->assertOk()->json('logs'))->keyBy('action');

        $this->assertSame('Created an exam', $logs['exam.create']['event']);
        $this->assertSame('exams', $logs['exam.create']['category']);
        $this->assertSame('Cataloging Midterm', $logs['exam.create']['target']['name']);
        $this->assertSame('Exam', $logs['exam.create']['target']['type_label']);
        $this->assertSame('Old Quiz', $logs['exam.delete']['target']['name']);
        $this->assertTrue($logs['exam.delete']['target']['deleted']);
        $this->assertSame('failure', $logs['auth.login_failed']['outcome']);
        $this->assertSame('typo@lnu.edu.ph', $logs['auth.login_failed']['actor_hint']);
    }

    public function test_the_filters_narrow_the_log(): void
    {
        $admin = $this->makeAdmin();
        $ana = $this->makeStudent(['name' => 'Ana Reyes']);
        $ben = $this->makeStudent(['name' => 'Ben Cruz']);
        $this->log($ana, 'auth.login', ['target_type' => 'user', 'target_id' => $ana->id]);
        $this->log($ben, 'room.join', ['target_type' => 'room', 'target_id' => 5]);
        $this->log($ben, 'auth.login_failed', ['target_type' => 'user', 'target_id' => $ben->id]);
        $old = $this->log($ana, 'exam.attempt.start');
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        Sanctum::actingAs($admin);
        $actions = fn (string $query) => collect($this->getJson('/api/admin/audit-logs?'.$query)->assertOk()->json('logs'))->pluck('action')->sort()->values()->all();

        $this->assertSame(['auth.login', 'auth.login_failed'], $actions('category=access'));
        $this->assertSame(['auth.login', 'exam.attempt.start'], $actions('q=Ana%20Reyes'));
        $this->assertSame(['auth.login_failed', 'room.join'], $actions("actor_id={$ben->id}"));
        $this->assertSame(['auth.login_failed'], $actions('outcome=failure'));
        $this->assertSame(['room.join'], $actions('target_type=room&target_id=5'));
        $this->assertSame(['exam.attempt.start'], $actions('to='.urlencode(now()->subDays(5)->toIso8601String())));
        $this->assertNotContains('exam.attempt.start', $actions('from='.urlencode(now()->subDay()->toIso8601String())));

        $this->getJson("/api/admin/audit-logs?actor_id={$ben->id}")->assertJsonPath('filter_names.actor', 'Ben Cruz');
        $this->getJson('/api/admin/audit-logs?category=nonsense')->assertStatus(422);
    }

    public function test_the_export_is_a_csv_that_is_logged_and_safe_in_a_spreadsheet(): void
    {
        $admin = $this->makeAdmin(['name' => 'Head of BLIS']);
        $this->log($this->makeStudent(['name' => '=HYPERLINK("http://x")']), 'room.join', ['description' => '+1 formula']);

        Sanctum::actingAs($admin);
        $response = $this->get('/api/admin/audit-logs/export?tz=Asia/Manila')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Time (Asia/Manila)', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+1 formula", $csv);
        $this->assertSame(1, AuditLog::where('action', 'audit.export')->where('actor_id', $admin->id)->count());
    }

    public function test_only_an_administrator_reads_or_exports_the_log(): void
    {
        Sanctum::actingAs($this->makeTeacher());

        $this->getJson('/api/admin/audit-logs')->assertForbidden();
        $this->getJson('/api/admin/audit-logs/export')->assertForbidden();
    }
}
