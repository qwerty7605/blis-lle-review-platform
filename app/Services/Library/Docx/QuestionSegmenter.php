<?php

namespace App\Services\Library\Docx;

use PhpOffice\PhpWord\Element\Text;

/**
 * Cuts a document's lines into questions and their choices. If the document
 * numbers its questions (Word numbering, or three or more typed "12." lines)
 * a question starts at a number and nowhere else; the "looks like a
 * question" guesses run only for documents with no numbering. Each choice
 * keeps the formatting marks on it, for AnswerMarkResolver.
 */
class QuestionSegmenter
{
    use ReadsDocxText;

    /**
     * A typed choice letter: A to H standing on its own, then a full stop or a
     * closing bracket, or a colon or hyphen with a space after it. "E-mail"
     * and "C:drive" are words, not choices E and C.
     */
    private const CHOICE_LABEL_PATTERN = '/(?:^|(?<=\s))([A-H])([\).]|[:\-](?=\s))\s*/iu';

    /**
     * @param  array<int, array{text: string, num_id: ?int, depth: ?int}>  $lines
     */
    public function resolveDominantQuestionNumId(array $lines): ?int
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
    public function buildQuestions(array $lines, ?int $dominantQuestionNumId, array &$warnings): array
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
