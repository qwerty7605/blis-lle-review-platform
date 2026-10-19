<?php

namespace App\Services\Library;

use App\Services\Library\Docx\AnswerKeyReader;
use App\Services\Library\Docx\AnswerMarkResolver;
use App\Services\Library\Docx\DocxLineReader;
use App\Services\Library\Docx\QuestionSegmenter;
use PhpOffice\PhpWord\Element\Text;
use RuntimeException;
use Throwable;

/**
 * Reads a teacher's Word file of exam questions: the questions, their
 * choices, and the correct answers, however the teacher marked them (a
 * highlight, shading, a colour, bold, underline, or an answer key).
 *
 * The work is done in four steps, each its own class in Docx/: reading the
 * file into lines (DocxLineReader), finding an answer key (AnswerKeyReader),
 * cutting the lines into questions (QuestionSegmenter), and deciding which
 * mark means "correct" (AnswerMarkResolver).
 */
class DocxQuestionParser
{
    public function __construct(
        private readonly DocxLineReader $reader = new DocxLineReader(),
        private readonly QuestionSegmenter $segmenter = new QuestionSegmenter(),
        private readonly AnswerKeyReader $answerKeys = new AnswerKeyReader(),
        private readonly AnswerMarkResolver $marks = new AnswerMarkResolver(),
    ) {
    }

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
            $phpWord = $this->reader->loadDocument($filePath);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to read DOCX document.', previous: $exception);
        }

        $lines = $this->reader->extractLines($phpWord);
        [$answerKeySection, $answerKeyLetters] = $this->answerKeys->detectAnswerKeySection($lines);

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

        [$contentLines, $trailingKey] = $this->answerKeys->takeTrailingAnswerKey($contentLines);
        $answerKey += $trailingKey;

        $dominantQuestionNumId = $this->segmenter->resolveDominantQuestionNumId($contentLines);
        $warnings = [];

        $questions = $this->segmenter->buildQuestions($contentLines, $dominantQuestionNumId, $warnings);
        $this->marks->resolveMarkedAnswers($questions, $warnings);
        $this->answerKeys->applyDetectedAnswers($questions, $answerKey, $warnings);

        return [
            'questions' => $questions,
            'warnings' => array_values(array_unique($warnings)),
            'answer_key_detected' => $answerKey !== [],
            'answer_key_items' => count($answerKey),
        ];
    }
}
