<?php

namespace Tests\Unit\Library;

use App\Services\Library\DocxQuestionParser;
use PhpOffice\PhpWord\Element\TextBreak;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\Fakes\Tab;
use Tests\TestCase;

/**
 * White-box tests of DocxQuestionParser's private helper functions, called
 * through reflection: naming a mark's colour family, reading text out of
 * each kind of Word element, the "does this line look like..." checks,
 * folding a statement list back into the question, and choice-letter order.
 */
class DocxQuestionParserHelpersTest extends TestCase
{
    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(DocxQuestionParser::class, $method);

        return $reflection->invokeArgs(new DocxQuestionParser(), $arguments);
    }

    /** @return array<string, array{mixed, bool, ?string}> */
    public static function colours(): array
    {
        return [
            'not a string' => [null, false, null],
            'auto' => ['auto', true, null],
            'none' => ['none', false, null],
            'white by name' => ['white', false, null],
            'not hex' => ['zzzzzz', false, null],
            'red text' => ['#FF0000', true, 'red'],
            'dark red text is still red' => ['C00000', true, 'red'],
            'orange' => ['FF8000', false, 'orange'],
            'yellow by highlight name' => ['yellow', false, 'yellow'],
            'green' => ['00B050', true, 'green'],
            'cyan' => ['00FFFF', false, 'cyan'],
            'blue' => ['0070C0', true, 'blue'],
            'purple' => ['7030A0', true, 'purple'],
            'pink, with a hue below zero' => ['FF0080', false, 'pink'],
            'grey text is ordinary writing' => ['808080', true, null],
            'grey highlight is a mark' => ['808080', false, 'gray'],
            'white highlight is not' => ['FFFFFF', false, null],
            'near-black text is ordinary writing' => ['300000', true, null],
        ];
    }

    #[DataProvider('colours')]
    public function test_a_colour_is_named_by_its_family(mixed $value, bool $isText, ?string $expected): void
    {
        $this->assertSame($expected, $this->invoke('colorFamily', $value, $isText));
    }

    public function test_something_that_is_not_a_font_has_no_marks(): void
    {
        $this->assertSame([], $this->invoke('marksOfFont', null));
        $this->assertSame([], $this->invoke('marksOfFont', 'bold'));
    }

    public function test_text_is_read_out_of_each_kind_of_element(): void
    {
        $this->assertSame([['text' => "\t", 'marks' => []]], $this->invoke('collectTextSegments', new Tab()));
        $this->assertSame([['text' => "\n", 'marks' => []]], $this->invoke('collectTextSegments', new TextBreak()));

        $link = new class {
            public function getText(): string
            {
                return 'Tom &amp; Jerry';
            }
        };
        $this->assertSame([['text' => 'Tom & Jerry', 'marks' => []]], $this->invoke('collectTextSegments', $link));
        $this->assertSame([], $this->invoke('collectTextSegments', new \stdClass()));
    }

    public function test_compact_choices_are_short_codes_and_numbers(): void
    {
        $this->assertTrue($this->invoke('looksLikeCompactChoice', '1, 2 and 3') === false);
        $this->assertTrue($this->invoke('looksLikeCompactChoice', '1 and 2') === false);
        $this->assertTrue($this->invoke('looksLikeCompactChoice', '1, 2, 3'));
        $this->assertTrue($this->invoke('looksLikeCompactChoice', 'A, B'));
        $this->assertFalse($this->invoke('looksLikeCompactChoice', ''));
        $this->assertFalse($this->invoke('looksLikeCompactChoice', 'a long line of ordinary words here'));
        $this->assertFalse($this->invoke('looksLikeCompactChoice', 'Library'));
    }

    public function test_statement_lines_have_letters_and_more_than_one_word(): void
    {
        $this->assertTrue($this->invoke('looksLikeStatementLine', 'The catalog lists holdings.'));
        $this->assertFalse($this->invoke('looksLikeStatementLine', ''));
        $this->assertFalse($this->invoke('looksLikeStatementLine', '12 34 56'));
        $this->assertFalse($this->invoke('looksLikeStatementLine', 'Short'));
    }

    public function test_headings_are_short_capitals_or_an_lle_review_title(): void
    {
        $this->assertTrue($this->invoke('looksLikeHeading', 'PART II. CATALOGING'));
        $this->assertTrue($this->invoke('looksLikeHeading', 'LLE Review in Reference Services'));
        $this->assertFalse($this->invoke('looksLikeHeading', 'Which of these is a union catalog?'));
    }

    public function test_question_numbers_are_read_but_numbers_inside_a_sentence_are_not(): void
    {
        $this->assertSame('What is MARC?', $this->invoke('stripQuestionPrefix', '12. What is MARC?'));
        $this->assertSame('What is MARC?', $this->invoke('stripQuestionPrefix', 'Question 3: What is MARC?'));
        $this->assertNull($this->invoke('stripQuestionPrefix', '198.70.185.3 is an example of'));
        $this->assertSame(12, $this->invoke('typedQuestionNumber', '12. What is MARC?'));
        $this->assertNull($this->invoke('typedQuestionNumber', 'What is MARC?'));
    }

    public function test_form_lines_at_the_top_of_an_exam_are_ignored(): void
    {
        $this->assertTrue($this->invoke('isIgnorableHeader', 'Name: ____________'));
        $this->assertTrue($this->invoke('isIgnorableHeader', 'Directions: Choose the best answer.'));
        $this->assertFalse($this->invoke('isIgnorableHeader', 'Which tool is used for names?'));
    }

    public function test_question_wording_is_recognised(): void
    {
        $this->assertTrue($this->invoke('looksLikeQuestionText', 'MARC stands for ____ cataloging.'));
        $this->assertTrue($this->invoke('looksLikeQuestionText', 'Which of these is a union catalog'));
        $this->assertTrue($this->invoke('looksLikeQuestionText', str_repeat('word ', 15)));
        $this->assertFalse($this->invoke('looksLikeQuestionText', 'Almanac'));
    }

    public function test_a_statement_list_is_folded_into_the_question_and_numbered(): void
    {
        $text = $this->invoke('appendStructuralLinesToQuestionText', 'Consider the statements:', [
            ['text' => '1. Catalogs list holdings.'],
            ['text' => '  '],
            ['text' => 'a) Indexes point to articles.'],
            ['text' => '2.'],
        ]);

        $this->assertSame('Consider the statements: 1. Catalogs list holdings. 3. Indexes point to articles.', $text);
        $this->assertSame('Only the question', $this->invoke('appendStructuralLinesToQuestionText', ' Only the question ', [['text' => '']]));
    }

    public function test_a_list_turns_into_choices_when_its_numbering_or_depth_changes(): void
    {
        $pending = [
            ['text' => 'Catalogs list holdings.', 'num_id' => 1, 'depth' => 0],
            ['text' => 'Indexes point to articles.', 'num_id' => 1, 'depth' => 0],
        ];

        $this->assertFalse($this->invoke('shouldStartStructuralOptions', [$pending[0]], ['text' => 'x', 'num_id' => 2, 'depth' => 0]));
        $this->assertTrue($this->invoke('shouldStartStructuralOptions', $pending, ['text' => 'x', 'num_id' => 2, 'depth' => 0]));
        $this->assertTrue($this->invoke('shouldStartStructuralOptions', $pending, ['text' => 'x', 'num_id' => 1, 'depth' => 1]));
        $this->assertTrue($this->invoke('shouldStartStructuralOptions', $pending, ['text' => '1 and 2', 'num_id' => null, 'depth' => null]) === false);
        $this->assertTrue($this->invoke('shouldStartStructuralOptions', $pending, ['text' => '1, 2', 'num_id' => null, 'depth' => null]));

        $shortPending = [['text' => 'A', 'num_id' => null, 'depth' => null], ['text' => 'B', 'num_id' => null, 'depth' => null]];
        $this->assertFalse($this->invoke('shouldStartStructuralOptions', $shortPending, ['text' => '1, 2', 'num_id' => null, 'depth' => null]));
    }

    public function test_statements_followed_by_code_choices_are_split_apart(): void
    {
        $method = new ReflectionMethod(DocxQuestionParser::class, 'normalizeUnlabeledMixedOptions');
        $parser = new DocxQuestionParser();
        $option = fn (string $text) => ['label' => '', 'text' => $text, 'marks' => []];

        $current = [
            'text' => 'Which are true?',
            'has_labeled_options' => false,
            'pending_structural' => [],
            'options' => array_map($option, ['Catalogs list holdings.', 'Indexes point to articles.', 'Atlases hold maps.', '1, 2', '2, 3', '1, 3']),
        ];
        $method->invokeArgs($parser, [&$current]);

        $this->assertSame(['1, 2', '2, 3', '1, 3'], array_column($current['options'], 'text'));
        $this->assertStringContainsString('1. Catalogs list holdings.', $current['text']);

        // Left alone: lettered choices, too few, or no clean split.
        foreach ([
            ['has_labeled_options' => true],
            ['options' => array_map($option, ['a', 'b', 'c', 'd'])],
            ['options' => [['label' => 'A', 'text' => 'x', 'marks' => []], ...array_map($option, ['b', 'c', 'd', 'e'])]],
            ['options' => array_map($option, ['Catalogs list holdings.', 'x', 'y', 'Atlases hold maps.', 'More words here.'])],
            ['options' => array_map($option, ['1, 2', 'Catalogs list holdings.', '2, 3', '1, 3', '3, 4'])],
            // Two statements, but too few of the lines before the codes are statements.
            ['options' => array_map($option, ['Catalogs list holdings.', 'Indexes point to articles.', 'Atlas', 'Globe', '1, 2', '2, 3', '1, 3'])],
        ] as $override) {
            $unchanged = [...$current, 'has_labeled_options' => false, 'options' => array_map($option, ['p', 'q', 'r', 's', 't']), ...$override];
            $before = $unchanged['options'];
            $method->invokeArgs($parser, [&$unchanged]);
            $this->assertSame($before, $unchanged['options']);
        }
    }

    public function test_a_line_with_a_second_choice_after_tabs_is_split_only_when_it_really_has_one(): void
    {
        $split = $this->invoke('splitStructuralLineWithEmbeddedLabel', "Worm\t\t\tc. Spam");
        $this->assertSame(['', 'C'], array_column($split, 'label'));
        $this->assertSame(['Worm', 'Spam'], array_column($split, 'text'));

        $this->assertNull($this->invoke('splitStructuralLineWithEmbeddedLabel', '   '));
        $this->assertNull($this->invoke('splitStructuralLineWithEmbeddedLabel', 'a. Worm   c. Spam'));
        $this->assertNull($this->invoke('splitStructuralLineWithEmbeddedLabel', 'Worm and spam'));
        $this->assertNull($this->invoke('splitStructuralLineWithEmbeddedLabel', "\t\tc. Spam"));
        $this->assertNull($this->invoke('extractOptionsWithLabels', '   '));
        $this->assertNull($this->invoke('extractOptionsWithLabels', 'no letters here'));
    }

    public function test_choice_letters_sort_before_numbers_before_anything_else(): void
    {
        $this->assertSame(0, $this->invoke('optionLabelSortKey', 'a'));
        $this->assertSame(103, $this->invoke('optionLabelSortKey', '3'));
        $this->assertSame(1000, $this->invoke('optionLabelSortKey', '?'));
        $this->assertLessThan(0, $this->invoke('compareOptionLabels', 'B', '1'));
    }

    public function test_when_every_letter_is_taken_the_next_choice_gets_a_number(): void
    {
        $options = array_map(fn ($label) => ['label' => $label, 'text' => 'x'], ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', '']);

        $this->assertSame('9', $this->invoke('resolveNextOptionLabel', $options, 8));
        $this->assertSame('B', $this->invoke('resolveNextOptionLabel', [['label' => 'A', 'text' => 'x'], ['label' => '', 'text' => 'y']], 1));
    }
}
