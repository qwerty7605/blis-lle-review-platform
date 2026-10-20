<?php

namespace App\Services\Reports;

use App\Services\ReportExportService;
use App\Support\Reports\ReportOutput;
use App\Support\Reports\SheetStyle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The Class Results Excel file: About (what the file is and how to read it),
 * Scores (a row per student, a mark per question) and Questions (how the
 * class did on each).
 */
class ClassResultsWorkbook
{
    /** A student's mark on a question: what is shown, its text and fill. */
    private const MARKS = [
        'Correct' => ['✓', '1E6B34', 'E7F4EA'],
        'Wrong' => ['✗', 'A3261E', 'FBE9E7'],
        'No Answer' => ['–', SheetStyle::MUTED, 'F1F2F6'],
        'Answered' => ['?', '8A5A00', 'FFF4D6'],
    ];

    public function __construct(private readonly ReportExportService $reports)
    {
    }

    public function build(array $session): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('BLIS')->setTitle('Class Results - ' . ($session['exam']['title'] ?? ''));

        $this->about($book->getActiveSheet(), $session);
        $this->scores($book->createSheet(), $session);
        $this->questions($book->createSheet(), $session);

        $book->setActiveSheetIndex(1);

        return $book;
    }

    private function about($sheet, array $session): void
    {
        $summary = $session['summary'] ?? [];
        $passing = (int) ($summary['passing_score'] ?? ReportExportService::PASSING_SCORE);
        $percent = fn ($value) => $value === null ? '–' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '%';
        $submitted = (int) ($summary['attempts_submitted'] ?? 0);

        $notes = [
            "Scores: one row per student in the room, from their latest attempt (the score that counts everywhere in BLIS). Attempt 2 means the score is from a retake. A student who has not taken the exam is listed as \"Not taken\".",
            "In the question columns: ✓ right, ✗ wrong, – left blank, ? a written answer not marked yet.",
            "Q numbers are the numbers in the Answer Key PDF, so Q5 here is item 5 there, whatever order the student saw the questions in.",
        ];
        if (!empty($session['exam']['shuffle_questions'])) {
            $notes[] = 'This exam shuffles its questions, so each student may get a different selection: an empty cell means that question was not in that student\'s exam.';
        }
        $notes[] = "Questions: each question, how many students got it right out of everyone who was given it (a blank counts as wrong), and its difficulty: Easy when 76% or more got it right, Hard when 25% or fewer did, Average in between.";
        $notes[] = "Passed means a score of {$passing}% or more. Times are Philippine time.";

        SheetStyle::about($sheet, 'Class Results', 'Exported from BLIS on ' . ReportOutput::time(now()), [
            ['Exam', $session['exam']['title'] ?? ''],
            ['Subject', $session['exam']['subject'] ?? '–'],
            ['Room', trim(($session['room']['name'] ?? '') . ' (' . ($session['room']['code'] ?? '') . ')')],
            ['Students in the room', (string) ($summary['students_total'] ?? 0)],
            ['Submitted', (string) $submitted],
            ['Average score', $percent($summary['average_score_percent'] ?? null)],
            ["Passed ({$passing}% and above)", $submitted > 0 ? (($summary['passed_count'] ?? 0) . " of {$submitted} (" . $percent($summary['pass_rate_percent'] ?? null) . ')') : '–'],
            ['Highest / lowest', $percent($summary['highest_score_percent'] ?? null) . ' / ' . $percent($summary['lowest_score_percent'] ?? null)],
        ], $notes);
    }

    private function scores($sheet, array $session): void
    {
        $sheet->setTitle('Scores');
        $maxItems = (int) ($session['max_items'] ?? 0);
        $headers = ReportExportService::resultsHeaders();
        $headers[4] = 'Score %';
        $leading = count($headers);
        for ($item = 1; $item <= $maxItems; $item++) {
            $headers[] = 'Q' . $item;
        }

        $rows = [];
        foreach (($session['rows'] ?? []) as $row) {
            $line = $this->reports->resultsLine($row);
            $moment = ReportOutput::local($line[9]);
            $line[9] = $moment ? ExcelDate::PHPToExcel($moment->format('Y-m-d H:i:s')) : '';
            for ($item = 1; $item <= $maxItems; $item++) {
                $line[] = self::MARKS[$row['item_status_labels'][$item] ?? ''][0] ?? '';
            }
            $rows[] = $line;
        }

        $widths = [1 => 30, 2 => 13, 3 => 32, 4 => 13, 5 => 10, 6 => 10, 7 => 8, 8 => 13, 9 => 9, 10 => 21];
        for ($column = $leading + 1; $column <= $leading + $maxItems; $column++) {
            $widths[$column] = 5.5;
        }
        SheetStyle::table($sheet, $headers, $rows, [1, 3], $widths, 2);

        $last = count($rows) + 1;
        if ($rows === []) {
            return;
        }

        $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle("E2:E{$last}")->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle("J2:J{$last}")->getNumberFormat()->setFormatCode('mmm d, yyyy h:mm AM/PM');

        foreach ($rows as $index => $line) {
            $r = $index + 2;
            if ($line[7] === 'Passed') {
                SheetStyle::tint($sheet, "H{$r}", '1E6B34', null, true);
            } elseif ($line[7] !== '') {
                SheetStyle::tint($sheet, "H{$r}", '8A5A00', null, true);
            }
            if ($line[3] !== 'Submitted') {
                SheetStyle::tint($sheet, "A{$r}:J{$r}", SheetStyle::MUTED);
            }

            for ($item = 1; $item <= $maxItems; $item++) {
                $mark = self::MARKS[$session['rows'][$index]['item_status_labels'][$item] ?? ''] ?? null;
                if ($mark) {
                    $cell = Coordinate::stringFromColumnIndex($leading + $item) . $r;
                    SheetStyle::tint($sheet, $cell, $mark[1], $mark[2], true);
                }
            }
        }
    }

    private function questions($sheet, array $session): void
    {
        $sheet->setTitle('Questions');
        $headers = ['Q', 'Question', 'Correct Answer', 'Got It Right', 'Given To', '% Correct', 'Difficulty', 'Most Chosen Wrong Answer', 'Chosen By'];

        $rows = collect($session['item_summary'] ?? [])->map(fn (array $item) => [
            (int) $item['item_number'],
            (string) ($item['question_text'] ?? ''),
            (string) ($item['correct_answer'] ?? ''),
            (int) $item['correct_count'],
            (int) $item['started_count'],
            $item['correct_percent'] === null ? '' : (float) $item['correct_percent'],
            (string) ($item['difficulty'] ?? ''),
            (string) ($item['common_wrong_answer'] ?? ''),
            $item['common_wrong_answer'] === null ? '' : (int) $item['common_wrong_count'],
        ])->all();

        SheetStyle::table($sheet, $headers, $rows, [2, 3, 8], [1 => 6, 2 => 60, 3 => 32, 4 => 11, 5 => 10, 6 => 11, 7 => 12, 8 => 32, 9 => 10], 1);

        $last = count($rows) + 1;
        if ($rows === []) {
            return;
        }

        $sheet->getStyle("B2:C{$last}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("H2:H{$last}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("F2:F{$last}")->getNumberFormat()->setFormatCode('0.00');

        foreach ($rows as $index => $line) {
            $r = $index + 2;
            match ($line[6]) {
                'Hard' => SheetStyle::tint($sheet, "G{$r}", 'A3261E', 'FBE9E7', true),
                'Easy' => SheetStyle::tint($sheet, "G{$r}", '1E6B34', 'E7F4EA', true),
                default => null,
            };
        }
    }
}
