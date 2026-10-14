<?php

namespace Tests\Unit\Library;

use App\Services\Library\DocxQuestionParser;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * PhpWord does not read shading (w:shd), so the parser reads a copy of the
 * document with shading rewritten as highlight. These documents are written
 * as raw WordprocessingML to reach each case: shading on the paragraph of a
 * run that has no properties, shading over a "none" highlight, shading that
 * is only white, and a document whose XML is broken.
 */
class DocxShadingTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

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

    /** A real .docx whose body is replaced with $bodyXml (or the whole document with $documentXml). */
    private function docx(string $bodyXml, ?string $documentXml = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shd') . '.docx';
        $this->files[] = $path;
        $this->files[] = substr($path, 0, -5);
        $word = new PhpWord();
        $word->addSection()->addText('placeholder');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('word/document.xml', $documentXml ?? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document ' . self::W . '><w:body>' . $bodyXml . '<w:sectPr/></w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function p(string $text, string $paragraphProps = '', string $runProps = ''): string
    {
        $pPr = $paragraphProps !== '' ? "<w:pPr>{$paragraphProps}</w:pPr>" : '';
        $rPr = $runProps !== '' ? "<w:rPr>{$runProps}</w:rPr>" : '';

        return "<w:p>{$pPr}<w:r>{$rPr}<w:t xml:space=\"preserve\">{$text}</w:t></w:r></w:p>";
    }

    /** Three questions with the correct choice marked by $mark(paragraph text). */
    private function exam(callable $markCorrect): string
    {
        $xml = '';
        foreach ([1, 2, 3] as $number) {
            $xml .= $this->p("{$number}. Which choice is correct for item {$number}?");
            foreach (['a' => true, 'b' => false, 'c' => false, 'd' => false] as $letter => $correct) {
                $text = "{$letter}. Choice {$letter}{$number}";
                $xml .= $correct ? $markCorrect($text) : $this->p($text);
            }
        }

        return $xml;
    }

    private function answers(string $path): array
    {
        return array_column((new DocxQuestionParser())->parse($path)['questions'], 'answer_label');
    }

    public function test_paragraph_shading_on_a_run_with_no_properties_marks_the_answer(): void
    {
        $path = $this->docx($this->exam(fn (string $text) => $this->p($text, '<w:shd w:val="clear" w:color="auto" w:fill="FFFF00"/>')));

        $this->assertSame(['A', 'A', 'A'], $this->answers($path));
    }

    public function test_shading_wins_over_a_highlight_set_to_none(): void
    {
        $path = $this->docx($this->exam(fn (string $text) => $this->p($text, '', '<w:highlight w:val="none"/><w:shd w:val="clear" w:color="auto" w:fill="00FF00"/>')));

        $this->assertSame(['A', 'A', 'A'], $this->answers($path));
    }

    public function test_a_real_highlight_is_kept_over_shading(): void
    {
        $path = $this->docx($this->exam(fn (string $text) => $this->p($text, '', '<w:highlight w:val="yellow"/><w:shd w:val="clear" w:color="auto" w:fill="00FF00"/>')));

        $this->assertSame(['A', 'A', 'A'], $this->answers($path));
    }

    public function test_white_shading_is_not_a_mark(): void
    {
        $path = $this->docx($this->exam(fn (string $text) => $this->p($text, '<w:shd w:val="clear" w:color="auto" w:fill="FFFFFF"/>')));

        $this->assertSame([null, null, null], $this->answers($path));
    }

    public function test_a_document_with_broken_xml_cannot_be_read(): void
    {
        $path = $this->docx('', '<w:document ' . self::W . '><w:body><w:p><w:shd w:fill="FFFF00"></w:body>');

        $this->expectException(RuntimeException::class);
        (new DocxQuestionParser())->parse($path);
    }
}
