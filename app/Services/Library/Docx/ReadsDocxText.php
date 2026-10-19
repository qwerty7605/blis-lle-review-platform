<?php

namespace App\Services\Library\Docx;

use PhpOffice\PhpWord\Element\Text;

/**
 * Small text helpers the Word parser's parts share.
 */
trait ReadsDocxText
{
    protected function normalizeText(string $text): string
    {
        $clean = str_replace(["\r", "\n", "\t"], ' ', $text);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }
    protected function looksLikeHeading(string $text): bool
    {
        if (strlen($text) <= 70 && strtoupper($text) === $text && preg_match('/[A-Z]/', $text)) {
            return true;
        }

        if (preg_match('/^LLE\s+REVIEW/i', $text)) {
            return true;
        }

        return false;
    }
}
