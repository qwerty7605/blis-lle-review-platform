<?php

namespace App\Services\Library\Docx;

use PhpOffice\PhpWord\Element\Text;

/**
 * Answer keys: a section of single letters, or a key typed after the last
 * question (pairs like "1. d   26. c", or five or more one-letter lines), and
 * applying a key to the questions. Where a marked choice and the key disagree
 * the key wins, with a warning.
 */
class AnswerKeyReader
{
    use ReadsDocxText;

    /**
     * @param  array<int, array{section_index: int, text: string}>  $lines
     * @return array{0: ?int, 1: array<int, string>}
     */
    public function detectAnswerKeySection(array $lines): array
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
    public function takeTrailingAnswerKey(array $lines): array
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
     * @param  array<int, array{
     *   text: string,
     *   options: array<int, array{label: string, text: string, marks: array<int, string>}>,
     *   marked_label: ?string
     * }>  $questions
     * @param  array<int, string>  $answerKeyLetters
     * @param  array<int, string>  $warnings
     */
    public function applyDetectedAnswers(array &$questions, array $answerKey, array &$warnings): void
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
}
