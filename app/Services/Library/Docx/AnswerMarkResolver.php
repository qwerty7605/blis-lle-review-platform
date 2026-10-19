<?php

namespace App\Services\Library\Docx;

use PhpOffice\PhpWord\Element\Text;

/**
 * Works out which formatting mark the teacher used for correct answers: a
 * mark counts as the answer mark when it sits on exactly one choice per
 * question across at least 5% of the questions (30% for bold and underline,
 * which are everyday emphasis). A mark on two choices of a question sets no
 * answer and warns.
 */
class AnswerMarkResolver
{
    use ReadsDocxText;

    /**
     * Bold and underline are everyday emphasis, so on their own they say
     * little. They count as the answer mark only when the document uses them
     * that way on a good share of its questions.
     */
    private const EMPHASIS_MARKS = ['bold', 'underline'];

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
    public function resolveMarkedAnswers(array &$questions, array &$warnings): void
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
}
