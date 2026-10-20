<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendStudentReportEmailJob;
use App\Mail\StudentReportMail;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\Demo\DemoData;
use App\Services\ReportExportService;
use App\Services\Reports\ClassResultsWorkbook;
use App\Support\Reports\ReportOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\HeaderUtils;
use ZipArchive;

class ReportController extends Controller
{
    public function __construct(
        private readonly DashboardAnalyticsService $dashboardAnalyticsService,
        private readonly ReportExportService $reportExportService,
    ) {
    }

    /**
     * Delegates to DashboardAnalyticsService rather than recomputing metrics here.
     * The inline version this replaces returned four counts; the service adds
     * session enrolments, attempt and completion figures, average score, pass rate,
     * and per-subject and per-session breakdowns. Purely additive for callers --
     * the frontend reads data.metrics wholesale.
     */
    public function overview(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // A teacher's dashboard is their own classes. An administrator's is the
        // whole programme, which ?teacher_id narrows to one person's.
        if (!$this->oversees($user)) {
            return response()->json($this->dashboardAnalyticsService->buildStaffReportOverview($user));
        }

        $teachers = User::query()
            ->whereIn('role', [User::ROLE_STAFF_MASTER_EXAMINER, User::ROLE_ADMIN])
            ->where(fn ($query) => $query
                ->whereIn('id', Room::query()->select('created_by'))
                ->orWhereIn('id', Exam::query()->select('created_by')))
            ->orderBy('name')
            ->get(['id', 'name']);

        $teacher = $request->filled('teacher_id')
            ? $teachers->firstWhere('id', (int) $request->query('teacher_id'))
            : null;

        return response()->json(array_merge(
            $this->dashboardAnalyticsService->buildStaffReportOverview(
                $teacher ? $this->dashboardAnalyticsService->asOwner($teacher) : $user
            ),
            ['scope' => ['teacher_id' => $teacher?->id, 'teachers' => $teachers]],
        ));
    }

