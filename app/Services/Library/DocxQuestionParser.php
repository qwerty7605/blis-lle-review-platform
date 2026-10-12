<?php

namespace App\Services\Library;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use Throwable;
use ZipArchive;

class DocxQuestionParser
{
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
     * Bold and underline are everyday emphasis, so on their own they say
     * little. They count as the answer mark only when the document uses them
     * that way on a good share of its questions.
     */
    private const EMPHASIS_MARKS = ['bold', 'underline'];

    /**
     * A typed choice letter: A to H standing on its own, then a full stop or a
     * closing bracket, or a colon or hyphen with a space after it. "E-mail"
     * and "C:drive" are words, not choices E and C.
     */
    private const CHOICE_LABEL_PATTERN = '/(?:^|(?<=\s))([A-H])([\).]|[:\-](?=\s))\s*/iu';

    /**
     * Parse a DOCX document into structured questions.
     *
     * @return array{
     *   questions: array<int, array{
     *     item_number: int,
     *     text: string,
     *     question_type: string,
     *     options: array<int, array{label: string, text: string, is_correct: bool}>,
     *     answer_label: ?string,
     *     answer_text: ?string
     *   }>,
     *   warnings: array<int, string>,
     *   answer_key_detected: bool,
     *   answer_key_items: int
     * }
     */
    public function parse(string $filePath): array
    {
        try {
            $phpWord = $this->loadDocument($filePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to read DOCX document.', previous: $exception);
        }

        $lines = $this->extractLines($phpWord);
        [$answerKeySection, $answerKeyLetters] = $this->detectAnswerKeySection($lines);

        $contentLines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => $answerKeySection === null || $line['section_index'] !== $answerKeySection
        ));

        // The key by question number. A key in a section of its own lists the
        // answers in order; one typed at the end of the questions is read by
        // takeTrailingAnswerKey(), which also takes its lines out of the way.
        $answerKey = [];
        foreach ($answerKeyLetters as $index => $letter) {
            $answerKey[$index + 1] = $letter;
        }

        [$contentLines, $trailingKey] = $this->takeTrailingAnswerKey($contentLines);
        $answerKey += $trailingKey;

        $dominantQuestionNumId = $this->resolveDominantQuestionNumId($contentLines);
        $warnings = [];

        $questions = $this->buildQuestions($contentLines, $dominantQuestionNumId, $warnings);
        $this->resolveMarkedAnswers($questions, $warnings);
        $this->applyDetectedAnswers($questions, $answerKey, $warnings);

