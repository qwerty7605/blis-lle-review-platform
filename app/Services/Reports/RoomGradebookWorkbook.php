<?php

namespace App\Services\Reports;

use App\Services\ReportExportService;
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

        SheetStyle::about($book->getActiveSheet(), 'Room Gradebook', 'Exported from BLIS on ' . ReportOutput::time(now()), [
            ['Room', trim(($gradebook['room']['name'] ?? '') . ' (' . ($gradebook['room']['code'] ?? '') . ')')],
            ['Students', (string) ($summary['students_total'] ?? 0)],
            ['Exams', (string) ($summary['exams_total'] ?? 0)],
            ['Average of all scores', $summary['average_score_percent'] === null ? '–' : number_format((float) $summary['average_score_percent'], 2) . '%'],
        ], [
            'Gradebook: one row per student currently in the room and one column per exam the room has had, with the student\'s latest score on it.',
            'An empty cell means the student has not submitted that exam. Exams Taken and Average % count only the exams they have submitted.',
            'Scores of ' . (int) ReportExportService::PASSING_SCORE . '% and above are passing.',
        ]);

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

            foreach ($table['rows'] as $index => $row) {
                foreach ($row as $column => $value) {
                    if ($column >= 4 && is_float($value) && $value < ReportExportService::PASSING_SCORE) {
                        SheetStyle::tint($sheet, Coordinate::stringFromColumnIndex($column + 1) . ($index + 2), '8A5A00');
                    }
                }
            }
        }

        $book->setActiveSheetIndex(1);

        return $book;
    }
}
