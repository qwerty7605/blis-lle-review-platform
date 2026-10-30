<?php

namespace Tests\Unit\MachineLearning;

use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetPredictionService;
use RuntimeException;
use Tests\TestCase;

/**
 * The base dataset every retraining starts from:
 * ml/data/LLE_dataset_all_ExamType.arff, read by attribute name.
 */
class LetBaseDatasetTest extends TestCase
{
    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lle') . '.arff';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_the_project_s_dataset_has_its_128_review_exams(): void
    {
        $rows = (new LetBaseDataset())->rows();
        $types = array_count_values(array_map(fn (array $row): string => $row['features']['exam_type'], $rows));
        $labels = array_count_values(array_column($rows, 'label'));

        $this->assertCount(128, $rows);
        $this->assertSame(['pre_test' => 85, 'mock_board' => 43], $types);
        $this->assertSame([LetPredictionService::RESULT_PASS => 101, LetPredictionService::RESULT_FAIL => 27], $labels);

        // The first row, by subject key whatever the column order.
        $this->assertSame([
            'exam_type' => 'pre_test',
            'management' => 53.0,
            'reference' => 46.0,
            'indexing' => 38.0,
            'selection' => 55.0,
            'cataloging' => 51.0,
            'it' => 51.0,
        ], $rows[0]['features']);
        $this->assertSame('base:1', $rows[0]['key']);
    }

    public function test_a_question_mark_is_a_missing_score_never_zero(): void
    {
        $rows = (new LetBaseDataset())->rows();
        $missing = array_sum(array_map(fn (array $row): int => count(array_filter($row['features'], 'is_null')), $rows));

        $this->assertSame(10, $missing);
    }

    public function test_columns_are_read_by_name_in_any_order(): void
    {
        $path = $this->file(implode("\n", [
            '@relation shuffled',
            '@attribute Information_Technology numeric',
            '@attribute Exam_Type {pretest,mockboard}',
            '@attribute Reference_Bibliography_and_User_Services numeric',
            '@attribute Library_Organization_and_Management numeric',
            '@attribute Indexing_and_Abstracting numeric',
            '@attribute Selection_and_Acquisition numeric',
            '@attribute Cataloging_and_Classification numeric',
            '@attribute LLE_Result {FAILED,PASSED}',
            '@data',
            '10,mockboard,20,30,40,50,60,FAILED',
            '1,pretest,2,3',
            '?,pretest,?,30,40,50,60,?',
        ]));

        $rows = (new LetBaseDataset($path))->rows();

        // A short row and one with no result are skipped.
        $this->assertCount(1, $rows);
        $this->assertSame(['exam_type' => 'mock_board', 'management' => 30.0, 'reference' => 20.0, 'indexing' => 40.0, 'selection' => 50.0, 'cataloging' => 60.0, 'it' => 10.0], $rows[0]['features']);
        $this->assertSame(LetPredictionService::RESULT_FAIL, $rows[0]['label']);
    }

    public function test_a_missing_file_or_one_without_the_model_s_attributes_is_refused(): void
    {
        $this->assertFalse((new LetBaseDataset('/nowhere/lle.arff'))->exists());

        try {
            (new LetBaseDataset('/nowhere/lle.arff'))->rows();
            $this->fail('A missing file was read.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(LetBaseDataset::PATH, $exception->getMessage());
        }

        $this->expectExceptionMessage('missing exam_type');
        (new LetBaseDataset($this->file("@attribute reference numeric\n@attribute LLE_Result {FAILED,PASSED}\n@data\n1,PASSED\n")))->rows();
    }
}