        return [
            'questions' => $questions,
            'warnings' => array_values(array_unique($warnings)),
            'answer_key_detected' => $answerKey !== [],
            'answer_key_items' => count($answerKey),
        ];
    }

    /**
     * A coloured background comes in two forms that look the same on screen:
     * the highlighter (w:highlight), and shading (w:shd), which is what Word's
     * Shading button and Google Docs both write. PhpWord reads the first and
     * drops the second, so a shaded answer would go unseen. When a document
     * has shading, a copy is parsed in which shaded text also carries a
     * highlight of the same colour.
     */
    private function loadDocument(string $filePath): object
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
    private function extractLines(object $phpWord): array
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
     * Which marks a choice carries, given the stretch of its line it occupies.
     *
     * A mark counts when it covers at least half of the choice's wording, or,
     * where the letter is typed, the letter itself -- some teachers mark only
     * the "B." A single emphasised word inside a longer choice is neither.
     *
     * The typed letter is left out of the half: in "c. 5" it is most of the
     * visible text, and marking the 5 alone has to be enough.
     *
     * @param  array{raw_text: string, spans: array<int, array{start: int, end: int, marks: array<int, string>}>}  $line
     * @param  int|null  $textStart  where the wording begins after a typed letter; null when Word supplies the letter
     * @return array<int, string>
     */
    private function marksInRange(array $line, int $start = 0, ?int $end = null, ?int $textStart = null): array
    {
        $scanned = str_replace("\t", '    ', $line['raw_text']);
        $end ??= strlen($scanned);

        $wording = $this->markCoverage($line['spans'], $scanned, $textStart ?? $start, $end);
        $marks = [];

        foreach ($wording['covered'] as $mark => $count) {
            if ($count * 2 >= $wording['visible']) {
                $marks[] = $mark;
            }
        }

        if ($textStart !== null) {
            $letter = $this->markCoverage($line['spans'], $scanned, $start, $textStart);
            $marks = array_values(array_unique([...$marks, ...$letter['leading']]));
        }

        return $marks;
    }

    /**
     * @param  array<int, array{start: int, end: int, marks: array<int, string>}>  $spans
     * @return array{visible: int, covered: array<string, int>, leading: array<int, string>}
     *         visible characters in the stretch, how many of them carry each
     *         mark, and the marks on the first of them
     */
    private function markCoverage(array $spans, string $scanned, int $start, int $end): array
    {
        $visible = 0;
        $covered = [];
        $leading = null;

        foreach ($spans as $span) {
            $from = max($start, $span['start']);
            $to = min($end, $span['end']);

            if ($from >= $to) {
                continue;
            }

            $count = strlen((string) preg_replace('/\s+/', '', substr($scanned, $from, $to - $from)));
            if ($count === 0) {
                continue;
            }

            $leading ??= $span['marks'];
            $visible += $count;

            foreach ($span['marks'] as $mark) {
                $covered[$mark] = ($covered[$mark] ?? 0) + $count;
            }
        }

        return ['visible' => $visible, 'covered' => $covered, 'leading' => $leading ?? []];
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

    private function normalizeText(string $text): string
    {
        $clean = str_replace(["\r", "\n", "\t"], ' ', $text);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * @param  array<int, array{section_index: int, text: string}>  $lines
     * @return array{0: ?int, 1: array<int, string>}
     */
    private function detectAnswerKeySection(array $lines): array
    {
        $stats = [];
        $lettersBySection = [];

        foreach ($lines as $line) {
            $sectionIndex = $line['section_index'];
            $stats[$sectionIndex]['total'] = ($stats[$sectionIndex]['total'] ?? 0) + 1;

            if (!preg_match('/^[A-H]$/i', $line['text'])) {
                continue;
            }

            $stats[$sectionIndex]['letter_total'] = ($stats[$sectionIndex]['letter_total'] ?? 0) + 1;
            $lettersBySection[$sectionIndex][] = strtoupper($line['text']);
        }

        $pickedSection = null;
        $pickedScore = 0.0;

        foreach ($stats as $sectionIndex => $sectionStats) {
            $total = (int) ($sectionStats['total'] ?? 0);
            $letterTotal = (int) ($sectionStats['letter_total'] ?? 0);

            if ($total < 5 || $letterTotal < 5) {
                continue;
            }

            $ratio = $total === 0 ? 0.0 : $letterTotal / $total;
            if ($ratio < 0.8) {
                continue;
            }

            $score = ($ratio * 1000) + $letterTotal;
            if ($score <= $pickedScore) {
                continue;
            }

            $pickedSection = (int) $sectionIndex;
            $pickedScore = $score;
        }

        return [$pickedSection, $pickedSection === null ? [] : ($lettersBySection[$pickedSection] ?? [])];
    }

    /**
     * An answer key typed after the last question, with no section of its own:
     * lines made of nothing but number-and-letter pairs ("1. d   26. c   51. b"),
     * or a run of lines holding one letter each. Read as questions these became
     * dozens of invented items with no choices, and their answers were lost.
     *
     * Only the very end of the document is looked at, so a question elsewhere
     * can never be taken for part of a key.
     *
     * @param  array<int, array{text: string}>  $lines
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}  the lines without the key, and the key by question number
     */
    private function takeTrailingAnswerKey(array $lines): array
    {
        $numbered = [];
        $letters = [];
        $cut = count($lines);
        $closingLines = 0;

        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $text = $lines[$index]['text'];

            if (preg_match('/^[A-H]$/i', $text)) {
                $letters[] = strtoupper($text);
                $cut = $index;
                continue;
            }

            $pairs = $this->answerKeyPairs($text);
            if ($pairs !== null) {
                $numbered = $pairs + $numbered;
                $cut = $index;
                continue;
            }

            if (preg_match('/^(?:answers?(?:\s+keys?)?|key\s+to\s+corrections?)\s*:?$/i', $text)) {
                $cut = $index;
                continue;
            }

            // A sign-off under the key ("GOOD LUCK!") does not end the search.
            if ($numbered === [] && $letters === [] && $closingLines < 2 && $this->looksLikeHeading($text)) {
                $closingLines++;
                continue;
            }

            break;
        }

        if (count($numbered) >= 3) {
            return [array_slice($lines, 0, $cut), $numbered];
        }

        if ($numbered === [] && count($letters) >= 5) {
            $key = [];
            foreach (array_reverse($letters) as $position => $letter) {
                $key[$position + 1] = $letter;
            }

            return [array_slice($lines, 0, $cut), $key];
        }

        return [$lines, []];
    }

    /**
     * @return array<int, string>|null  the letters by question number, or null when the line is anything other than key entries
     */
    private function answerKeyPairs(string $text): ?array
    {
        if (!preg_match('/^(?:\(?\d{1,3}\)?\s*[.):\-]?\s*[A-H][.)]?(?:\s+|$))+$/i', $text)) {
            return null;
        }

        preg_match_all('/(\d{1,3})\)?\s*[.):\-]?\s*([A-H])[.)]?(?=\s|$)/i', $text, $matches, PREG_SET_ORDER);

        $pairs = [];
        foreach ($matches as $match) {
            $pairs[(int) $match[1]] = strtoupper($match[2]);
        }

        return $pairs === [] ? null : $pairs;
    }

    /**
     * @param  array<int, array{text: string, num_id: ?int, depth: ?int}>  $lines
     */
    private function resolveDominantQuestionNumId(array $lines): ?int
    {
        $counts = [];

        foreach ($lines as $line) {
            if ($line['num_id'] === null || $line['depth'] !== 0) {
                continue;
            }

            if (preg_match('/^[A-H]$/i', $line['text'])) {
                continue;
            }

            $counts[$line['num_id']] = ($counts[$line['num_id']] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * Each choice comes back with the marks found on it (see marksInRange());
     * which of them is the answer is settled afterwards, across the whole
     * document, by resolveMarkedAnswers().
     *
     * @param  array<int, array{
     *   section_index: int,
     *   text: string,
     *   raw_text: string,
     *   num_id: ?int,
     *   depth: ?int,
     *   spans: array<int, array{start: int, end: int, marks: array<int, string>}>
     * }>  $lines
     * @return array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, marks: array<int, string>}>
     * }>
     */
    private function buildQuestions(array $lines, ?int $dominantQuestionNumId, array &$warnings): array
    {
        $questions = [];
        $current = null;

        // Whether the document numbers its questions, by Word's numbering or
        // by typing "12." in front of each. Where it does, a question begins
        // at a number and nowhere else; the guesses further down ("this line
        // is long", "this line ends in a question mark") are only for
        // documents with no numbering to go by. Guessing in a numbered
        // document is what turned an instruction between two questions, or a
        // note above the first, into a question of its own -- and every
        // question after an invented one carried the wrong number.
        $typedNumbers = 0;
        foreach ($lines as $line) {
            if ($this->stripQuestionPrefix($line['text']) !== null) {
                $typedNumbers++;
            }
        }
        $numbered = $dominantQuestionNumId !== null || $typedNumbers >= 3;

        $newQuestion = static fn (string $text): array => [
            'text' => $text,
            'options' => [],
            'has_labeled_options' => false,
            'pending_structural' => [],
            'after_pending' => [],
            'list_counts' => [],
        ];

        $leaveOut = function (string $text, int $questionNumber) use (&$warnings): void {
            // A heading or a sign-off needs no report.
            if ($this->looksLikeHeading($text)) {
                return;
            }

            $warnings[] = sprintf(
                'A line after question %d is not part of any question and was left out: "%s"',
                $questionNumber,
                mb_strlen($text) > 90 ? mb_substr($text, 0, 90) . '...' : $text,
            );
        };

        $commit = function () use (&$current, &$questions, $leaveOut): void {
            if ($current === null) {
                return;
            }

            $current['text'] = $this->normalizeText($current['text']);
            if ($current['text'] === '') {
                $current = null;
                return;
            }

            // Lines that came after a list and were never followed by
            // choices: the list was the choices, and these trail the question.
            foreach ($current['after_pending'] as $trailingText) {
                $leaveOut($trailingText, count($questions) + 1);
            }

            if (count($current['pending_structural']) > 0) {
                if ($current['has_labeled_options']) {
                    $current['text'] = $this->appendStructuralLinesToQuestionText(
                        $current['text'],
                        $current['pending_structural']
                    );
                } else {
                    foreach ($current['pending_structural'] as $pendingLine) {
                        $current['options'][] = [
                            'label' => '',
                            'text' => $pendingLine['text'],
                            'marks' => $pendingLine['marks'],
                        ];
                    }
                }
            }

            $this->normalizeUnlabeledMixedOptions($current);

            $current['options'] = array_values(array_filter(
                $current['options'],
                fn (array $option): bool => $this->normalizeText($option['text']) !== ''
            ));

            $existingLabels = [];
            foreach ($current['options'] as $index => $option) {
                $label = strtoupper(trim($option['label']));
                if ($label === '' || isset($existingLabels[$label])) {
                    $label = $this->resolveNextOptionLabel($current['options'], $index);
                }

                $current['options'][$index]['label'] = $label;
                $current['options'][$index]['text'] = $this->normalizeText($option['text']);
                $existingLabels[$label] = true;
            }

            usort(
                $current['options'],
                fn (array $left, array $right): int => $this->compareOptionLabels(
                    (string) ($left['label'] ?? ''),
                    (string) ($right['label'] ?? ''),
                )
            );

            unset(
                $current['has_labeled_options'],
                $current['pending_structural'],
                $current['after_pending'],
                $current['list_counts'],
            );
            $questions[] = $current;
            $current = null;
        };

        foreach ($lines as $line) {
            $text = $line['text'];
            $rawText = $line['raw_text'] ?? $text;

            if ($text === '') {
                continue;
            }

            if ($this->isIgnorableHeader($text)) {
                continue;
            }

            if ($current === null && $this->looksLikeHeading($text)) {
                continue;
            }

            $strippedQuestion = $this->stripQuestionPrefix($text);

            $isQuestionAnchor = $dominantQuestionNumId !== null
                && $line['num_id'] !== null
                && $line['num_id'] === $dominantQuestionNumId
                && $line['depth'] === 0;

            if ($isQuestionAnchor) {
                $commit();

                // Word numbers this line itself, so a number typed at its
                // start is part of the sentence ("3-D printing is"), unless it
                // only repeats the number Word is already showing.
                $repeatsOwnNumber = $strippedQuestion !== null
                    && $this->typedQuestionNumber($text) === count($questions) + 1;

                $current = $newQuestion($this->normalizeText($repeatsOwnNumber ? $strippedQuestion : $text));
                continue;
            }

            if ($strippedQuestion !== null) {
                $commit();
                $current = $newQuestion($this->normalizeText($strippedQuestion));
                continue;
            }

            if ($current === null) {
                if ($numbered || !$this->looksLikeQuestionText($text)) {
                    continue;
                }

                $current = $newQuestion($this->normalizeText($text));
                continue;
            }

            $isStructuralOption = ($line['depth'] !== null && $line['depth'] > 0)
                || ($dominantQuestionNumId !== null && $line['num_id'] !== null && $line['num_id'] !== $dominantQuestionNumId);

            $optionsWithLabels = $this->extractOptionsWithLabels($rawText);
            $leadIsWording = false;

            if ($isStructuralOption) {
                $listKey = ($line['num_id'] ?? '-') . ':' . ($line['depth'] ?? '-');
                $positionInList = $current['list_counts'][$listKey] ?? 0;
                $current['list_counts'][$listKey] = $positionInList + 1;

                // Word letters this line itself. A capital and a full stop at
                // its start is therefore usually wording -- "H. Wheatley" as
                // the fourth choice -- and is a typed label only when it
                // repeats the letter Word gives the line.
                if ($optionsWithLabels !== null && $optionsWithLabels[0]['label'] !== chr(65 + $positionInList)) {
                    $optionsWithLabels = null;
                    $leadIsWording = true;
                }
            }

            if ($optionsWithLabels !== null) {
                $this->settlePendingLines($current, $isStructuralOption ? $line : null);

                $current['has_labeled_options'] = true;
                foreach ($optionsWithLabels as $extractedOption) {
                    $current['options'][] = [
                        'label' => $extractedOption['label'],
                        'text' => $extractedOption['text'],
                        'marks' => $this->marksInRange(
                            $line,
                            $extractedOption['start'],
                            $extractedOption['end'],
                            $extractedOption['text_start'],
                        ),
                    ];
                }
                continue;
            }

            if ($isStructuralOption) {
                // Check for embedded secondary option after tabs in raw text.
                // Word often uses list numbering for A./B. labels (not in text),
                // while C./D. appear inline after tab characters.
                // e.g. raw: "Analytic Engine\t\t\tc. Punch Cards"
                $splitOptions = $this->splitStructuralLineWithEmbeddedLabel($rawText, $leadIsWording);

                if ($splitOptions !== null) {
                    $this->settlePendingLines($current, $line);

                    // We found an embedded label — produce two options from this one line
                    foreach ($splitOptions as $splitOption) {
                        $current['options'][] = [
                            'label' => $splitOption['label'],
                            'text' => $splitOption['text'],
                            'marks' => $this->marksInRange(
                                $line,
                                $splitOption['start'],
                                $splitOption['end'],
                                $splitOption['text_start'],
                            ),
                        ];
                    }
                    $current['has_labeled_options'] = true;
                    continue;
                }

                // A list, more of the question, and now another list: the
                // first list and the words after it belong to the question.
                if (count($current['after_pending']) > 0) {
                    $this->settlePendingLines($current, null);
                }

                if (!$current['has_labeled_options'] && count($current['options']) === 0) {
                    if ($this->shouldStartStructuralOptions($current['pending_structural'], $line)) {
                        $current['text'] = $this->appendStructuralLinesToQuestionText(
                            $current['text'],
                            $current['pending_structural']
                        );
                        $current['pending_structural'] = [];
                        $current['options'][] = [
                            'label' => '',
                            'text' => $text,
                            'marks' => $this->marksInRange($line),
                        ];
                        continue;
                    }

                    $current['pending_structural'][] = [
                        'text' => $text,
                        'marks' => $this->marksInRange($line),
                        'num_id' => $line['num_id'],
                        'depth' => $line['depth'],
                    ];
                    continue;
                }

                $current['options'][] = [
                    'label' => '',
                    'text' => $text,
                    'marks' => $this->marksInRange($line),
                ];
                continue;
            }

            if ($numbered) {
                // After the choices, a line with no number and no letter
                // belongs to no question: "Good luck!", an instruction for the
                // next part. It used to be glued onto the last choice.
                if (count($current['options']) > 0) {
                    $leaveOut($text, count($questions) + 1);
                    continue;
                }

                // After a list it may be more of the question (a list of
                // statements, then "Which of these is true?") or a stray line
                // under a list of choices. What comes next settles it.
                if (count($current['pending_structural']) > 0) {
                    $current['after_pending'][] = $text;
                    continue;
                }

                $current['text'] = $this->normalizeText($current['text'] . ' ' . $text);
                continue;
            }

            if ($this->looksLikeQuestionText($text) && count($current['options']) >= 2) {
                $commit();
                $current = $newQuestion($this->normalizeText($text));
                continue;
            }

            if (count($current['options']) > 0 && !$this->looksLikeQuestionText($text)) {
                // Carried-over text adds to the choice's wording but not to
                // its marks: a bold "Good luck!" under the last question must
                // not make that question's last choice the marked one.
                $lastOptionIndex = count($current['options']) - 1;
                $current['options'][$lastOptionIndex]['text'] = $this->normalizeText(
                    $current['options'][$lastOptionIndex]['text'] . ' ' . $text
                );
                continue;
            }

            $current['text'] = $this->normalizeText($current['text'] . ' ' . $text);
        }

        $commit();

        return $questions;
    }

    /**
     * List lines are held back until it is clear what they are. This is called
     * when choices with letters turn up, which makes it clear.
     *
     * Held lines from the same Word list as the line that has just arrived are
     * the earlier choices of that list ("Full stop (.)", then "Slash (/)
     * <tab> D. Semicolon"); they used to be folded into the question's text.
     * Held lines from another list, or with more of the question written
     * after them, are part of the question: numbered statements to choose
     * between.
     *
     * @param  array<string, mixed>  $current
     * @param  array{num_id: ?int, depth: ?int}|null  $line  the list line that has just arrived, if it is one
     */
    private function settlePendingLines(array &$current, ?array $line): void
    {
        $pending = $current['pending_structural'];
        $trailing = $current['after_pending'];

        $current['pending_structural'] = [];
        $current['after_pending'] = [];

        if ($pending === []) {
            return;
        }

        $sameList = $trailing === []
            && $line !== null
            && $pending[0]['num_id'] === $line['num_id']
            && $pending[0]['depth'] === $line['depth'];

        if ($sameList) {
            foreach ($pending as $pendingLine) {
                $current['options'][] = [
                    'label' => '',
                    'text' => $pendingLine['text'],
                    'marks' => $pendingLine['marks'],
                ];
            }

            return;
        }

        $current['text'] = $this->appendStructuralLinesToQuestionText($current['text'], $pending);

        if ($trailing !== []) {
            $current['text'] = $this->normalizeText($current['text'] . ' ' . implode(' ', $trailing));
        }
    }

    /**
     * @param  array<int, array{text: string}>  $lines
     */
    private function appendStructuralLinesToQuestionText(string $questionText, array $lines): string
    {
        $parts = [];

        foreach ($lines as $index => $line) {
            $clean = $this->normalizeText($line['text'] ?? '');
            if ($clean === '') {
                continue;
            }

            $clean = preg_replace('/^\(?\d+\)?[.)]\s*/u', '', $clean) ?? $clean;
            $clean = preg_replace('/^[A-H][.)]\s*/iu', '', $clean) ?? $clean;
            $clean = $this->normalizeText($clean);
            if ($clean === '') {
                continue;
            }

            $parts[] = sprintf('%d. %s', $index + 1, $clean);
        }

        if ($parts === []) {
            return $this->normalizeText($questionText);
        }

        return $this->normalizeText(trim($questionText . ' ' . implode(' ', $parts)));
    }

    /**
     * Decide if a new structural line should start option parsing after accumulating
     * statement-like structural lines.
     *
     * @param  array<int, array{text: string, num_id: ?int, depth: ?int}>  $pending
     * @param  array{text: string, num_id: ?int, depth: ?int}  $line
     */
    private function shouldStartStructuralOptions(array $pending, array $line): bool
    {
        if (count($pending) < 2) {
            return false;
        }

        $first = $pending[0];

        if (
            $first['num_id'] !== null
            && $line['num_id'] !== null
            && (int) $first['num_id'] !== (int) $line['num_id']
        ) {
            return true;
        }

        if (
            $first['depth'] !== null
            && $line['depth'] !== null
            && (int) $first['depth'] !== (int) $line['depth']
        ) {
            return true;
        }

        $statementLikeCount = 0;
        foreach ($pending as $pendingLine) {
            if ($this->looksLikeStatementLine($pendingLine['text'] ?? '')) {
                $statementLikeCount++;
            }
        }

        if ($statementLikeCount < 2) {
            return false;
        }

        return $this->looksLikeCompactChoice($line['text'] ?? '');
    }

    /**
     * If structural parsing produced unlabeled mixed content (statement list + compact choices),
     * move statement-like prefix lines back into question text.
     *
     * @param  array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, marks: array<int, string>}>,
     *   has_labeled_options: bool,
     *   pending_structural: array<int, array{text: string, marks: array<int, string>, num_id: ?int, depth: ?int}>
     * }  $current
     */
    private function normalizeUnlabeledMixedOptions(array &$current): void
    {
        if ($current['has_labeled_options']) {
            return;
        }

        if (count($current['options']) < 5) {
            return;
        }

        foreach ($current['options'] as $option) {
            if (trim((string) ($option['label'] ?? '')) !== '') {
                return;
            }
        }

        $splitIndex = null;
        $optionCount = count($current['options']);

        for ($index = 2; $index <= $optionCount - 2; $index++) {
            $prefix = array_slice($current['options'], 0, $index);
            $suffix = array_slice($current['options'], $index);

            $suffixAllCompact = true;
            foreach ($suffix as $option) {
                if (!$this->looksLikeCompactChoice((string) ($option['text'] ?? ''))) {
                    $suffixAllCompact = false;
                    break;
                }
            }

            if (!$suffixAllCompact) {
                continue;
            }

            $prefixStatementLike = 0;
            foreach ($prefix as $option) {
                if ($this->looksLikeStatementLine((string) ($option['text'] ?? ''))) {
                    $prefixStatementLike++;
                }
            }

            if ($prefixStatementLike < 2) {
                continue;
            }

            if ($prefixStatementLike / max(1, count($prefix)) < 0.6) {
                continue;
            }

            $splitIndex = $index;
            break;
        }

        if ($splitIndex === null) {
            return;
        }

        $prefix = array_slice($current['options'], 0, $splitIndex);
        $suffix = array_slice($current['options'], $splitIndex);

        $structuralPrefix = array_map(
            static fn (array $option): array => ['text' => $option['text']],
            $prefix
        );

        $current['text'] = $this->appendStructuralLinesToQuestionText($current['text'], $structuralPrefix);
        $current['options'] = array_values($suffix);
    }

    private function looksLikeCompactChoice(string $text): bool
    {
        $clean = $this->normalizeText($text);
        if ($clean === '') {
            return false;
        }

        if (strlen($clean) > 24) {
            return false;
        }

        if (preg_match('/^[0-9][0-9\s\/,\.-]{1,}$/u', $clean)) {
            return true;
        }

        if (preg_match('/^[A-H0-9][A-H0-9\s\/,\.-]{1,}$/iu', $clean) && substr_count($clean, ' ') <= 2) {
            return true;
        }

        return false;
    }

    private function looksLikeStatementLine(string $text): bool
    {
        $clean = $this->normalizeText($text);
        if ($clean === '') {
            return false;
        }

        if (!preg_match('/[A-Za-z]/u', $clean)) {
            return false;
        }

        return strlen($clean) >= 6 && substr_count($clean, ' ') >= 1;
    }

    private function looksLikeHeading(string $text): bool
    {
        if (strlen($text) <= 70 && strtoupper($text) === $text && preg_match('/[A-Z]/', $text)) {
            return true;
        }

        if (preg_match('/^LLE\s+REVIEW/i', $text)) {
            return true;
        }

        return false;
    }

    private function stripQuestionPrefix(string $text): ?string
    {
        // "198.70.185.3 is an example of" and "1990-1995 saw" open with a
        // number that is part of the sentence, not a question number: there
        // the separator runs straight into another digit.
        if (!preg_match('/^(?:Q(?:uestion)?\s*)?\d+[\).\:-](?!\d)\s*(.+)$/iu', $text, $matches)) {
            return null;
        }

        return $this->normalizeText($matches[1] ?? '');
    }

    private function typedQuestionNumber(string $text): ?int
    {
        return preg_match('/^(?:Q(?:uestion)?\s*)?(\d+)/iu', $text, $matches) ? (int) $matches[1] : null;
    }

    private function isIgnorableHeader(string $text): bool
    {
        // Ignore lines like "1. Name ____ Direction: Choose the best answer..."
        // Or "Directions: Choose the best..."
        if (preg_match('/^(?:\d+[\).\:-]\s*)?(?:Name|Date|Score|Section|Direction)s?\b\s*[_:\-]/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * `start` and `end` say where each choice sits, letter included, and
     * `text_start` where its wording begins, on the trimmed text with tabs
     * widened -- the positions styledSpans() uses.
     *
     * @return array<int, array{label: string, text: string, start: int, text_start: int, end: int}>|null
     */
    private function extractOptionsWithLabels(string $rawText): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        // Tabs are widened so that a tab and a run of spaces measure alike.
        $normalized = str_replace("\t", '    ', $rawText);
        $length = strlen($normalized);

        if (!preg_match_all(self::CHOICE_LABEL_PATTERN, $normalized, $candidates, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        // A line of typed choices begins with one.
        if ($candidates[0][0][1] !== 0) {
            return null;
        }

        $firstIsLower = ctype_lower($candidates[0][1][0]);
        $firstSeparator = $candidates[0][2][0];

        $accepted = [];
        $lastLetter = null;

        foreach ($candidates as $candidate) {
            $letter = strtoupper($candidate[1][0]);
            $offset = $candidate[0][1];

            if ($lastLetter !== null) {
                // Choices on one line run forwards through the alphabet. A
                // letter at or before the last one is wording: the "A." in
                // "b. Maria A. Cruz".
                if (ord($letter) <= ord($lastLetter)) {
                    continue;
                }

                $gap = $offset - strlen(rtrim(substr($normalized, 0, $offset)));

                // A tab or a wide gap sets a choice off by itself. A single
                // space is also what separates ordinary words ("Irene D.
                // Amores"), so there the letter has to be the very next one
                // and typed the way the line's first label is: that is what
                // "a. organizing b. staffing" looks like, and a name does not.
                if ($gap < 2 && (
                    ord($letter) !== ord($lastLetter) + 1
                    || ctype_lower($candidate[1][0]) !== $firstIsLower
                    || $candidate[2][0] !== $firstSeparator
                )) {
                    continue;
                }
            }

            $accepted[] = [
                'label' => $letter,
                'fullMatchStart' => $offset,
                'textStart' => $offset + strlen($candidate[0][0]),
            ];
            $lastLetter = $letter;
        }

        // Extract text between consecutive label positions
        $options = [];
        $count = count($accepted);
        for ($i = 0; $i < $count; $i++) {
            $textStart = $accepted[$i]['textStart'];
            $textEnd = ($i + 1 < $count)
                ? $accepted[$i + 1]['fullMatchStart']
                : $length;

            $options[] = [
                'label' => $accepted[$i]['label'],
                'text' => $this->normalizeText(substr($normalized, $textStart, $textEnd - $textStart)),
                'start' => $accepted[$i]['fullMatchStart'],
                'text_start' => $textStart,
                'end' => $textEnd,
            ];
        }

        // Filter out empty options
        $options = array_values(array_filter(
            $options,
            fn(array $opt): bool => $this->normalizeText($opt['text']) !== ''
        ));

        return $options === [] ? null : $options;
    }

    /**
     * Word sometimes renders two answer choices on one structural line where the
     * first choice is carried by list numbering and the second choice appears
     * inline after tab spacing, e.g. "Worm\t\t\tc. Spam".
     *
     * @return array<int, array{label: string, text: string, start: int, text_start: ?int, end: int}>|null
     */
    private function splitStructuralLineWithEmbeddedLabel(string $rawText, bool $leadIsWording = false): ?array
    {
        $rawText = trim($rawText);
        if ($rawText === '') {
            return null;
        }

        // A line that opens with a typed letter is a line of typed choices,
        // unless the caller has already worked out that the letter is wording.
        if (!$leadIsWording && preg_match(self::CHOICE_LABEL_PATTERN, $rawText, $lead, PREG_OFFSET_CAPTURE) && $lead[0][1] === 0) {
            return null;
        }

        $normalized = str_replace("\t", '    ', $rawText);
        if (!preg_match('/\s{2,}([A-H])(?:[\).]|[:\-](?=\s))\s*/iu', $normalized, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $embeddedLabelOffset = $match[1][1] ?? null;
        if (!is_int($embeddedLabelOffset) || $embeddedLabelOffset <= 0) {
            return null;
        }

        $leadingText = $this->normalizeText(substr($normalized, 0, $embeddedLabelOffset));
        if ($leadingText === '') {
            return null;
        }

        $tail = ltrim(substr($normalized, $embeddedLabelOffset));
        if ($tail === '') {
            return null;
        }

        $tailOptions = $this->extractOptionsWithLabels($tail);
        if ($tailOptions === null || $tailOptions === []) {
            return null;
        }

        // The tail was scanned on its own, so its positions start from zero.
        $tailStart = strlen($normalized) - strlen($tail);
        $tailOptions = array_map(
            static fn (array $option): array => [
                ...$option,
                'start' => $option['start'] + $tailStart,
                'text_start' => $option['text_start'] + $tailStart,
                'end' => $option['end'] + $tailStart,
            ],
            $tailOptions
        );

        // The first choice takes its letter from Word's list numbering, so
        // all of its stretch is wording.
        return array_merge([[
            'label' => '',
            'text' => $leadingText,
            'start' => 0,
            'text_start' => null,
            'end' => $embeddedLabelOffset,
        ]], $tailOptions);
    }

    private function looksLikeQuestionText(string $text): bool
    {
        if (preg_match('/\?$/', $text)) {
            return true;
        }

        if (preg_match('/_{3,}/', $text)) {
            return true;
        }

        if (preg_match('/^(which|what|who|where|when|why|how|this|these|that|those|an|a|the|within|in|at)\b/i', $text)) {
            return true;
        }

        return strlen($text) >= 70;
    }

    /**
     * Work out, for each question, which choice the teacher marked as correct.
     *
     * Any colour or highlighter can be the mark, so no particular one is looked
     * for. What makes formatting an answer mark is how the document uses it: on
     * one choice of a question and not the others, on question after question.
     * Formatting that keeps turning up on several choices of the same question
     * (a body colour, text pasted in from elsewhere) is not one, and neither is
     * formatting seen on a stray choice or two in a whole exam.
     *
     * Where two marks disagree within a question, the one the document uses
     * more often decides. A question with the mark on more than one choice gets
     * no answer from it, rather than a guess.
     *
     * @param  array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, marks: array<int, string>}>
     * }>  $questions
     * @param  array<int, string>  $warnings
     */
    private function resolveMarkedAnswers(array &$questions, array &$warnings): void
    {
        $onOneChoice = [];
        $onSeveralChoices = [];
        $questionsWithChoices = 0;

        foreach ($questions as $question) {
            $optionCount = count($question['options']);
            if ($optionCount < 2) {
                continue;
            }

            $questionsWithChoices++;

            foreach ($this->countMarks($question['options']) as $mark => $count) {
                if ($count === $optionCount) {
                    continue; // the whole question is set that way
                }

                if ($count === 1) {
                    $onOneChoice[$mark] = ($onOneChoice[$mark] ?? 0) + 1;
                } else {
                    $onSeveralChoices[$mark] = ($onSeveralChoices[$mark] ?? 0) + 1;
                }
            }
        }

        $answerMarks = [];

        foreach ($onOneChoice as $mark => $questionCount) {
            $share = in_array($mark, self::EMPHASIS_MARKS, true) ? 0.3 : 0.05;

            if ($questionCount < $questionsWithChoices * $share) {
                continue;
            }

            if (($onSeveralChoices[$mark] ?? 0) * 2 > $questionCount) {
                continue;
            }

            $answerMarks[$mark] = $questionCount;
        }

        // Most used first; on a tie a highlight outranks a text colour, and
        // either outranks bold or underline.
        $rank = static fn (string $mark): int => match (true) {
            str_starts_with($mark, 'highlight:') => 0,
            str_starts_with($mark, 'color:') => 1,
            default => 2,
        };
        uksort($answerMarks, static fn (string $left, string $right): int => [-$answerMarks[$left], $rank($left), $left]
            <=> [-$answerMarks[$right], $rank($right), $right]);

        foreach ($questions as $index => &$question) {
            $question['marked_label'] = null;

            $optionCount = count($question['options']);
            if ($optionCount < 2) {
                continue;
            }

            $counts = $this->countMarks($question['options']);

            foreach (array_keys($answerMarks) as $mark) {
                $count = $counts[$mark] ?? 0;
                if ($count === 0 || $count === $optionCount) {
                    continue;
                }

                $labels = array_column(
                    array_filter($question['options'], static fn (array $option): bool => in_array($mark, $option['marks'], true)),
                    'label',
                );

                if ($count === 1) {
                    $question['marked_label'] = $labels[0];
                } else {
                    $warnings[] = sprintf(
                        'Question %d has more than one marked choice (%s).',
                        $index + 1,
                        implode(', ', $labels),
                    );
                }

                break;
            }
        }
        unset($question);
    }

    /**
     * How many of a question's choices carry each mark.
     *
     * @param  array<int, array{marks: array<int, string>}>  $options
     * @return array<string, int>
     */
    private function countMarks(array $options): array
    {
        $counts = [];

        foreach ($options as $option) {
            foreach ($option['marks'] as $mark) {
                $counts[$mark] = ($counts[$mark] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, marks: array<int, string>}>,
     *   marked_label: ?string
     * }>  $questions
     * @param  array<int, string>  $answerKeyLetters
     * @param  array<int, string>  $warnings
     */
    private function applyDetectedAnswers(array &$questions, array $answerKey, array &$warnings): void
    {
        if ($answerKey !== [] && count($answerKey) !== count($questions)) {
            $warnings[] = sprintf(
                'Answer key item count (%d) does not match detected questions (%d).',
                count($answerKey),
                count($questions),
            );
        }

        foreach ($questions as $index => &$question) {
            $markedAnswerLabel = $question['marked_label'] ?? null;
            $keyAnswerLabel = $answerKey[$index + 1] ?? null;

            // A key that names a letter the question does not have cannot be
            // followed; it is reported further down.
            $keyNamesAChoice = $keyAnswerLabel !== null && in_array(
                strtoupper($keyAnswerLabel),
                array_map(static fn (array $option): string => strtoupper($option['label']), $question['options']),
                true,
            );

            // The key is the teacher saying what the answer is. A mark is
            // formatting from which the answer is worked out, and formatting
            // gets left behind on the wrong choice. Where the two disagree the
            // key is followed, and the teacher is told to look.
            if ($markedAnswerLabel !== null && $keyNamesAChoice && $markedAnswerLabel !== $keyAnswerLabel) {
                $warnings[] = sprintf(
                    'Question %d: the marked choice (%s) and the answer key (%s) disagree. The answer key was used; check this item.',
                    $index + 1,
                    $markedAnswerLabel,
                    $keyAnswerLabel,
                );
            }

            $resolvedAnswerLabel = $keyNamesAChoice ? $keyAnswerLabel : ($markedAnswerLabel ?? $keyAnswerLabel);
            $resolvedAnswerText = null;

            foreach ($question['options'] as $optionIndex => $option) {
                $isCorrect = $resolvedAnswerLabel !== null && strtoupper($option['label']) === strtoupper($resolvedAnswerLabel);
                $question['options'][$optionIndex]['is_correct'] = $isCorrect;

                if ($isCorrect) {
                    $resolvedAnswerText = $option['text'];
                }

                unset($question['options'][$optionIndex]['marks']);
            }

            if ($resolvedAnswerLabel !== null && $resolvedAnswerText === null && count($question['options']) > 0) {
                $warnings[] = sprintf(
                    'Question %d answer key "%s" does not match any option label.',
                    $index + 1,
                    $resolvedAnswerLabel,
                );
                $resolvedAnswerLabel = null;
            }

            $normalizedOptions = array_values($question['options']);
            $questionType = $this->resolveQuestionType($normalizedOptions);

            if ($questionType !== 'open_ended' && count($normalizedOptions) < 2) {
                $warnings[] = sprintf(
                    'Question %d has fewer than 2 options.',
                    $index + 1,
                );
            }

            if ($questionType !== 'open_ended' && $resolvedAnswerLabel === null) {
                $warnings[] = sprintf(
                    'Question %d has no detected correct answer.',
                    $index + 1,
                );
            }

            $question = [
                'item_number' => $index + 1,
                'text' => $question['text'],
                'question_type' => $questionType,
                'options' => $normalizedOptions,
                'answer_label' => $resolvedAnswerLabel,
                'answer_text' => $resolvedAnswerText,
            ];
        }
        unset($question);
    }

    /**
     * @param  array<int, array{label: string, text: string, is_correct: bool}>  $options
     */
    private function resolveQuestionType(array $options): string
    {
        if ($options === []) {
            return 'open_ended';
        }

        if (count($options) === 2) {
            $normalized = array_map(
                static fn (array $option): string => strtolower(trim($option['text'], " \t\n\r\0\x0B.")),
                $options
            );

            sort($normalized);
            if ($normalized === ['false', 'true']) {
                return 'true_false';
            }
        }

        return 'multiple_choice';
    }

    /**
     * @param  array<int, array{label: string, text: string}>  $options
     */
    private function resolveNextOptionLabel(array $options, int $targetIndex): string
    {
        $used = [];
        foreach ($options as $index => $option) {
            if ($index === $targetIndex) {
                continue;
            }

            $label = strtoupper(trim($option['label']));
            if ($label !== '') {
                $used[$label] = true;
            }
        }

        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $candidate) {
            if (!isset($used[$candidate])) {
                return $candidate;
            }
        }

        return (string) ($targetIndex + 1);
    }

    private function compareOptionLabels(string $leftLabel, string $rightLabel): int
    {
        return $this->optionLabelSortKey($leftLabel) <=> $this->optionLabelSortKey($rightLabel);
    }

    private function optionLabelSortKey(string $label): int
    {
        $normalized = strtoupper(trim($label));
        $alphaLabels = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $alphaIndex = array_search($normalized, $alphaLabels, true);

        if ($alphaIndex !== false) {
            return (int) $alphaIndex;
        }

        if (ctype_digit($normalized)) {
            return 100 + (int) $normalized;
        }

        return 1000;
    }
}
