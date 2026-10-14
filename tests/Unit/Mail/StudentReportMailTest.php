<?php

namespace Tests\Unit\Mail;

use App\Mail\StudentReportMail;
use App\Models\User;
use Tests\TestCase;

/**
 * The email that carries one student's exam report: its subject line, the
 * teacher named in it, and the PDF attached.
 */
class StudentReportMailTest extends TestCase
{
    private function mail(array $report): StudentReportMail
    {
        $teacher = new User(['name' => 'Ms. Reyes', 'email' => 'reyes@lnu.edu.ph']);
        $teacher->id = 7;

        return new StudentReportMail($report, $teacher, '%PDF-1.4 fake', 'report.pdf');
    }

    private function report(array $exam = [], array $room = []): array
    {
        return [
            'exam' => ['title' => 'Reference Quiz', ...$exam],
            'room' => ['code' => 'RPT001', ...$room],
            'student' => ['name' => 'Ana Cruz', 'student_id' => '2300001'],
            'attempt' => ['score_percent' => 80, 'correct_answers' => 8, 'total_items' => 10, 'submitted_at' => now()],
            'items' => [],
        ];
    }

    public function test_the_subject_names_the_exam_and_room(): void
    {
        $this->assertSame('Your Exam Report: Reference Quiz (RPT001)', $this->mail($this->report())->envelope()->subject);
    }

    public function test_the_subject_leaves_out_a_missing_room_code_and_title(): void
    {
        $this->assertSame('Your Exam Report: Reference Quiz', $this->mail($this->report([], ['code' => '']))->envelope()->subject);
        $this->assertSame('Your Exam Report', $this->mail($this->report(['title' => '']))->envelope()->subject);
    }

    public function test_the_body_renders_with_the_teacher_and_the_pdf_is_attached(): void
    {
        $mail = $this->mail($this->report());

        $this->assertSame(7, $mail->content()->with['teacher']['id']);
        $this->assertStringContainsString('Reference Quiz', $mail->render());

        $attachments = $mail->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('report.pdf', $attachments[0]->as);
        $this->assertSame('application/pdf', $attachments[0]->mime);
    }
}
