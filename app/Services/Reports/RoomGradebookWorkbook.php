<?php

namespace App\Services\Reports;

use App\Services\ReportExportService;
use App\Support\PassingMark;
use App\Support\Reports\ReportOutput;
use App\Support\Reports\SheetStyle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * The room's Gradebook Excel file: About, then a row per student with their
 * latest score on every exam the room has had.
 */
class RoomGradebookWorkbook
{
    public function __construct(private readonly ReportExportService $reports)
    {
    }

    public function build(array $gradebook): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('BLIS')->setTitle('Gradebook - ' . ($gradebook['room']['name'] ?? ''));
        $summary = $gradebook['summary'] ?? [];
        $mark = ReportExportService::markOf($gradebook['passing'] ?? null);
        $average = $summary['average_score_percent'] ?? null;
        $staff = PassingMark::STAFF_MARK;

        $facts = [
            ['Room', trim(($gradebook['room']['name'] ?? '') . ' (' . ($gradebook['room']['code'] ?? '') . ')')],
            ['Students', (string) ($summary['students_total'] ?? 0)],
            ['Exams', (string) ($summary['exams_total'] ?? 0)],
            ['Average of all scores', $average === null ? '–' : number_format((float) $average, 2) . '%'],
        ];
        $notes = [
            'Gradebook: one row per student currently in the room and one column per exam the room has had, with the student\'s latest score on it.',
            'An empty cell means the student has not submitted that exam. Exams Taken and Average % count only the exams they have submitted.',
        ];

        if ($mark->converts()) {
            $facts[] = ['Average grade', $average === null ? '–' : number_format((float) $mark->grade((float) $average), 2)];
            $notes[] = "Each score (%) is followed by its grade on LNU's review scale, where {$staff} is passing. Students are held to a passing mark of {$mark->mark}% (System Settings), so a score of {$mark->mark}% is a grade of {$staff}. An average grade is worked out from the average score.";
            $notes[] = "Scores under {$mark->mark}% and grades under {$staff} are shown in amber.";
        } else {
            $notes[] = "Scores of {$mark->mark}% and above are passing.";
        }

        SheetStyle::about($book->getActiveSheet(), 'Room Gradebook', 'Exported from BLIS on ' . ReportOutput::time(now()), $facts, $notes);

        $sheet = $book->createSheet();
        $sheet->setTitle('Gradebook');
        $table = $this->reports->buildRoomGradebookTable($gradebook);
        $columns = count($table['headers']);

        $widths = [1 => 30, 2 => 13, 3 => 32, 4 => 10, 5 => 10];
        for ($column = 6; $column <= $columns; $column++) {
            $widths[$column] = 16;
        }
        SheetStyle::table($sheet, $table['headers'], $table['rows'], [1, 3], $widths, 2);
        // Exam names wrap in their 16-wide columns: tall enough for the
        // longest, at about 13 characters and 15 points a line.
        $longest = max(array_map('mb_strlen', $table['headers']));
        $sheet->getRowDimension(1)->setRowHeight(max(32, 15 * (int) ceil($longest / 13) + 6));

        $last = count($table['rows']) + 1;
        if ($table['rows'] !== []) {
            $lastLetter = Coordinate::stringFromColumnIndex($columns);
            $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle("E2:{$lastLetter}{$last}")->getNumberFormat()->setFormatCode('0.00');

            // Below passing in amber: a score under the students' mark, a
            // grade under 75 (the same students either way).
            $kinds = $table['kinds'] ?? [];
            foreach ($table['rows'] as $index => $row) {
                foreach ($row as $column => $value) {
                    $below = match ($kinds[$column] ?? null) {
                        'score' => is_float($value) && !$mark->passes($value),
                        'grade' => is_float($value) && $value < $staff,
                        default => false,
                    };
                    if ($below) {
                        SheetStyle::tint($sheet, Coordinate::stringFromColumnIndex($column + 1) . ($index + 2), '8A5A00');
                    }
                }
            }
        }

        $book->setActiveSheetIndex(1);

        return $book;
    }
}
