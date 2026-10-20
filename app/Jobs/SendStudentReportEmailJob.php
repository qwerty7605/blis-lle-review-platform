<?php

namespace App\Jobs;

use App\Mail\StudentReportMail;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails one student their report PDF, for "Email All Reports". Run by the
 * queue worker rather than inside the teacher's request: a class of 45 is 45
 * PDFs and 45 mail sends, which outlast a request on the production server.
 * The PDF is the student's copy, so a "Hidden" exam's leaves the answers out.
 */
class SendStudentReportEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** A mail server that is briefly unreachable gets two more chances. */
    public int $tries = 3;

    /** @var array<int, int> seconds before the second and third try */
    public array $backoff = [60, 300];

    public function __construct(
        public int $attemptId,
        public int $teacherId,
        public string $recipientEmail,
    ) {
    }

    public function handle(ReportExportService $reports): void
    {
        $attempt = ExamAttempt::query()->find($this->attemptId);
        $teacher = User::query()->find($this->teacherId);

        if (!$attempt || !$teacher) {
            return; // deleted since it was queued
        }

        $pdf = $reports->studentReportPdf($attempt, forStudent: true);

        Mail::to($this->recipientEmail)->send(new StudentReportMail(
            $pdf['report'],
            $teacher,
            $pdf['binary'],
            $pdf['filename'],
        ));
    }

    /** Every try failed: the teacher finds out from the audit log. */
    public function failed(?Throwable $exception): void
    {
        $attempt = ExamAttempt::query()->with('student:id,name')->find($this->attemptId);

        app(AuditLogService::class)->record(
            User::query()->find($this->teacherId),
            'report.email.failed',
            'exam_attempt',
            $this->attemptId,
            'A student report email could not be sent: ' . ($attempt?->student?->name ?? 'student'),
            [
                'exam_id' => $attempt?->exam_id,
                'room_id' => $attempt?->room_id,
                'student_id' => $attempt?->user_id,
                'recipient_email' => $this->recipientEmail,
                'error' => $exception ? class_basename($exception) . ': ' . $exception->getMessage() : null,
            ],
        );
    }
}
