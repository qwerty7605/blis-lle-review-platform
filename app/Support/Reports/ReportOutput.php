<?php

namespace App\Support\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every export shares: the school's clock, readable file names, a CSV
 * that Excel opens with its accents intact, and PDFs that carry only the
 * letters they use (a whole font in every student report made a class's ZIP
 * 65 MB).
 */
class ReportOutput
{
    /** "Oct 20, 2026, 11:44 PM" on the school's clock, or null. */
    public static function time(mixed $moment): ?string
    {
        $local = self::local($moment);

        return $local?->format('M j, Y, g:i A');
    }

    /** "Oct 20, 2026" on the school's clock, or null. */
    public static function date(mixed $moment): ?string
    {
        return self::local($moment)?->format('M j, Y');
    }

    public static function local(mixed $moment): ?CarbonInterface
    {
        if ($moment === null || $moment === '') {
            return null;
        }

        return Carbon::parse($moment)->setTimezone(self::timezone());
    }

    public static function timezone(): string
    {
        return (string) config('app.school_timezone', 'Asia/Manila');
    }

    /**
     * "BLIS 4-A - Cataloging Mock Board Exam - Class Results - 2026-10-20.xlsx".
     * Parts that are empty are left out; characters a file name cannot hold
     * are dropped.
     *
     * @param  array<int, string|null>  $parts
     */
    public static function filename(array $parts, string $extension): string
    {
        $parts[] = Carbon::now(self::timezone())->format('Y-m-d');

        $name = collect($parts)
            ->map(fn ($part) => trim(preg_replace('/\s+/', ' ', str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', (string) $part))))
            ->filter(fn (string $part) => $part !== '')
            ->implode(' - ');

        return Str::limit($name, 150, '') . '.' . $extension;
    }

    /**
     * A CSV download. The byte-order mark is what tells Excel the file is
     * UTF-8; without it "Pacaña" opens as "PacaÃ±a".
     *
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function csv(array $headers, iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);

            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function pdf(string $view, array $data, string $orientation = 'portrait'): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadView($view, $data)
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('a4', $orientation);

        // "Page 2 of 3" in the footer's right corner. Drawn once the pages
        // exist: the PDF library cannot count them from CSS.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $text = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        // Points, not CSS pixels: the 38px page margin is 28.5pt, the footer's
        // 8px type 6pt.
        $width = $dompdf->getFontMetrics()->getTextWidth('Page 1 of 1', $font, 6);
        $canvas->page_text($canvas->get_width() - 28.5 - $width, $canvas->get_height() - 27, $text, $font, 6, [0.37, 0.4, 0.47]);

        return $pdf;
    }

    /** @param  array<string, mixed>  $data */
    public static function pdfDownload(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
    {
        return self::pdf($view, $data, $orientation)->download($filename);
    }
}
