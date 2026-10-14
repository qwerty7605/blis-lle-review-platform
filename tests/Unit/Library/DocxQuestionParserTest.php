<?php

namespace Tests\Unit\Library;

use App\Services\Library\DocxQuestionParser;
use Closure;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use RuntimeException;
use Tests\TestCase;

/**
 * DocxQuestionParser, called directly on small Word files built here. The
 * layouts from real LNU exams are in tests/Feature/DocxQuestionReadingTest
 * and DocxAnswerMarkingTest; these cover what is left: a file that cannot be
 * read, questions inside a table, true/false and open-ended questions, and
 * each warning the parser can give about an answer key.
 */
class DocxQuestionParserTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Style::resetStyles();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function document(Closure $write): string
    {
        $phpWord = new PhpWord();
        $write($phpWord->addSection(), $phpWord);

        $path = tempnam(sys_get_temp_dir(), 'parser') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $this->files[] = $path;
        $this->files[] = substr($path, 0, -5);

        return $path;
    }

    /** @param  list<string>  $lines */
    private function lines(array $lines): string
    {
        return $this->document(function (Section $section) use ($lines): void {
            foreach ($lines as $line) {
                $section->addText($line);
            }
        });
    }

    private function parse(string $path): array
    {
        return (new DocxQuestionParser())->parse($path);
    }

    /** Three ordinary four-choice questions, as most exam files type them. */
    private function threeQuestions(): array
    {
        return [
            '1. Which code is used for bibliographic records?',
            'a. MARC',
            'b. HTML',
            'c. JPEG',
            'd. MP3',
            '2. Which scheme uses ten main classes?',
            'a. DDC',
            'b. LCC',
            'c. UDC',
            'd. Colon',
            '3. Which rules replaced AACR2?',
            'a. RDA',
            'b. ISBD',
            'c. FRBR',
            'd. DCMI',
        ];
    }

    public function test_a_file_that_is_not_a_word_document_cannot_be_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'parser') . '.docx';
        file_put_contents($path, 'not a document');
        $this->files[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read DOCX document.');

        $this->parse($path);
    }

    public function test_numbered_questions_are_read_with_their_choices(): void
    {
        $result = $this->parse($this->lines($this->threeQuestions()));

        $this->assertCount(3, $result['questions']);
        $this->assertSame('Which code is used for bibliographic records?', $result['questions'][0]['text']);
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($result['questions'][0]['options'], 'label'));
        $this->assertSame('multiple_choice', $result['questions'][0]['question_type']);
    }

    public function test_questions_typed_inside_a_table_are_read(): void
    {
        $path = $this->document(function (Section $section): void {
            $table = $section->addTable();
            foreach ($this->threeQuestions() as $line) {
                $table->addRow();
                $table->addCell(6000)->addText($line);
            }
        });

        $this->assertCount(3, $this->parse($path)['questions']);
    }

    public function test_a_true_or_false_question_is_recognised(): void
    {
        $result = $this->parse($this->lines([
            ...$this->threeQuestions(),
            '4. A catalog lists the holdings of a library.',
            'a. True',
            'b. False',
        ]));

        $this->assertSame('true_false', $result['questions'][3]['question_type']);
    }

    public function test_a_question_with_no_choices_is_open_ended_and_one_choice_is_warned_about(): void
    {
        $result = $this->parse($this->lines([
            ...$this->threeQuestions(),
            '4. Define the term authority control.',
            '5. Which of these is a union catalog?',
            'a. WorldCat',
        ]));

        $this->assertSame('open_ended', $result['questions'][3]['question_type']);
        $this->assertContains('Question 5 has fewer than 2 options.', $result['warnings']);
        $this->assertContains('Question 5 has no detected correct answer.', $result['warnings']);
    }

    public function test_an_answer_key_sets_the_answers_and_a_short_key_is_warned_about(): void
    {
        $result = $this->parse($this->lines([
            ...$this->threeQuestions(),
            '4. Which tool lists subject headings?',
            'a. LCSH',
            'b. OPAC',
            'c. ILS',
            'd. ISBN',
            'ANSWER KEY',
            '1. a     2. b     3. a',
        ]));

        $this->assertTrue($result['answer_key_detected']);
        $this->assertSame(['A', 'B', 'A', null], array_column($result['questions'], 'answer_label'));
        $this->assertContains('Answer key item count (3) does not match detected questions (4).', $result['warnings']);
    }

    public function test_a_key_letter_the_question_does_not_have_is_warned_about_and_dropped(): void
    {
        $result = $this->parse($this->lines([
            ...$this->threeQuestions(),
            'ANSWER KEY',
            '1. a',
            '2. b',
            '3. f',
        ]));

        $this->assertNull($result['questions'][2]['answer_label']);
        $this->assertContains('Question 3 answer key "F" does not match any option label.', $result['warnings']);
    }
}
