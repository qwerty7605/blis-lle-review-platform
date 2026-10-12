<?php

namespace Tests\Feature;

use App\Services\Library\DocxQuestionParser;
use Closure;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use Tests\TestCase;

/**
 * The import used to find a correct answer only when it was typed in red.
 * Teachers mark answers with whatever is to hand -- a yellow highlighter, blue
 * text, shading, bold -- and those files came in with no answers at all.
 *
 * Any highlight or text colour is now read, and what decides that formatting
 * is an answer mark is how the document uses it: on one choice of a question,
 * question after question.
 */
class DocxAnswerMarkingTest extends TestCase
{
    private const CHOICES = ['A' => 'Alpha', 'B' => 'Bravo', 'C' => 'Charlie', 'D' => 'Delta'];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PhpWord keeps named styles in a static registry. A web request
        // parses one document and exits; a test run parses many.
        Style::resetStyles();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_a_choice_typed_in_red_is_still_the_answer(): void
    {
        $this->assertSame(['B', 'D', 'A'], $this->answers($this->exam([
            ['B' => ['color' => 'FF0000']],
            ['D' => ['color' => 'C00000']],
            ['A' => ['color' => 'EE0000']],
        ])));
    }

    public function test_any_highlighter_colour_marks_the_answer(): void
    {
        $this->assertSame(['B', 'C', 'A', 'D'], $this->answers($this->exam([
            ['B' => ['fgColor' => 'yellow']],
            ['C' => ['fgColor' => 'green']],
            ['A' => ['fgColor' => 'cyan']],
            ['D' => ['fgColor' => 'lightGray']],
        ])));
    }

    public function test_shading_counts_the_same_as_the_highlighter(): void
    {
        // What Word's Shading button and Google Docs write instead of a
        // highlight: on the text itself, or on the whole paragraph.
        $this->assertSame(['C', 'A', 'B'], $this->answers($this->exam([
            ['C' => ['bgColor' => 'FFF2CC']],
            ['A' => ['bgColor' => 'FFFF00']],
            ['B' => ['paragraph' => ['shading' => ['fill' => 'C6E0B4']]]],
        ])));
    }

    public function test_any_text_colour_marks_the_answer(): void
    {
        $this->assertSame(['A', 'B', 'C', 'D'], $this->answers($this->exam([
            ['A' => ['color' => '0070C0']],
            ['B' => ['color' => '00B050']],
            ['C' => ['color' => '7030A0']],
            ['D' => ['color' => 'ED7D31']],
        ])));
    }

    public function test_bold_or_underline_is_the_answer_when_the_document_marks_that_way_throughout(): void
    {
        $this->assertSame(['B', 'A', 'D'], $this->answers($this->exam([
            ['B' => ['bold' => true]],
            ['A' => ['bold' => true]],
            ['D' => ['bold' => true]],
        ])));

        $this->assertSame(['C', 'C', 'A'], $this->answers($this->exam([
            ['C' => ['underline' => 'single']],
            ['C' => ['underline' => 'single']],
            ['A' => ['underline' => 'single']],
        ])));
    }

    public function test_one_stray_bold_choice_is_not_an_answer(): void
    {
        $marks = array_fill(0, 10, []);
        $marks[3] = ['C' => ['bold' => true]];

        $this->assertSame(array_fill(0, 10, null), $this->answers($this->exam($marks)));
    }

    public function test_one_stray_coloured_choice_in_a_long_exam_is_not_an_answer(): void
    {
        $marks = array_fill(0, 30, []);
        $marks[7] = ['B' => ['color' => '0070C0']];

        $this->assertSame(array_fill(0, 30, null), $this->answers($this->exam($marks)));
    }

    public function test_of_two_choices_sharing_a_row_only_the_marked_one_is_the_answer(): void
    {
        $path = $this->document(function (Section $section): void {
            $section->addText('1. Which city is the capital of France?');
            $row = $section->addTextRun();
            $row->addText("A. Berlin\t\t\t");
            $row->addText('C. Paris', ['color' => 'FF0000']);
            $section->addText("B. Madrid\t\t\tD. Rome");

            $section->addText('2. Which city is the capital of Spain?');
            $section->addText("A. Berlin\t\t\tC. Paris");
            $row = $section->addTextRun();
            $row->addText('B. Madrid', ['fgColor' => 'yellow']);
            $row->addText("\t\t\tD. Rome");
        });

        $result = $this->parse($path);

        $this->assertSame(['C', 'B'], array_column($result['questions'], 'answer_label'));
        $this->assertSame([], $result['warnings']);
    }

