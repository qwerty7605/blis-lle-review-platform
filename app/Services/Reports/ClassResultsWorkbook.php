<?php

namespace App\Services\Reports;

use App\Services\ReportExportService;
use App\Support\PassingMark;
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
        $mark = ReportExportService::markOf($summary['passing'] ?? null);
        $passing = $mark->mark;
        $number = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
        $percent = fn ($value) => $value === null ? '–' : $number($value) . '%';
        $grade = fn ($value) => $value === null ? '–' : $number($mark->grade((float) $value));
        $submitted = (int) ($summary['attempts_submitted'] ?? 0);
        $passedLine = $submitted > 0 ? (($summary['passed_count'] ?? 0) . " of {$submitted} (" . $percent($summary['pass_rate_percent'] ?? null) . ')') : '–';

        $notes = [
            "Scores: one row per student in the room, from their latest attempt (the score that counts everywhere in BLIS). Attempt 2 means the score is from a retake. A student who has not taken the exam is listed as \"Not taken\".",
            "In the question columns: ✓ right, ✗ wrong, – left blank, ? a written answer not marked yet.",
            "Q numbers are the numbers in the Answer Key PDF, so Q5 here is item 5 there, whatever order the student saw the questions in.",
        ];
        if (!empty($session['exam']['shuffle_questions'])) {
            $notes[] = 'This exam shuffles its questions, so each student may get a different selection: an empty cell means that question was not in that student\'s exam.';
        }
        $notes[] = "Questions: each question, how many students got it right out of everyone who was given it (a blank counts as wrong), and its difficulty: Easy when 76% or more got it right, Hard when 25% or fewer did, Average in between.";
        if ($mark->converts()) {
            $staff = PassingMark::STAFF_MARK;
            $notes[] = "Grade: each score on LNU's review scale, where {$staff} is passing. Students are held to a passing mark of {$passing}% (System Settings), so a score of {$passing}% is a grade of {$staff}. Score % is the share of the questions the student got right.";
            $notes[] = "Passed means a grade of {$staff} or more, which is a score of {$passing}% or more. Times are Philippine time.";
        } else {
            $notes[] = "Passed means a score of {$passing}% or more. Times are Philippine time.";
        }

        $facts = [
            ['Exam', $session['exam']['title'] ?? ''],
            ['Subject', $session['exam']['subject'] ?? '–'],
            ['Room', trim(($session['room']['name'] ?? '') . ' (' . ($session['room']['code'] ?? '') . ')')],
            ['Students in the room', (string) ($summary['students_total'] ?? 0)],
            ['Submitted', (string) $submitted],
        ];

        if ($mark->converts()) {
            // Averages are of the scores; a grade is worked out from them last.
            array_push(
                $facts,
                ['Average grade', $grade($summary['average_score_percent'] ?? null) . ' (' . $percent($summary['average_score_percent'] ?? null) . ')'],
                ['Passed (grade ' . PassingMark::STAFF_MARK . ' and above)', $passedLine],
                ['Highest / lowest grade', $grade($summary['highest_score_percent'] ?? null) . ' / ' . $grade($summary['lowest_score_percent'] ?? null)
                    . ' (' . $percent($summary['highest_score_percent'] ?? null) . ' / ' . $percent($summary['lowest_score_percent'] ?? null) . ')'],
            );
        } else {
            array_push(
                $facts,
                ['Average score', $percent($summary['average_score_percent'] ?? null)],
                ["Passed ({$passing}% and above)", $passedLine],
                ['Highest / lowest', $percent($summary['highest_score_percent'] ?? null) . ' / ' . $percent($summary['lowest_score_percent'] ?? null)],
            );
        }

        SheetStyle::about($sheet, 'Class Results', 'Exported from BLIS on ' . ReportOutput::time(now()), $facts, $notes);
    }

    private function scores($sheet, array $session): void
    {
        $sheet->setTitle('Scores');
        $maxItems = (int) ($session['max_items'] ?? 0);
        $mark = ReportExportService::markOf($session['summary']['passing'] ?? null);
        $headers = ReportExportService::resultsHeaders($mark);
        $leading = count($headers);
        // Columns by name: a Grade column follows Score % once the students'
        // mark is above 75.
        $column = array_flip($headers);
        $letter = fn (string $name): string => Coordinate::stringFromColumnIndex($column[$name] + 1);
        array_push($headers, ...ReportExportService::questionHeaders($session));

        $rows = [];
        foreach (($session['rows'] ?? []) as $row) {
            $line = $this->reports->resultsLine($row, $mark);
            $moment = ReportOutput::local($line[$column['Submitted']]);
            $line[$column['Submitted']] = $moment ? ExcelDate::PHPToExcel($moment->format('Y-m-d H:i:s')) : '';
            for ($item = 1; $item <= $maxItems; $item++) {
                $line[] = self::MARKS[$row['item_status_labels'][$item] ?? ''][0] ?? '';
            }
            $rows[] = $line;
        }

        $widthByName = [
            'Student Name' => 30, 'Student ID' => 13, 'Email' => 32, 'Status' => 13, 'Score %' => 10, 'Grade' => 9,
            'Correct' => 10, 'Items' => 8, 'Result' => 15, 'Attempt' => 9, 'Submitted' => 21,
        ];
        $widths = [];
        foreach ($column as $name => $index) {
            $widths[$index + 1] = $widthByName[$name] ?? 10;
        }
        for ($index = $leading + 1; $index <= $leading + $maxItems; $index++) {
            $widths[$index] = 5.5;
        }
        SheetStyle::table($sheet, $headers, $rows, [1, 3], $widths, 2);

        $last = count($rows) + 1;
        if ($rows === []) {
            return;
        }

        $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle($letter('Score %') . "2:" . $letter('Score %') . $last)->getNumberFormat()->setFormatCode('0.00');
        if (isset($column['Grade'])) {
            $sheet->getStyle($letter('Grade') . "2:" . $letter('Grade') . $last)->getNumberFormat()->setFormatCode('0.00');
        }
        $sheet->getStyle($letter('Submitted') . "2:" . $letter('Submitted') . $last)->getNumberFormat()->setFormatCode('mmm d, yyyy h:mm AM/PM');

        foreach ($rows as $index => $line) {
            $r = $index + 2;
            $result = $line[$column['Result']];
            if ($result === 'Passed') {
                SheetStyle::tint($sheet, $letter('Result') . $r, '1E6B34', null, true);
            } elseif ($result !== '') {
                SheetStyle::tint($sheet, $letter('Result') . $r, '8A5A00', null, true);
            }
            if ($line[$column['Status']] !== 'Submitted') {
                SheetStyle::tint($sheet, "A{$r}:" . $letter('Submitted') . $r, SheetStyle::MUTED);
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
