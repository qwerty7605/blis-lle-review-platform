<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetPredictionService;
use RuntimeException;
use Tests\TestCase;

/**
 * LetBaseDataset reads ml/data/lle_clean.arff, the 156 review-exam records
 * the original model was trained on and every retraining starts from.
 */
class LetBaseDatasetTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function arff(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'arff');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }

    private function header(): string
    {
        return "@RELATION test\n% a comment\n"
            . implode("\n", array_map(fn ($name) => "@ATTRIBUTE {$name} numeric", LetPredictionService::requiredFeatures()))
            . "\n@ATTRIBUTE board_result {FAILED,PASSED}\n\n@DATA\n";
    }

    public function test_the_project_s_dataset_has_its_156_records(): void
    {
        $dataset = new LetBaseDataset();

        $this->assertTrue($dataset->exists());
        $rows = $dataset->rows();
        $this->assertCount(156, $rows);
        $this->assertSame('base:1', $rows[0]['key']);
        $this->assertSame(LetPredictionService::RESULT_PASS, $rows[0]['label']);
        $this->assertSame(46.0, $rows[0]['features']['reference']);
        $this->assertCount(30, array_filter($rows, fn ($row) => $row['label'] === LetPredictionService::RESULT_FAIL));
    }

    public function test_a_missing_file_is_reported(): void
    {
        $dataset = new LetBaseDataset('/nowhere/lle_clean.arff');

        $this->assertFalse($dataset->exists());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The base dataset is missing.');
        $dataset->rows();
    }

    public function test_a_file_without_the_model_s_attributes_is_refused(): void
    {
        $dataset = new LetBaseDataset($this->arff("@RELATION x\n@ATTRIBUTE score numeric\n@ATTRIBUTE board_result {FAILED,PASSED}\n@DATA\n50,PASSED\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not have the model\'s attributes');
        $dataset->rows();
    }

    public function test_short_rows_and_rows_without_a_result_are_skipped_and_question_marks_are_missing(): void
    {
        $dataset = new LetBaseDataset($this->arff($this->header()
            . "50,50,50,50,50,50,50,PASSED\n"
            . "50,50,50\n"
            . "40,40,40,40,40,40,40,?\n"
            . "30,?,30,30,30,30,30,'FAILED'\n"));

        $rows = $dataset->rows();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[1]['features']['management']);
        $this->assertSame(LetPredictionService::RESULT_FAIL, $rows[1]['label']);
        // Read once, then kept.
        $this->assertSame($rows, $dataset->rows());
    }
}