    public function test_formatting_every_choice_shares_is_not_a_mark(): void
    {
        $blue = ['color' => '1F3864'];

        $this->assertSame([null, 'C', null], $this->answers($this->exam([
            // The whole question was typed in dark blue.
            ['A' => $blue, 'B' => $blue, 'C' => $blue, 'D' => $blue],
            // Dark blue again, except the answer, which is red.
            ['A' => $blue, 'B' => $blue, 'C' => ['color' => 'FF0000'], 'D' => $blue],
            // Dark blue on some choices and not others: pasted text, not an answer.
            ['A' => $blue, 'B' => $blue],
        ])));
    }

    public function test_near_black_and_grey_text_is_ordinary_text(): void
    {
        $this->assertSame([null, null, null], $this->answers($this->exam([
            ['B' => ['color' => '001A1E']],
            ['C' => ['color' => '595959']],
            ['A' => ['color' => '000000']],
        ])));
    }

    public function test_two_marked_choices_set_no_answer_and_say_so(): void
    {
        $result = $this->parse($this->exam([
            ['B' => ['fgColor' => 'yellow']],
            ['A' => ['fgColor' => 'yellow'], 'C' => ['fgColor' => 'yellow']],
            ['D' => ['fgColor' => 'yellow']],
        ]));

        $this->assertSame(['B', null, 'D'], array_column($result['questions'], 'answer_label'));
        $this->assertContains('Question 2 has more than one marked choice (A, C).', $result['warnings']);
    }

    public function test_marking_only_the_letter_is_enough(): void
    {
        $path = $this->document(function (Section $section): void {
            foreach ([1 => 'B', 2 => 'D'] as $number => $answer) {
                $section->addText("{$number}. Question {$number} of this exam?");

                foreach (self::CHOICES as $letter => $text) {
                    $line = $section->addTextRun();
                    $line->addText("{$letter}.", $letter === $answer ? ['fgColor' => 'yellow'] : null);
                    $line->addText(" {$text} is the long wording of this choice");
                }
            }
        });

        $this->assertSame(['B', 'D'], $this->answers($path));
    }

    public function test_a_one_character_choice_is_read_when_only_its_wording_is_marked(): void
    {
        // In "C. 5" the typed letter is most of what is visible, so the mark
        // is measured against the wording alone.
        $path = $this->document(function (Section $section): void {
            $section->addText('1. For how many years is the professional identification card valid?');

            foreach (['A' => '3', 'B' => '4', 'C' => '5', 'D' => '6'] as $letter => $years) {
                $line = $section->addTextRun();
                $line->addText("{$letter}. ");
                $line->addText($years, $letter === 'C' ? ['color' => '0070C0'] : null);
            }
        });

        $this->assertSame(['C'], $this->answers($path));
    }

    public function test_a_coloured_word_inside_a_choice_is_not_a_mark(): void
    {
        $path = $this->document(function (Section $section): void {
            $section->addText('1. Which statement is true of the catalogue?');
            $section->addText('A. It lists every item the library holds');

            $line = $section->addTextRun();
            $line->addText('B. It is ');
            $line->addText('never', ['color' => 'FF0000']);
            $line->addText(' arranged by subject, author or title');

            $section->addText('C. It records where each item is shelved');
            $section->addText('D. It is kept by the cataloguing section');
        });

        $this->assertSame([null], $this->answers($path));
    }

