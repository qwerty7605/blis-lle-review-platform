<?php

namespace App\Services\Library\Docx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use ZipArchive;

/**
 * Reads a Word file into lines: the text of each paragraph, the section it is
 * in, its Word list numbering, and the formatting marks on each stretch of
 * text (highlight, shading, text colour, bold, underline), which is how a
 * teacher marks the correct choice.
 */
class DocxLineReader
{
    use ReadsDocxText;

    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    /**
     * The colours Word's highlighter offers, which it stores by name.
     */
    private const HIGHLIGHT_NAMES = [
        'yellow' => 'FFFF00',
        'green' => '00FF00',
        'cyan' => '00FFFF',
        'magenta' => 'FF00FF',
        'blue' => '0000FF',
        'red' => 'FF0000',
        'darkblue' => '000080',
        'darkcyan' => '008080',
        'darkgreen' => '008000',
        'darkmagenta' => '800080',
        'darkred' => '800000',
        'darkyellow' => '808000',
        'darkgray' => '808080',
        'lightgray' => 'C0C0C0',
        'black' => '000000',
    ];

    /**
     * A coloured background comes in two forms that look the same on screen:
     * the highlighter (w:highlight), and shading (w:shd), which is what Word's
     * Shading button and Google Docs both write. PhpWord reads the first and
     * drops the second, so a shaded answer would go unseen. When a document
     * has shading, a copy is parsed in which shaded text also carries a
     * highlight of the same colour.
     */
    public function loadDocument(string $filePath): object
    {
        $prepared = $this->copyWithShadingAsHighlight($filePath);

        try {
            return IOFactory::load($prepared ?? $filePath);
        } finally {
            if ($prepared !== null && is_file($prepared)) {
                unlink($prepared);
            }
        }
    }
    /**
     * @return string|null path of the rewritten copy, or null when the document
     *                     has no shading (or could not be rewritten) and should
     *                     simply be read as it is
     */
    private function copyWithShadingAsHighlight(string $filePath): ?string
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || !str_contains($xml, '<w:shd')) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::WORD_NAMESPACE);
        $changed = false;

        foreach ($xpath->query('//w:r') as $run) {
            if (!$run instanceof DOMElement) {
                continue;
            }

            // A run's own shading, or failing that the shading of its paragraph.
            $fill = $this->shadingFill($xpath, 'w:rPr/w:shd', $run)
                ?? $this->shadingFill($xpath, 'ancestor::w:p[1]/w:pPr/w:shd', $run);

            if ($fill === null) {
                continue;
            }

            $highlight = $xpath->query('w:rPr/w:highlight', $run)->item(0);
            if ($highlight instanceof DOMElement && strtolower($highlight->getAttributeNS(self::WORD_NAMESPACE, 'val')) !== 'none') {
                continue;
            }

            $properties = $xpath->query('w:rPr', $run)->item(0);
            if (!$properties instanceof DOMElement) {
                $properties = $document->createElementNS(self::WORD_NAMESPACE, 'w:rPr');
                $run->insertBefore($properties, $run->firstChild);
            }

            if ($highlight instanceof DOMElement) {
                $properties->removeChild($highlight);
            }

            $added = $document->createElementNS(self::WORD_NAMESPACE, 'w:highlight');
            $added->setAttributeNS(self::WORD_NAMESPACE, 'w:val', $fill);
            $properties->appendChild($added);
            $changed = true;
        }

        if (!$changed) {
            return null;
        }

        $copy = tempnam(sys_get_temp_dir(), 'docx');
        if ($copy === false) {
            return null;
        }

        $rewritten = new ZipArchive();
        if (!copy($filePath, $copy) || $rewritten->open($copy) !== true) {
            unlink($copy);

            return null;
        }

        $rewritten->addFromString('word/document.xml', (string) $document->saveXML());
        $rewritten->close();

        return $copy;
    }
    /**
     * The fill colour of a w:shd element, when it is a real colour. "auto" and
     * white are what unshaded text carries.
     */
    private function shadingFill(DOMXPath $xpath, string $path, DOMElement $context): ?string
    {
        $shading = $xpath->query($path, $context)->item(0);
        if (!$shading instanceof DOMElement) {
            return null;
        }

        $fill = strtoupper($shading->getAttributeNS(self::WORD_NAMESPACE, 'fill'));

        return preg_match('/^[0-9A-F]{6}$/', $fill) === 1 && $fill !== 'FFFFFF' ? $fill : null;
    }
    /**
     * @return array<int, array{
     *   section_index: int,
     *   text: string,
     *   raw_text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   spans: array<int, array{start: int, end: int, marks: array<int, string>}>
     * }>
     */
    public function extractLines(object $phpWord): array
    {
        $lines = [];

        foreach ($phpWord->getSections() as $sectionIndex => $section) {
            foreach ($section->getElements() as $element) {
                // Handle Table elements (Word often uses invisible tables for multi-column options)
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            foreach ($cell->getElements() as $cellElement) {
                                $line = $this->lineFromElement($cellElement, $sectionIndex);
                                if ($line !== null) {
                                    $lines[] = $line;
                                }
                            }
                        }
                    }
                    continue;
                }

                $line = $this->lineFromElement($element, $sectionIndex);
                if ($line === null) {
                    continue;
                }

                $lines[] = $line;
            }
        }

        return $lines;
    }
    /**
     * @return array{
     *   section_index: int,
     *   text: string,
     *   raw_text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   spans: array<int, array{start: int, end: int, marks: array<int, string>}>
     * }|null
     */
    private function lineFromElement(object $element, int $sectionIndex): ?array
    {
        $segments = $this->collectTextSegments($element);
        if (count($segments) === 0) {
            return null;
        }

        $rawText = implode('', array_column($segments, 'text'));
        $text = $this->normalizeText($rawText);
        if ($text === '') {
            return null;
        }

        $numId = null;
        $depth = null;

        if ($element instanceof ListItemRun) {
            $style = $element->getStyle();
            $resolvedNumId = method_exists($style, 'getNumId') ? $style->getNumId() : null;
            $numId = is_numeric($resolvedNumId) ? (int) $resolvedNumId : null;
            $depth = $element->getDepth();
        }

        return [
            'section_index' => $sectionIndex,
            'text' => $text,
            'raw_text' => trim($rawText),
            'num_id' => $numId,
            'depth' => is_numeric($depth) ? (int) $depth : null,
            'spans' => $this->styledSpans($segments),
        ];
    }
    /**
     * Where each run of formatting sits along a line, measured on the string
     * the option scanners work on: the trimmed raw text with every tab widened
     * to four spaces. That lets a choice that shares its line with another be
     * judged on its own formatting rather than the line's.
     *
     * @param  array<int, array{text: string, marks: array<int, string>}>  $segments
     * @return array<int, array{start: int, end: int, marks: array<int, string>}>
     */
    private function styledSpans(array $segments): array
    {
        $rawText = implode('', array_column($segments, 'text'));
        $trimmedLead = strlen($rawText) - strlen(ltrim($rawText));

        $spans = [];
        $rawPosition = 0;
        $position = 0;

        foreach ($segments as $segment) {
            $length = strlen($segment['text']);
            $from = max($rawPosition, $trimmedLead);

            if ($from < $rawPosition + $length) {
                $width = strlen(str_replace("\t", '    ', substr($segment['text'], $from - $rawPosition)));
                $spans[] = [
                    'start' => $position,
                    'end' => $position + $width,
                    'marks' => $segment['marks'],
                ];
                $position += $width;
            }

            $rawPosition += $length;
        }

        return $spans;
    }
    /**
     * The formatting on a run that a teacher could have used to mark an
     * answer: a highlighter or shading colour, a text colour, bold, underline.
     *
     * @return array<int, string>
     */
    private function marksOfFont(mixed $font): array
    {
        if (!is_object($font)) {
            return [];
        }

        $marks = [];

        $highlight = method_exists($font, 'getFgColor') ? $this->colorFamily($font->getFgColor(), false) : null;
        if ($highlight !== null) {
            $marks[] = 'highlight:' . $highlight;
        }

        $color = method_exists($font, 'getColor') ? $this->colorFamily($font->getColor(), true) : null;
        if ($color !== null) {
            $marks[] = 'color:' . $color;
        }

        if (method_exists($font, 'isBold') && $font->isBold() === true) {
            $marks[] = 'bold';
        }

        $underline = method_exists($font, 'getUnderline') ? $font->getUnderline() : null;
        if (is_string($underline) && $underline !== '' && strtolower($underline) !== 'none') {
            $marks[] = 'underline';
        }

        return $marks;
    }
    /**
     * Name the colour family a text or highlight colour belongs to, or null
     * when it is not a mark at all.
     *
     * Families, not exact values, because a document rarely keeps to one
     * shade: FF0000, EE0000 and C00000 are all "the red the answers are in".
     * For text, black, near-black and grey are ordinary writing -- pasted-in
     * text is full of them -- so only a real colour counts. A highlight is a
     * mark in any colour, grey included; only "none" and white are not.
     */
    private function colorFamily(mixed $value, bool $isText): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value, "# \t\n\r\0\x0B"));
        if ($normalized === '' || in_array($normalized, ['auto', 'none', 'white'], true)) {
            return null;
        }

        $hex = self::HIGHLIGHT_NAMES[$normalized] ?? strtoupper($normalized);
        if (preg_match('/^[0-9A-F]{6}$/', $hex) !== 1) {
            return null;
        }

        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $saturation = $max === $min
            ? 0.0
            : (($max - $min) / 255) / (1 - abs(($max + $min) / 255 - 1));

        if ($saturation < 0.2) {
            if ($isText || $hex === 'FFFFFF') {
                return null;
            }

            return 'gray';
        }

        if ($isText && $max <= 0x50) {
            return null;
        }

        if ($max === $red) {
            $hue = 60 * fmod(($green - $blue) / ($max - $min), 6);
        } elseif ($max === $green) {
            $hue = 60 * (($blue - $red) / ($max - $min) + 2);
        } else {
            $hue = 60 * (($red - $green) / ($max - $min) + 4);
        }

        if ($hue < 0) {
            $hue += 360;
        }

        return match (true) {
            $hue < 20 || $hue >= 345 => 'red',
            $hue < 40 => 'orange',
            $hue < 75 => 'yellow',
            $hue < 165 => 'green',
            $hue < 200 => 'cyan',
            $hue < 260 => 'blue',
            $hue < 290 => 'purple',
            default => 'pink',
        };
    }
    /**
     * @return array<int, array{text: string, marks: array<int, string>}>
     */
    private function collectTextSegments(object $element): array
    {
        if ($element instanceof Text) {
            return [[
                'text' => $this->plainText((string) $element->getText()),
                'marks' => $this->marksOfFont($element->getFontStyle()),
            ]];
        }

        if ($element instanceof TextRun || $element instanceof ListItemRun) {
            $segments = [];
            foreach ($element->getElements() as $child) {
                if (!is_object($child)) {
                    continue;
                }

                foreach ($this->collectTextSegments($child) as $segment) {
                    $segments[] = $segment;
                }
            }

            return $segments;
        }

        $className = get_class($element);
        if (str_ends_with($className, '\\Tab')) {
            return [['text' => "\t", 'marks' => []]];
        }
        if (str_ends_with($className, '\\TextBreak')) {
            return [['text' => "\n", 'marks' => []]];
        }

        if (method_exists($element, 'getText')) {
            return [[
                'text' => $this->plainText((string) $element->getText()),
                'marks' => [],
            ]];
        }

        return [];
    }
    /**
     * PhpWord's reader escapes every piece of text it reads as if it were
     * about to be written into HTML, so an ampersand arrives as "&amp;" and a
     * quotation mark as "&quot;". The questions are stored and shown as plain
     * text, where that escaping shows up literally, so it is undone here. It
     * has to happen before anything measures the text: styledSpans() counts
     * characters.
     */
    private function plainText(string $text): string
    {
        return htmlspecialchars_decode($text, ENT_QUOTES);
    }
}
