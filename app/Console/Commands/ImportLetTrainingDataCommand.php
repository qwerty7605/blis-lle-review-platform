<?php

namespace App\Console\Commands;

use App\Models\LetTrainingRecord;
use App\Services\MachineLearning\LetPredictionService;
use Illuminate\Console\Command;

/**
 * Brings in training records that were collected outside this system -- above
 * all the original cohort the built-in tree was trained on -- so a retrained
 * model can learn from them together with this system's own graduates.
 */
class ImportLetTrainingDataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'let:import-training-data
        {file : CSV with a header row: the 15 feature columns and a result column (pass or fail)}
        {--replace : Remove previously imported records first}';

    /**
     * @var string
     */
    protected $description = 'Import past students\' records (features and real licensure result) for the Exam Outcome model to learn from';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (!is_file($path) || !is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = array_map(static fn ($name): string => strtolower(trim((string) $name)), fgetcsv($handle) ?: []);
        $features = LetPredictionService::requiredFeatures();

        $resultColumn = null;
        foreach (['result', 'outcome', 'let_result', 'lle_result', 'class'] as $name) {
            if (in_array($name, $header, true)) {
                $resultColumn = $name;
                break;
            }
        }

        $missing = array_diff($features, $header);

        if ($resultColumn === null || $missing !== []) {
            fclose($handle);
            $this->error('The file needs these columns: ' . implode(', ', $features) . ', result.');
            if ($missing !== []) {
                $this->line('Missing: ' . implode(', ', $missing));
            }

            return self::FAILURE;
        }

        $rows = [];
        $rejected = 0;

        while (($cells = fgetcsv($handle)) !== false) {
            if (count($cells) !== count($header)) {
                $rejected++;

                continue;
            }

            $row = array_combine($header, $cells);
            $result = match (strtolower(trim((string) $row[$resultColumn]))) {
                'pass', 'passed', 'p', '1' => 'pass',
                'fail', 'failed', 'f', '0' => 'fail',
                default => null,
            };

            $values = [];
            foreach ($features as $feature) {
                $value = trim((string) $row[$feature]);
                if (!is_numeric($value) || $value < 0 || $value > 100) {
                    $result = null;
                    break;
                }

                $values[$feature] = (float) $value;
            }

            if ($result === null) {
                $rejected++;

                continue;
            }

            $rows[] = ['features' => $values, 'result' => $result];
        }

        fclose($handle);

        if ($rows === []) {
            $this->error('No usable rows found.');

            return self::FAILURE;
        }

        if ($this->option('replace')) {
            LetTrainingRecord::query()->delete();
        }

        foreach ($rows as $row) {
            LetTrainingRecord::query()->create($row + ['source' => basename($path)]);
        }

        $this->info(sprintf('Imported %d record(s).', count($rows)));
        if ($rejected > 0) {
            $this->warn("Skipped {$rejected} row(s) with a missing or out-of-range value.");
        }
        $this->line('Run `php artisan let:retrain` to retrain with them once new licensure results are in.');

        return self::SUCCESS;
    }
}