    public function test_choices_lettered_by_word_itself_are_read_the_same_way(): void
    {
        $path = $this->document(function (Section $section, PhpWord $phpWord): void {
            $phpWord->addNumberingStyle('exam', [
                'type' => 'multilevel',
                'levels' => [
                    ['format' => 'decimal', 'text' => '%1.', 'left' => 360, 'hanging' => 360, 'tabPos' => 360],
                    ['format' => 'lowerLetter', 'text' => '%2.', 'left' => 720, 'hanging' => 360, 'tabPos' => 720],
                ],
            ]);

            foreach ([1 => 'Charlie', 2 => 'Alpha', 3 => 'Delta'] as $number => $answer) {
                $section->addListItemRun(0, 'exam')->addText("Question {$number} of this exam asks which of these is right");

                foreach (self::CHOICES as $text) {
                    $section->addListItemRun(1, 'exam')->addText($text, $text === $answer ? ['fgColor' => 'green'] : null);
                }
            }
        });

        $result = $this->parse($path);

        $this->assertSame(['C', 'A', 'D'], array_column($result['questions'], 'answer_label'));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], array_column($result['questions'][0]['options'], 'text'));
    }

    public function test_a_coloured_line_after_the_last_choice_does_not_mark_that_choice(): void
    {
        $red = ['color' => 'FF0000'];

        $path = $this->exam([['B' => $red], ['A' => $red]], function (Section $section) use ($red): void {
            $section->addText('Good luck!', $red);
        });

        $result = $this->parse($path);

        $this->assertSame(['B', 'A'], array_column($result['questions'], 'answer_label'));
        // Nor is the last question reported as having two marked choices.
        $this->assertSame([], $result['warnings']);
    }

    public function test_the_marking_the_document_uses_most_decides_a_disagreement(): void
    {
        $yellow = ['fgColor' => 'yellow'];
        $marks = array_fill(0, 12, ['B' => $yellow]);
        // Two questions also carry a choice in blue, e.g. text pasted from a web page.
        $marks[4]['D'] = ['color' => '0070C0'];
        $marks[9]['A'] = ['color' => '0070C0'];

        $result = $this->parse($this->exam($marks));

        $this->assertSame(array_fill(0, 12, 'B'), array_column($result['questions'], 'answer_label'));
        $this->assertSame([], $result['warnings']);
    }

    public function test_the_answer_key_works_alone_and_decides_when_a_marked_choice_disagrees(): void
    {
        $key = function (Section $section, PhpWord $phpWord): void {
            $keySection = $phpWord->addSection();
            foreach (['A', 'B', 'C', 'D', 'A'] as $letter) {
                $keySection->addText($letter);
            }
        };

        $keyOnly = $this->parse($this->exam(array_fill(0, 5, []), $key));

        $this->assertTrue($keyOnly['answer_key_detected']);
        $this->assertSame(['A', 'B', 'C', 'D', 'A'], array_column($keyOnly['questions'], 'answer_label'));

        // Question 2 is highlighted on D while the key says B.
        $both = $this->parse($this->exam([
            ['A' => ['fgColor' => 'yellow']],
            ['D' => ['fgColor' => 'yellow']],
            ['C' => ['fgColor' => 'yellow']],
            ['D' => ['fgColor' => 'yellow']],
            ['A' => ['fgColor' => 'yellow']],
        ], $key));

        // The key is a statement of the answer; a highlight can be left on
        // the wrong line. The key is followed and the teacher is told.
        $this->assertSame(['A', 'B', 'C', 'D', 'A'], array_column($both['questions'], 'answer_label'));
        $this->assertContains(
            'Question 2: the marked choice (D) and the answer key (B) disagree. The answer key was used; check this item.',
            $both['warnings'],
        );
    }

    /**
     * A typed exam: "1. Question 1 ...?" followed by "A. Alpha" to "D. Delta",
     * one choice per line. $marks[n] maps a letter of question n + 1 to the
     * font style that choice is typed in; "paragraph" inside it styles the
     * paragraph instead.
     *
     * @param  list<array<string, array<string, mixed>>>  $marks
     */
    private function exam(array $marks, ?Closure $after = null): string
    {
        return $this->document(function (Section $section, PhpWord $phpWord) use ($marks, $after): void {
            foreach ($marks as $index => $styles) {
                $number = $index + 1;
                $section->addText("{$number}. Question {$number} of this exam?");

                foreach (self::CHOICES as $letter => $text) {
                    $font = $styles[$letter] ?? [];
                    $paragraph = $font['paragraph'] ?? null;
                    unset($font['paragraph']);

                    $section->addText("{$letter}. {$text}", $font === [] ? null : $font, $paragraph);
                }
            }

            if ($after !== null) {
                $after($section, $phpWord);
            }
        });
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

    /** @return list<?string> */
    private function answers(string $path): array
    {
        return array_column($this->parse($path)['questions'], 'answer_label');
    }
}
