<?php

namespace App\Services\MachineLearning;

use RuntimeException;

/**
 * The records the original model was trained on: `ml/data/lle_clean.arff`,
 * kept in the project as the base of every retraining. A retrained model
 * learns from these rows plus the graduates whose real board result has been
 * recorded since; it never starts again from the new results alone.
 *
 * The file is Weka's ARFF: the model's eight attributes (reference,
 * management, indexing, selection, cataloging, it, overall, board_result),
 * one review exam a student took to a line, labelled with that student's real
 * board result, `?` where a score is missing.
 */
class LetBaseDataset
{
    public const PATH = 'ml/data/lle_clean.arff';

    public const SOURCE = 'base';

    private readonly string $path;

    /** @var array<int, array{key: string, features: array<string, ?float>, label: string, source: string}>|null */
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
     * @return array<int, array{key: string, features: array<string, ?float>, label: string, source: string}>
     *
     * @throws RuntimeException when the file is missing or is not the model's dataset
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (!$this->exists()) {
            throw new RuntimeException('The base dataset is missing. Put lle_clean.arff at ' . self::PATH . '.');
        }

        $columns = [];
        $inData = false;
        $rows = [];
        $features = LetPredictionService::requiredFeatures();

        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '%')) {
                continue;
            }

            if (!$inData) {
                if (preg_match('/^@attribute\s+[\'"]?([^\s\'"]+)[\'"]?\s/i', $line, $match)) {
                    $columns[] = strtolower($match[1]);
                } elseif (preg_match('/^@data\b/i', $line)) {
                    $inData = true;

                    if (array_diff([...$features, 'board_result'], $columns) !== []) {
                        throw new RuntimeException(sprintf(
                            'The base dataset does not have the model\'s attributes. Expected %s, board_result.',
                            implode(', ', $features),
                        ));
                    }
                }

                continue;
            }

            $cells = array_map(static fn (string $cell): string => trim($cell, " \t'\""), explode(',', $line));
            if (count($cells) !== count($columns)) {
                continue;
            }

            $values = array_combine($columns, $cells);
            $result = strtoupper($values['board_result']);

            if (!in_array($result, ['PASSED', 'FAILED'], true)) {
                continue; // an examinee with no result teaches nothing
            }

            $row = [];
            foreach ($features as $feature) {
                $row[$feature] = is_numeric($values[$feature]) ? (float) $values[$feature] : null;
            }

            $rows[] = [
                'key' => self::SOURCE . ':' . (count($rows) + 1),
                'features' => $row,
                'label' => $result === 'PASSED' ? LetPredictionService::RESULT_PASS : LetPredictionService::RESULT_FAIL,
                'source' => self::SOURCE,
            ];
        }

        return $this->rows = $rows;
    }
}
