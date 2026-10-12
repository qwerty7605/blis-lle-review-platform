<?php

namespace Tests\Feature;

use App\Services\Library\DocxQuestionParser;
use Closure;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style;
use Tests\TestCase;

/**
 * How a teacher's file is cut into questions and choices. Each test is a
 * layout taken from a real LNU mock exam that the import used to misread: a
 * question invented from an instruction, a choice split at "H." or "E-", a
 * row of choices left as one, an answer key read as twenty-five questions.
 *
 * Reading which choice is marked as correct is DocxAnswerMarkingTest.
 */
class DocxQuestionReadingTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PhpWord keeps named styles in a static registry. A web request
        // parses one document and exits; a test run parses many.
        Style::resetStyles();

        // So that the fixtures can hold an ampersand: without it PhpWord
        // writes the character raw and the file is not valid XML.
        Settings::setOutputEscapingEnabled(true);
    }

    protected function tearDown(): void
    {
        Settings::setOutputEscapingEnabled(false);

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_symbols_and_quotation_marks_are_read_as_they_were_typed(): void
    {
        $result = $this->parse($this->typedExam([
            ['The "stepped reckoner" was designed by', ['Pascal & Leibniz', "Babbage's son", 'Hollerith <IBM>', 'Lovelace']],
            ['Chinese musical instruments & pictures is catalogued under', ['Title', 'Editor', 'Publisher', 'Series']],
            ['Which is a mark-up language?', ['HTML', 'JAVA', 'COBOL', 'BASIC']],
        ]));

        $this->assertSame('The "stepped reckoner" was designed by', $result['questions'][0]['text']);
        $this->assertSame(
            ['Pascal & Leibniz', "Babbage's son", 'Hollerith <IBM>', 'Lovelace'],
            array_column($result['questions'][0]['options'], 'text'),
        );
        $this->assertSame('Chinese musical instruments & pictures is catalogued under', $result['questions'][1]['text']);
    }

    public function test_lines_that_are_not_questions_do_not_become_questions(): void
    {
        $instruction = 'Select the correct transcription of the following information in the catalog entry.';

        $path = $this->document(function (Section $section) use ($instruction): void {
            $section->addText('For system testing only. The correct answer of each item is typed in red, so keep this file private.');
            $this->typeQuestion($section, 1, 'Which is a mark-up language?', ['HTML', 'JAVA', 'COBOL', 'BASIC']);
            $this->typeQuestion($section, 2, 'Which is a network protocol?', ['FTP', 'PDF', 'RAM', 'CPU']);
            $section->addText($instruction);
            $this->typeQuestion($section, 3, 'Language of the street and other Essays', ['First form', 'Second form', 'Third form', 'Fourth form']);
            $section->addText('GOODLUCK!');
        });

        $result = $this->parse($path);

        // Three, numbered as the document numbers them: the note above the
        // first and the instruction before the third used to be items too,
        // and every question after an invented one was one number out.
        $this->assertSame([1, 2, 3], array_column($result['questions'], 'item_number'));
        $this->assertSame('Which is a mark-up language?', $result['questions'][0]['text']);
        $this->assertSame('Language of the street and other Essays', $result['questions'][2]['text']);

        // Nor is a stray line glued onto the choice above it.
        $this->assertSame('CPU', $result['questions'][1]['options'][3]['text']);
        $this->assertSame('Fourth form', $result['questions'][2]['options'][3]['text']);

        // The instruction is reported, so the teacher knows it was left out.
        $this->assertContains(
            'A line after question 2 is not part of any question and was left out: "' . $instruction . '"',
            $result['warnings'],
        );
    }

    public function test_a_question_can_still_run_over_several_paragraphs(): void
    {
        $path = $this->document(function (Section $section): void {
            $section->addText('1. Riza was on her interview and could not talk straight anymore.');
            $section->addText('What barrier of communication happened in the scenario?');
            foreach (['A. language', 'B. selectivity', 'C. emotion', 'D. race'] as $choice) {
                $section->addText($choice);
            }
            $this->typeQuestion($section, 2, 'Which is a network protocol?', ['FTP', 'PDF', 'RAM', 'CPU']);
            $this->typeQuestion($section, 3, 'Which is a mark-up language?', ['HTML', 'JAVA', 'COBOL', 'BASIC']);
        });

        $result = $this->parse($path);

        $this->assertCount(3, $result['questions']);
        $this->assertSame(
            'Riza was on her interview and could not talk straight anymore. What barrier of communication happened in the scenario?',
            $result['questions'][0]['text'],
        );
        $this->assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'left out'),
        )));
    }

    public function test_a_choice_that_begins_with_a_capital_and_a_full_stop_or_a_hyphen_stays_whole(): void
    {
        $result = $this->parse($this->listedExam([
            ['Who introduced the idea of one index to many periodicals?', ['W.E. Batten', 'Hans Peter Luhn', 'W.F. Poole', 'H. Wheatley']],
            ['The rules governing how files are sent across the Internet', ['Protocol', 'E-mail', 'Telnet', 'FTP', 'Firewall']],
            ['Who wrote the first rules for a dictionary catalog?', ['C. A. Cutter', 'Melvil Dewey', 'Panizzi', 'Jewett']],
        ]));

        $this->assertSame(
            ['W.E. Batten', 'Hans Peter Luhn', 'W.F. Poole', 'H. Wheatley'],
            array_column($result['questions'][0]['options'], 'text'),
        );
        $this->assertSame('Who introduced the idea of one index to many periodicals?', $result['questions'][0]['text']);

        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($result['questions'][1]['options'], 'label'));
        $this->assertSame('E-mail', $result['questions'][1]['options'][1]['text']);

        $this->assertSame('C. A. Cutter', $result['questions'][2]['options'][0]['text']);
    }

    public function test_choices_typed_on_one_line_with_a_single_space_between_them_are_separated(): void
    {
        $path = $this->document(function (Section $section): void {
            $section->addText('1. What is the function of management that reflects planning?');
            $section->addText('a. organizing b. staffing   c. directing d. controlling   e. planning');
            $section->addText('2. Who was the first chairman of the Board for Librarians?');
            $section->addText("a. Irene D. Amores\t\tb. Juan C. Buenrostro");
            $section->addText('c. Maria A. Orendain d. Rosa B. Vallejo');
            $section->addText('3. Which is a network protocol?');
            $section->addText("a. FTP\tb. PDF\tc. RAM\td. CPU");
        });

        $result = $this->parse($path);

        $this->assertSame(
            ['organizing', 'staffing', 'directing', 'controlling', 'planning'],
            array_column($result['questions'][0]['options'], 'text'),
        );

        // A middle initial is the same shape as a choice letter. It is told
        // apart by not being the next letter in the run.
        $this->assertSame(
            ['Irene D. Amores', 'Juan C. Buenrostro', 'Maria A. Orendain', 'Rosa B. Vallejo'],
            array_column($result['questions'][1]['options'], 'text'),
        );
        $this->assertSame(['FTP', 'PDF', 'RAM', 'CPU'], array_column($result['questions'][2]['options'], 'text'));
    }

    public function test_a_number_that_opens_the_sentence_is_kept(): void
    {
        $result = $this->parse($this->listedExam([
            ['198.70.185.3 is an example of:', ['A web address', 'An IP address', 'A domain name', 'A port']],
            ['3-D printing is a kind of', ['Subtractive process', 'Additive process', 'Casting', 'Milling']],
            ['Which is a network protocol?', ['FTP', 'PDF', 'RAM', 'CPU']],
        ]));

        $this->assertSame('198.70.185.3 is an example of:', $result['questions'][0]['text']);
        $this->assertSame('3-D printing is a kind of', $result['questions'][1]['text']);

        $typed = $this->parse($this->typedExam([
            ['1990-1995 was the period in which the Web appeared. True or false?', ['True', 'False']],
            ['Which is a network protocol?', ['FTP', 'PDF', 'RAM', 'CPU']],
            ['Which is a mark-up language?', ['HTML', 'JAVA', 'COBOL', 'BASIC']],
        ]));

        $this->assertSame('1990-1995 was the period in which the Web appeared. True or false?', $typed['questions'][0]['text']);
    }

    public function test_choices_lettered_by_word_keep_their_place_when_the_last_one_is_typed_after_a_tab(): void
    {
        $path = $this->document(function (Section $section, PhpWord $phpWord): void {
            $this->addExamNumbering($phpWord);

            $section->addListItemRun(0, 'exam')->addText('The standard punctuation used to precede each area is the');
            $section->addListItemRun(1, 'exam')->addText('Full stop (.)');
            $section->addListItemRun(1, 'exam')->addText('Full stop, space, dash, space (. --)');
            $section->addListItemRun(1, 'exam')->addText("Slash (/)\t\t\tD. Semicolon (;)");

            foreach (['Which is a network protocol?', 'Which is a mark-up language?'] as $question) {
                $section->addListItemRun(0, 'exam')->addText($question);
                foreach (['First', 'Second', 'Third', 'Fourth'] as $choice) {
                    $section->addListItemRun(1, 'exam')->addText($choice);
                }
            }
        });

        $result = $this->parse($path);

        $this->assertSame('The standard punctuation used to precede each area is the', $result['questions'][0]['text']);
        $this->assertSame(
            ['A' => 'Full stop (.)', 'B' => 'Full stop, space, dash, space (. --)', 'C' => 'Slash (/)', 'D' => 'Semicolon (;)'],
            array_column($result['questions'][0]['options'], 'text', 'label'),
        );
    }

    public function test_numbered_statements_inside_a_question_stay_in_the_question(): void
    {
        $path = $this->document(function (Section $section, PhpWord $phpWord): void {
            $this->addExamNumbering($phpWord);
            $phpWord->addNumberingStyle('statements', [
                'type' => 'multilevel',
                'levels' => [['format' => 'upperRoman', 'text' => '%1.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720]],
            ]);

            $section->addListItemRun(0, 'exam')->addText('Consider the following statements about indexes.');
            $section->addListItemRun(0, 'statements')->addText('An index points to information.');
            $section->addListItemRun(0, 'statements')->addText('An abstract replaces the document.');
            $section->addText('Which of the statements is true?');
            foreach (['First only', 'Second only', 'Both', 'Neither'] as $choice) {
                $section->addListItemRun(1, 'exam')->addText($choice);
            }

            foreach (['Which is a network protocol?', 'Which is a mark-up language?'] as $question) {
                $section->addListItemRun(0, 'exam')->addText($question);
                foreach (['First', 'Second', 'Third', 'Fourth'] as $choice) {
                    $section->addListItemRun(1, 'exam')->addText($choice);
                }
            }
        });

        $result = $this->parse($path);

        $this->assertCount(3, $result['questions']);
        $this->assertSame(
            'Consider the following statements about indexes. 1. An index points to information. 2. An abstract replaces the document. Which of the statements is true?',
            $result['questions'][0]['text'],
        );
        $this->assertSame(['First only', 'Second only', 'Both', 'Neither'], array_column($result['questions'][0]['options'], 'text'));
        $this->assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $warning): bool => str_contains($warning, 'left out'),
        )));
    }

    public function test_an_answer_key_typed_after_the_questions_is_read_as_the_key(): void
    {
        $questions = [];
        foreach (range(1, 6) as $number) {
            $questions[] = ["Question {$number} of this exam?", ['Alpha', 'Bravo', 'Charlie', 'Delta']];
        }

        $path = $this->typedExam($questions, function (Section $section): void {
            $section->addText('Answer Key');
            $section->addText("1. d\t\t4. c");
            $section->addText("2. b\t\t5. a");
            $section->addText("3. a\t\t\t6. b");
        });

        $result = $this->parse($path);

        // Six questions, not nine: the key's lines used to be read as
        // questions with no choices, and no answer was applied.
        $this->assertCount(6, $result['questions']);
        $this->assertSame(['D', 'B', 'A', 'C', 'A', 'B'], array_column($result['questions'], 'answer_label'));
        $this->assertTrue($result['answer_key_detected']);
        $this->assertSame(6, $result['answer_key_items']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_an_answer_key_of_one_letter_per_line_needs_no_section_break(): void
    {
        $questions = [];
        foreach (range(1, 5) as $number) {
            $questions[] = ["Question {$number} of this exam?", ['Alpha', 'Bravo', 'Charlie', 'Delta']];
        }

        $path = $this->typedExam($questions, function (Section $section): void {
            foreach (['B', 'C', 'A', 'D', 'B'] as $letter) {
                $section->addText($letter);
            }
        });

        $result = $this->parse($path);

        $this->assertCount(5, $result['questions']);
        $this->assertSame(['B', 'C', 'A', 'D', 'B'], array_column($result['questions'], 'answer_label'));
        $this->assertSame('Delta', $result['questions'][4]['options'][3]['text']);
    }

    public function test_the_answer_key_decides_when_a_marked_choice_disagrees_with_it(): void
    {
        $red = ['color' => 'FF0000'];

        $path = $this->document(function (Section $section) use ($red): void {
            foreach (range(1, 4) as $number) {
                $section->addText("{$number}. Question {$number} of this exam?");
                foreach (['A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta'] as $letter => $text) {
                    // Every question has B typed in red.
                    $section->addText("{$letter}. {$text}", $letter === 'B' ? $red : null);
                }
            }

            $section->addText('Answer Key');
            $section->addText("1. b\t2. c\t3. b\t4. h");
        });

        $result = $this->parse($path);

        // Question 2: the key says C. Question 4: the key names a letter the
        // question does not have, so the marked choice stands.
        $this->assertSame(['B', 'C', 'B', 'B'], array_column($result['questions'], 'answer_label'));
        $this->assertContains(
            'Question 2: the marked choice (B) and the answer key (C) disagree. The answer key was used; check this item.',
            $result['warnings'],
        );
    }

    public function test_a_document_with_no_numbering_is_still_read_by_its_wording(): void
    {
        $path = $this->document(function (Section $section): void {
            $section->addText('Which planet is the largest?');
            foreach (['A. Mars', 'B. Jupiter', 'C. Venus', 'D. Mercury'] as $choice) {
                $section->addText($choice);
            }
            $section->addText('What is the nearest star to the Earth?');
            foreach (['A. Sirius', 'B. Vega', 'C. The Sun', 'D. Polaris'] as $choice) {
                $section->addText($choice);
            }
        });

        $result = $this->parse($path);

        $this->assertSame(
            ['Which planet is the largest?', 'What is the nearest star to the Earth?'],
            array_column($result['questions'], 'text'),
        );
        $this->assertSame(['Sirius', 'Vega', 'The Sun', 'Polaris'], array_column($result['questions'][1]['options'], 'text'));
    }

    /**
     * Questions typed as "1. ..." with a choice per line typed as "A. ...".
     *
     * @param  list<array{0: string, 1: list<string>}>  $questions
     */
    private function typedExam(array $questions, ?Closure $after = null): string
    {
        return $this->document(function (Section $section) use ($questions, $after): void {
            foreach ($questions as $index => [$text, $choices]) {
                $this->typeQuestion($section, $index + 1, $text, $choices);
            }

            if ($after !== null) {
                $after($section);
            }
        });
    }

    /** @param  list<string>  $choices */
    private function typeQuestion(Section $section, int $number, string $text, array $choices): void
    {
        $section->addText("{$number}. {$text}");

        foreach ($choices as $index => $choice) {
            $section->addText(chr(65 + $index) . '. ' . $choice);
        }
    }

    /**
     * Questions numbered and choices lettered by Word's own list numbering,
     * so neither the number nor the letter is in the text.
     *
     * @param  list<array{0: string, 1: list<string>}>  $questions
     */
    private function listedExam(array $questions): string
    {
        return $this->document(function (Section $section, PhpWord $phpWord) use ($questions): void {
            $this->addExamNumbering($phpWord);

            foreach ($questions as [$text, $choices]) {
                $section->addListItemRun(0, 'exam')->addText($text);

                foreach ($choices as $choice) {
                    $section->addListItemRun(1, 'exam')->addText($choice);
                }
            }
        });
    }

    private function addExamNumbering(PhpWord $phpWord): void
    {
        $phpWord->addNumberingStyle('exam', [
            'type' => 'multilevel',
            'levels' => [
                ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
                ['format' => 'upperLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
            ],
        ]);
    }

    private function document(Closure $write): string
    {
        $phpWord = new PhpWord();
        $write($phpWord->addSection(), $phpWord);

        $path = tempnam(sys_get_temp_dir(), 'exam') . '.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $this->files[] = $path;
        $this->files[] = substr($path, 0, -5);

        return $path;
    }

    /** @return array<string, mixed> */
    private function parse(string $path): array
    {
        return (new DocxQuestionParser())->parse($path);
    }
}
