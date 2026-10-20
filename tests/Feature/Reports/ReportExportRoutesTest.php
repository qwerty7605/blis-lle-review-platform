<?php

namespace Tests\Feature\Reports;

use App\Jobs\SendStudentReportEmailJob;
use App\Mail\StudentReportMail;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\Demo\DemoData;
use App\Services\ReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;
use ZipArchive;

/**
 * The teacher's exports of one exam in one room (routes/api/reports.php):
 * complete results as CSV and XLSX, the summary and answer key PDFs, one
 * student's report as a PDF or by email, every student's report by email,
 * and every report in a ZIP. Each is checked for what it hands back and for
 * the rules on who may ask for it.
 */
class ReportExportRoutesTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private Room $room;

    private Exam $exam;

    /** @var array<int, User> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->students = [
            $this->makeStudent(['name' => 'Ana Cruz', 'email' => 'ana@lnu.edu.ph']),
            $this->makeStudent(['name' => 'Ben Diaz', 'email' => 'ben@lnu.edu.ph']),
        ];
        $this->room = $this->makeRoom($this->teacher, $this->students, ['code' => 'RPT001']);
        $bank = $this->makeBank($this->teacher, 3);
        $this->exam = $this->makeExam($this->teacher, $bank, [$this->room], ['title' => 'Reference Quiz']);

        $this->makeSubmittedAttempt($this->exam, $this->room, $this->students[0], [true, true, false]);
        $this->makeSubmittedAttempt($this->exam, $this->room, $this->students[1], [false, null, true]);

        Sanctum::actingAs($this->teacher);
    }

    private function base(): string
    {
        return "/api/reports/exams/{$this->exam->id}/rooms/{$this->room->id}";
    }

    public function test_complete_results_csv_lists_every_student_and_is_audited(): void
    {
        $response = $this->get($this->base() . '/complete-results.csv')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('reference-quiz-rpt001-complete-results-', $response->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(3, $lines, 'A heading row and one row per student.');
        $this->assertStringContainsString('Ana Cruz', $response->streamedContent());
        $this->assertStringContainsString('Ben Diaz', $response->streamedContent());

        $this->assertTrue(AuditLog::where('action', 'report.export.complete_results_csv')->exists());
    }

    public function test_complete_results_xlsx_has_a_results_sheet_and_an_item_analysis_sheet(): void
    {
        $response = $this->get($this->base() . '/complete-results.xlsx')->assertOk();

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());

        $this->assertSame(['Results', 'Item Analysis'], $book->getSheetNames());
        $this->assertSame('Item', $book->getSheetByName('Item Analysis')->getCell('A1')->getValue());
        // Three questions, one row each under the heading.
        $this->assertSame(4, $book->getSheetByName('Item Analysis')->getHighestDataRow());
        $this->assertSame(3, $book->getSheetByName('Results')->getHighestDataRow());
        $this->assertTrue(AuditLog::where('action', 'report.export.complete_results_xlsx')->exists());
    }

    public function test_complete_results_xlsx_still_builds_for_a_session_nobody_sat(): void
    {
        $emptyRoom = $this->makeRoom($this->teacher);
        $this->exam->rooms()->attach($emptyRoom->id, ['assigned_by' => $this->teacher->id]);

        $response = $this->get("/api/reports/exams/{$this->exam->id}/rooms/{$emptyRoom->id}/complete-results.xlsx")->assertOk();

        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(1, $book->getSheetByName('Results')->getHighestDataRow());
    }

    public function test_summary_and_answer_key_come_back_as_pdfs(): void
    {
        foreach (['summary.pdf' => 'report.export.summary_pdf', 'answer-key.pdf' => 'report.export.answer_key_pdf'] as $file => $action) {
            $response = $this->get($this->base() . '/' . $file)->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertTrue(AuditLog::where('action', $action)->exists(), $action);
        }
    }

    public function test_one_student_s_report_downloads_as_a_pdf(): void
    {
        $student = $this->students[0];

        $response = $this->get($this->base() . "/students/{$student->id}/student-report.pdf")->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString($student->student_id . '-ana-cruz-attempt-', $response->headers->get('Content-Disposition'));
        $this->assertTrue(AuditLog::where('action', 'report.export.individual_student_pdf')->exists());
    }

    public function test_one_student_s_report_is_refused_for_someone_who_is_not_a_student_of_the_room(): void
    {
        $outsider = $this->makeStudent();
        $staff = $this->makeTeacher();
        $member = $this->makeStudent();
        $this->room->members()->attach($member->id);

        $this->getJson($this->base() . "/students/{$staff->id}/student-report.pdf")
            ->assertStatus(422)->assertJsonPath('message', 'Selected user is not a student.');
        $this->getJson($this->base() . "/students/{$outsider->id}/student-report.pdf")
            ->assertStatus(422)->assertJsonPath('message', 'Student is not enrolled in the selected room.');
        $this->getJson($this->base() . "/students/{$member->id}/student-report.pdf")
            ->assertNotFound();
    }

    public function test_one_student_s_report_is_emailed_to_them(): void
    {
        Mail::fake();
        $student = $this->students[0];

        $this->postJson($this->base() . "/students/{$student->id}/student-report.email")
            ->assertOk()
            ->assertJsonPath('student.email', 'ana@lnu.edu.ph');

        Mail::assertSent(StudentReportMail::class, fn (StudentReportMail $mail) => $mail->hasTo('ana@lnu.edu.ph'));
        $this->assertTrue(AuditLog::where('action', 'report.email.individual_student_pdf')->exists());
    }

    public function test_emailing_one_report_refuses_each_case_it_cannot_send(): void
    {
        Mail::fake();
        $url = fn (User $user) => $this->base() . "/students/{$user->id}/student-report.email";

        $staff = $this->makeTeacher();
        $this->postJson($url($staff))->assertStatus(422)->assertJsonPath('message', 'Selected user is not a student.');

        $outsider = $this->makeStudent();
        $this->postJson($url($outsider))->assertStatus(422)->assertJsonPath('message', 'Student is not enrolled in the selected room.');

        $badAddress = $this->makeStudent(['email' => 'not-an-address']);
        $this->room->members()->attach($badAddress->id);
        $this->postJson($url($badAddress))->assertStatus(422)->assertJsonPath('message', 'Student does not have a valid email address.');

        $demo = $this->makeStudent(['email' => 'demo.student@' . DemoData::EMAIL_DOMAIN]);
        $this->room->members()->attach($demo->id);
        $this->postJson($url($demo))->assertStatus(422)->assertJsonPath('message', 'This is a demo account, so its address cannot receive email.');

        $unverified = $this->makeStudent(['email_verified_at' => null]);
        $this->room->members()->attach($unverified->id);
        $this->postJson($url($unverified), ['verified_only' => true])->assertStatus(422)->assertJsonPath('message', 'Student email is not verified.');

        $noAttempt = $this->makeStudent();
        $this->room->members()->attach($noAttempt->id);
        $this->postJson($url($noAttempt))->assertNotFound();

        Mail::assertNothingSent();
    }

    public function test_a_mail_server_failure_is_reported_instead_of_claiming_success(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        $response = $this->postJson($this->base() . "/students/{$this->students[0]->id}/student-report.email")
            ->assertStatus(502);

        // The mail server's own words stay out of the teacher's screen.
        $this->assertStringNotContainsString('SMTP', $response->getContent());
    }

    public function test_bulk_email_queues_who_it_can_and_names_the_reason_for_everyone_else(): void
    {
        Queue::fake();

        $badAddress = $this->makeStudent(['name' => 'Bad Address', 'email' => 'nope']);
        $demo = $this->makeStudent(['name' => 'Demo Person', 'email' => 'd@' . DemoData::EMAIL_DOMAIN]);
        $unverified = $this->makeStudent(['name' => 'Not Verified', 'email_verified_at' => null]);
        $noAttempt = $this->makeStudent(['name' => 'No Attempt']);
        foreach ([$badAddress, $demo, $unverified, $noAttempt] as $student) {
            $this->room->members()->attach($student->id);
        }

        $response = $this->postJson($this->base() . '/student-reports.email', ['verified_only' => true])->assertOk();

        $this->assertSame(6, $response->json('summary.students_total'));
        $this->assertSame(2, $response->json('summary.queued_count'));
        $reasons = collect($response->json('issues'))->pluck('reason', 'student_name')->all();
        $this->assertSame([
            'Bad Address' => 'invalid_email',
            'Demo Person' => 'demo_account',
            'No Attempt' => 'no_attempt',
            'Not Verified' => 'email_not_verified',
        ], collect($reasons)->sortKeys()->all());

        Queue::assertPushed(SendStudentReportEmailJob::class, 2);
        Queue::assertPushed(SendStudentReportEmailJob::class, fn (SendStudentReportEmailJob $job) => $job->recipientEmail === 'ana@lnu.edu.ph');
        $this->assertTrue(AuditLog::where('action', 'report.email.student_reports_bulk')->exists());
    }

    public function test_the_queued_email_sends_the_student_their_report(): void
    {
        Mail::fake();
        $attempt = ExamAttempt::where('user_id', $this->students[0]->id)->firstOrFail();

        dispatch_sync(new SendStudentReportEmailJob($attempt->id, $this->teacher->id, 'ana@lnu.edu.ph'));

        Mail::assertSent(StudentReportMail::class, fn (StudentReportMail $mail) => $mail->hasTo('ana@lnu.edu.ph'));
    }

    public function test_a_queued_email_that_keeps_failing_is_written_to_the_audit_log(): void
    {
        $attempt = ExamAttempt::where('user_id', $this->students[0]->id)->firstOrFail();

        (new SendStudentReportEmailJob($attempt->id, $this->teacher->id, 'ana@lnu.edu.ph'))
            ->failed(new \RuntimeException('Mailbox full'));

        $log = AuditLog::where('action', 'report.email.failed')->firstOrFail();
        $this->assertSame((int) $this->students[0]->id, (int) $log->metadata['student_id']);
        $this->assertStringContainsString('Ana Cruz', $log->description);
    }

    public function test_a_student_s_copy_leaves_out_the_answers_when_results_are_hidden(): void
    {
        $attempt = ExamAttempt::where('user_id', $this->students[0]->id)->firstOrFail();
        $reports = app(ReportExportService::class);

        $this->exam->update(['results_visibility_mode' => 'hidden']);
        $this->assertFalse($reports->answersShownToStudent($attempt->fresh()));
        $this->exam->update(['results_visibility_mode' => 'visible']);
        $this->assertTrue($reports->answersShownToStudent($attempt->fresh()));

        $report = $reports->buildAttemptReportData($attempt->fresh());
        $hidden = view('reports.student_report_pdf', ['report' => $report, 'showAnswers' => false])->render();
        $shown = view('reports.student_report_pdf', ['report' => $report, 'showAnswers' => true])->render();
        $this->assertStringNotContainsString('Correct Answer', $hidden);
        $this->assertStringContainsString('kept the correct answers for this exam hidden', $hidden);
        $this->assertStringContainsString('Correct Answer', $shown);
    }

    public function test_every_report_comes_back_in_one_zip(): void
    {
        $response = $this->get($this->base() . '/student-reports.zip')->assertOk();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringEndsWith('.pdf', $zip->getNameIndex(0));
        $zip->close();

        $this->assertTrue(AuditLog::where('action', 'report.export.student_reports_zip')->exists());
    }

    public function test_the_zip_is_refused_when_nobody_has_sat_the_exam(): void
    {
        $emptyRoom = $this->makeRoom($this->teacher);
        $this->exam->rooms()->attach($emptyRoom->id, ['assigned_by' => $this->teacher->id]);

        $this->getJson("/api/reports/exams/{$this->exam->id}/rooms/{$emptyRoom->id}/student-reports.zip")
            ->assertStatus(422)
            ->assertJsonPath('message', 'No student attempts are available for this session.');
    }

    public function test_only_the_owning_teacher_or_an_administrator_may_export(): void
    {
        $paths = [
            'complete-results.csv',
            'complete-results.xlsx',
            'summary.pdf',
            'answer-key.pdf',
            "students/{$this->students[0]->id}/student-report.pdf",
            'student-reports.zip',
        ];

        Sanctum::actingAs($this->makeTeacher());
        foreach ($paths as $path) {
            $this->getJson($this->base() . '/' . $path)->assertForbidden();
        }
        $this->postJson($this->base() . '/student-reports.email')->assertForbidden();
        $this->postJson($this->base() . "/students/{$this->students[0]->id}/student-report.email")->assertForbidden();

        Sanctum::actingAs($this->students[0]);
        $this->getJson($this->base() . '/summary.pdf')->assertForbidden();

        Sanctum::actingAs($this->makeAdmin());
        $this->get($this->base() . '/summary.pdf')->assertOk();
    }

    public function test_a_teacher_cannot_export_their_exam_from_another_teacher_s_room(): void
    {
        $otherRoom = $this->makeRoom($this->makeTeacher());
        $this->exam->rooms()->attach($otherRoom->id, ['assigned_by' => $this->teacher->id]);

        $this->getJson("/api/reports/exams/{$this->exam->id}/rooms/{$otherRoom->id}/summary.pdf")->assertForbidden();
    }

    public function test_an_exam_not_assigned_to_the_room_is_refused(): void
    {
        $unassigned = $this->makeRoom($this->teacher);

        $this->getJson("/api/reports/exams/{$this->exam->id}/rooms/{$unassigned->id}/summary.pdf")
            ->assertStatus(422)
            ->assertJsonPath('message', 'This exam is not assigned to the selected room.');
    }

    public function test_a_disabled_account_is_signed_out_instead_of_exporting(): void
    {
        $this->teacher->forceFill(['is_active' => false])->save();

        $this->getJson($this->base() . '/summary.pdf')
            ->assertForbidden()
            ->assertJsonPath('message', 'Account is disabled.');
    }
}
