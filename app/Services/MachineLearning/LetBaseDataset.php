<?php

namespace App\Services\MachineLearning;

use App\Support\LleTaxonomy;
use RuntimeException;

/**
 * The records the model is trained on before anything this system adds:
 * `ml/data/LLE_dataset_all_ExamType.arff`, 128 review exams (85 pre-tests,
 * 43 mock boards), each with the examinee's real LLE result. Every
 * retraining learns from all of its rows plus the graduates recorded since.
 *
 * The file is WEKA's ARFF: `Exam_Type {pretest, mockboard}`, the six subject
 * scores under their official names, and `LLE_Result {FAILED, PASSED}`;
 * `?` is a missing score. Columns are read by name (a subject by
 * LleTaxonomy, so by its key), never by position.
 */
class LetBaseDataset
{
    public const PATH = 'ml/data/LLE_dataset_all_ExamType.arff';

    public const SOURCE = 'base';

    private readonly string $path;

    /** @var list<array{key: string, features: array<string, mixed>, label: string, source: string}>|null */
    private ?array $rows = null;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path(self::PATH);
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return list<array{key: string, features: array<string, mixed>, label: string, source: string}>
     *
     * @throws RuntimeException when the file is missing or is not the model's dataset
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (!$this->exists()) {
            throw new RuntimeException('The base dataset is missing. Put LLE_dataset_all_ExamType.arff at ' . self::PATH . '.');
        }

        $columns = [];
        $inData = false;
        $rows = [];

        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '%')) {
                continue;
            }

            if (!$inData) {
                if (preg_match('/^@attribute\s+[\'"]?([^\s\'"]+)[\'"]?\s/i', $line, $match)) {
                    $columns[] = self::columnKey($match[1]);
                } elseif (preg_match('/^@data\b/i', $line)) {
                    $inData = true;
                    $missing = array_diff([...LogisticModel::INPUTS, 'result'], $columns);

                    if ($missing !== []) {
                        throw new RuntimeException('The base dataset does not have the model\'s attributes: missing ' . implode(', ', $missing) . '.');
                    }
                }

                continue;
            }

            $cells = array_map(static fn (string $cell): string => trim($cell, " \t'\""), explode(',', $line));
            if (count($cells) !== count($columns)) {
                continue;
            }

            $values = array_combine($columns, $cells);
            $result = strtoupper($values['result']);

            if (!in_array($result, ['PASSED', 'FAILED'], true)) {
                continue; // an examinee with no result teaches nothing
            }

            $features = [
                'exam_type' => match (strtolower($values['exam_type'])) {
                    'mockboard', 'mock_board' => 'mock_board',
                    'pretest', 'pre_test' => 'pre_test',
                    default => null,
                },
            ];
            foreach (LogisticModel::SUBJECTS as $subject) {
                $features[$subject] = is_numeric($values[$subject]) ? (float) $values[$subject] : null;
            }

            $rows[] = [
                'key' => self::SOURCE . ':' . (count($rows) + 1),
                'features' => $features,
                'label' => $result === 'PASSED' ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL,
                'source' => self::SOURCE,
            ];
        }

        return $this->rows = $rows;
    }

    /** What a column of the file is: exam_type, a subject key, result, or its own name. */
    private static function columnKey(string $name): string
    {
        $normalized = LleTaxonomy::normalize($name);

        return match (true) {
            $normalized === 'exam type' => 'exam_type',
            in_array($normalized, ['lle result', 'result', 'board result'], true) => 'result',
            default => LleTaxonomy::subjectKey($name) ?? $normalized,
        };
    }
}
