<?php

namespace App\Support\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The one look every exported sheet has. One alignment rule: names and other
 * text on the left, every number, mark and date centred, the heading over a
 * column aligned as the column is. Headings are navy with white text, and
 * stay in view while scrolling.
 */
class SheetStyle
{
    public const NAVY = '1A237E';
    public const LINE = 'D7DBE7';
    public const MUTED = '5F6577';

    /**
     * Writes a table from A1: a heading row, then the rows.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $textColumns  1-based columns aligned left
     * @param  array<int, float>  $widths  1-based column => width
     */
    public static function table(Worksheet $sheet, array $headers, array $rows, array $textColumns, array $widths, int $freezeColumns = 1): void
    {
        $sheet->fromArray($headers, null, 'A1', true);
        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2', true);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $lastRow = max(1, count($rows) + 1);

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::LINE]]],
        ]);

        foreach ($textColumns as $column) {
            $letter = Coordinate::stringFromColumnIndex($column);
            $sheet->getStyle("{$letter}1:{$letter}{$lastRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setIndent(1);
        }

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
            'alignment' => ['wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(32);

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($width);
        }

        $sheet->freezePane(Coordinate::stringFromColumnIndex($freezeColumns + 1) . '2');
        self::print($sheet, count($headers) > 8);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        if ($rows !== []) {
            $sheet->setAutoFilter("A1:{$lastColumn}{$lastRow}");
        }
    }

    /**
     * An "About" sheet: a title, label/value lines, then notes on how to read
     * the file.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $facts
     * @param  array<int, string>  $notes
     */
    public static function about(Worksheet $sheet, string $title, string $subtitle, array $facts, array $notes): void
    {
        $sheet->setTitle('About');
        $sheet->setShowGridlines(false);
        self::print($sheet, false);
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(70);

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB(self::NAVY);
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB(self::MUTED);

        $row = 4;
        foreach ($facts as [$label, $value]) {
            $sheet->setCellValueExplicit("A{$row}", $label, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$row}", (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $row++;
        }

        $row++;
        $sheet->setCellValue("A{$row}", 'How to read this file');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB(self::NAVY);
        $row++;

        foreach ($notes as $note) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", $note);
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getRowDimension($row)->setRowHeight(15 * max(1, (int) ceil(mb_strlen($note) / 95)));
            $row++;
        }
    }

    /** Printed: A4, as wide as the paper, as many pages down as it takes. */
    public static function print(Worksheet $sheet, bool $landscape): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $sheet->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
    }

    /** Colours a range's text, and optionally its fill. */
    public static function tint(Worksheet $sheet, string $range, string $font, ?string $fill = null, bool $bold = false): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold($bold)->getColor()->setRGB($font);
        if ($fill !== null) {
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        }
    }
}