    public function completeResultsCsv(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $this->reportExportService->buildSessionLatestResults($exam, $room);
        $table = $this->reportExportService->buildCompleteResultsTable($session);

        $this->recordAudit(
            $user,
            'report.export.complete_results_csv',
            'exam',
            $exam->id,
            'Exported complete results CSV',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) ($session['summary']['students_total'] ?? 0),
            ],
            $request,
        );

        return ReportOutput::csv($table['headers'], $table['rows'], $this->sessionFilename($exam, $room, 'Class Results', 'csv'));
    }

    public function completeResultsXlsx(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $this->reportExportService->buildSessionLatestResults($exam, $room);
        $tempFile = tempnam(sys_get_temp_dir(), 'blis_xlsx_');

        if ($tempFile === false) {
            return response()->json(['message' => 'Unable to generate export file.'], 500);
        }

        $spreadsheet = app(ClassResultsWorkbook::class)->build($session);
        (new Xlsx($spreadsheet))->save($tempFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $this->recordAudit(
            $user,
            'report.export.complete_results_xlsx',
            'exam',
            $exam->id,
            'Exported complete results XLSX',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) ($session['summary']['students_total'] ?? 0),
            ],
            $request,
        );

        return response()->download(
            $tempFile,
            $this->sessionFilename($exam, $room, 'Class Results', 'xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function summaryPdf(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $session = $this->reportExportService->buildSessionLatestResults($exam, $room);

        $this->recordAudit(
            $user,
            'report.export.summary_pdf',
            'exam',
            $exam->id,
            'Exported results summary PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
            ],
            $request,
        );

        return ReportOutput::pdfDownload('reports.summary_pdf', [
            'session' => $session,
        ], $this->sessionFilename($exam, $room, 'Class Summary', 'pdf'));
    }

    public function answerKeyPdf(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $answerKey = $this->reportExportService->buildAnswerKey($exam);

        $this->recordAudit(
            $user,
            'report.export.answer_key_pdf',
            'exam',
            $exam->id,
            'Exported answer key PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
            ],
            $request,
        );

        return ReportOutput::pdfDownload('reports.answer_key_pdf', [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject,
                'total_items' => (int) $exam->total_items,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
            ],
            'room' => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            'answerKey' => $answerKey,
        ], ReportOutput::filename([$exam->title, 'Answer Key'], 'pdf'));
    }

    public function studentReportPdf(
        Request $request,
            Exam $exam,
            Room $room,
            User $student,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        if ($student->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $isMember = $room->members()->where('users.id', (int) $student->id)->exists();
        if (!$isMember) {
            return response()->json(['message' => 'Student is not enrolled in the selected room.'], 422);
        }

        $attempt = $this->reportExportService->findLatestAttemptForStudent($exam, $room, (int) $student->id);
        if (!$attempt) {
            return response()->json(['message' => 'No attempt found for this student in the selected session.'], 404);
        }

        $pdf = $this->reportExportService->studentReportPdf($attempt);

        $this->recordAudit(
            $user,
            'report.export.individual_student_pdf',
            'exam_attempt',
            $attempt->id,
            'Exported individual student report PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'student_id' => (int) $student->id,
            ],
            $request,
        );

        return response($pdf['binary'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $pdf['filename'], Str::ascii($pdf['filename'])),
        ]);
    }

    public function emailStudentReport(
        Request $request,
            Exam $exam,
            Room $room,
            User $student,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $validated = $request->validate([
            'verified_only' => ['sometimes', 'boolean'],
        ]);
        $verifiedOnly = (bool) ($validated['verified_only'] ?? false);

        if ($student->role !== User::ROLE_STUDENT) {
            return response()->json(['message' => 'Selected user is not a student.'], 422);
        }

        $isMember = $room->members()->where('users.id', (int) $student->id)->exists();
        if (!$isMember) {
            return response()->json(['message' => 'Student is not enrolled in the selected room.'], 422);
        }

        $recipientEmail = trim((string) ($student->email ?? ''));
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Student does not have a valid email address.'], 422);
        }

        // Demo addresses cannot receive mail; sending would only bounce back to
        // the school's own mailbox.
        if (DemoData::isDemoEmail($recipientEmail)) {
            return response()->json(['message' => 'This is a demo account, so its address cannot receive email.'], 422);
        }

        if ($verifiedOnly && is_null($student->email_verified_at)) {
            return response()->json(['message' => 'Student email is not verified.'], 422);
        }

        $attempt = $this->reportExportService->findLatestAttemptForStudent($exam, $room, (int) $student->id);
        if (!$attempt) {
            return response()->json(['message' => 'No attempt found for this student in the selected session.'], 404);
        }

        $pdf = $this->reportExportService->studentReportPdf($attempt, forStudent: true);

        try {
            Mail::to($recipientEmail)->send(new StudentReportMail($pdf['report'], $user, $pdf['binary'], $pdf['filename']));
        } catch (\Throwable $exception) {
            // The mail server's own words go to the error log and alert, not
            // to the teacher's screen.
            report($exception);

            return response()->json([
                'message' => 'The email could not be sent. Try again in a few minutes; if it keeps failing, download the PDF instead.',
            ], 502);
        }

        $this->recordAudit(
            $user,
            'report.email.individual_student_pdf',
            'exam_attempt',
            $attempt->id,
            'Emailed individual student report PDF',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'student_id' => (int) $student->id,
                'recipient_email' => $recipientEmail,
                'verified_only' => $verifiedOnly,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Student report emailed successfully.',
            'student' => [
                'id' => (int) $student->id,
                'name' => $student->name,
                'student_id' => $student->student_id,
                'email' => $recipientEmail,
            ],
            'attempt_id' => (int) $attempt->id,
        ]);
    }

    public function emailStudentReports(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        $validated = $request->validate([
            'verified_only' => ['sometimes', 'boolean'],
        ]);
        $verifiedOnly = (bool) ($validated['verified_only'] ?? false);

        $students = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.email_verified_at', 'users.role')
            ->where('users.role', User::ROLE_STUDENT)
            ->orderBy('users.name')
            ->get();

        $latestAttempts = $this->reportExportService
            ->getLatestSessionAttemptsByStudent($exam, $room)
            ->keyBy('user_id');

        $queued = [];
        $issues = [];

        foreach ($students as $student) {
            $recipientEmail = trim((string) ($student->email ?? ''));

            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'invalid_email',
                ];
                continue;
            }

            // A demo room is dozens of demo addresses; each send would bounce.
            if (DemoData::isDemoEmail($recipientEmail)) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'demo_account',
                ];
                continue;
            }

            if ($verifiedOnly && is_null($student->email_verified_at)) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'email_not_verified',
                ];
                continue;
            }

            /** @var ExamAttempt|null $attempt */
            $attempt = $latestAttempts->get((int) $student->id);
            if (!$attempt) {
                $issues[] = [
                    'student_id' => (int) $student->id,
                    'student_name' => $student->name,
                    'student_number' => $student->student_id,
                    'reason' => 'no_attempt',
                ];
                continue;
            }

            // Built and sent by the queue worker, one student at a time.
            SendStudentReportEmailJob::dispatch((int) $attempt->id, (int) $user->id, $recipientEmail);

            $queued[] = [
                'student_id' => (int) $student->id,
                'student_name' => $student->name,
                'student_number' => $student->student_id,
                'email' => $recipientEmail,
                'attempt_id' => (int) $attempt->id,
            ];
        }

        $summary = [
            'students_total' => (int) $students->count(),
            'queued_count' => (int) count($queued),
            'issue_count' => (int) count($issues),
            'verified_only' => $verifiedOnly,
        ];

        $this->recordAudit(
            $user,
            'report.email.student_reports_bulk',
            'exam',
            $exam->id,
            'Queued student report emails',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'students_total' => (int) $students->count(),
                'queued_count' => (int) count($queued),
                'issue_count' => (int) count($issues),
                'verified_only' => $verifiedOnly,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Student report emails are being sent.',
            'summary' => $summary,
            'queued' => $queued,
            'issues' => $issues,
        ]);
    }

    public function studentReportsZip(
        Request $request,
            Exam $exam,
            Room $room,
    )
    {
        $access = $this->ensureTeacherReportAccess($request, $exam, $room);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        /** @var User $user */
        $user = $access;

        // Each student's latest sitting, the score that counts everywhere else.
        $attempts = $this->reportExportService->getLatestSessionAttemptsByStudent($exam, $room);

        if ($attempts->isEmpty()) {
            return response()->json([
                'message' => 'No student attempts are available for this session.',
            ], 422);
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'blis_zip_');
        if ($zipPath === false) {
            return response()->json(['message' => 'Unable to create ZIP export.'], 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);

            return response()->json(['message' => 'Unable to open ZIP export stream.'], 500);
        }

        foreach ($attempts as $attempt) {
            $pdf = $this->reportExportService->studentReportPdf($attempt);
            $studentId = trim((string) ($pdf['report']['student']['student_id'] ?? ''));
            $entryName = trim(str_replace(['/', '\\'], ' ', (string) ($pdf['report']['student']['name'] ?? 'Student')))
                . ($studentId !== '' ? " ({$studentId})" : '') . '.pdf';

            $zip->addFromString($entryName, $pdf['binary']);
        }

        $zip->close();

        $this->recordAudit(
            $user,
            'report.export.student_reports_zip',
            'exam',
            $exam->id,
            'Exported bulk student reports ZIP',
            [
                'exam_id' => (int) $exam->id,
                'room_id' => (int) $room->id,
                'attempt_count' => (int) $attempts->count(),
            ],
            $request,
        );

        return response()->download($zipPath, $this->sessionFilename($exam, $room, 'Student Reports', 'zip'), [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /** "BLIS 4-A - Cataloging Mock Board Exam - Class Results - 2026-10-20.xlsx" */
    private function sessionFilename(Exam $exam, Room $room, string $kind, string $extension): string
    {
        return ReportOutput::filename([$room->name, $exam->title, $kind], $extension);
    }

    /**
     * The checks every per-exam report makes: a teacher or administrator, who
     * oversees both the exam and the room, and the exam is assigned to that
     * room. Answers the user, or the refusal to send back.
     */
    private function ensureTeacherReportAccess(Request $request, Exam $exam, Room $room): User|JsonResponse
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $exam->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!$this->oversees($user, $room->created_by)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $isAssigned = $exam->rooms()
            ->where('rooms.id', $room->id)
            ->exists();

        if (!$isAssigned) {
            return response()->json([
                'message' => 'This exam is not assigned to the selected room.',
            ], 422);
        }

        return $user;
    }
}
