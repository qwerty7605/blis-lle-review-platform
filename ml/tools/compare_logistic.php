<?php

/*
 * Checks the app's Estimated Outcome code against WEKA 3.8.6 (see
 * ml/README.md). Run in the php container from the repo root:
 *
 *   php ml/tools/compare_logistic.php vectors   P(PASSED) for tools/logistic_vectors.csv,
 *                                               the app's model against WEKA's (to 1e-4)
 *   php ml/tools/compare_logistic.php base      the app's trainer and evaluator on the base
 *                                               dataset against models/logistic-v1.json
 *   php ml/tools/compare_logistic.php generate  made-up datasets in tools/compare/logistic_*.arff
 *   php ml/tools/compare_logistic.php check     the trainer and evaluator on those, against the
 *                                               .weka.json files LleLogistic.java compare writes
 *
 * Each prints its differences and ends "ALL MATCH" or "MISMATCH".
 */

use App\Services\MachineLearning\LetBaseDataset;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LogisticModel;
use App\Services\MachineLearning\RidgeSelector;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const TOLERANCE = 1e-4;

$command = $argv[1] ?? '';
$ml = dirname(__DIR__);
$ok = true;

function say(string $line): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/** @return list<string> mismatch descriptions */
function compareFigures(string $label, array $php, array $weka): array
{
    $problems = [];
    foreach (['roc_area', 'failed_recall', 'false_alarm_rate', 'failed_precision', 'failed_f_measure', 'accuracy', 'kappa'] as $key) {
        $difference = abs((float) $php[$key] - (float) $weka[$key]);
        say(sprintf('  %-8s %-18s php %.6f  weka %.6f  diff %.2e', $label, $key, $php[$key], $weka[$key], $difference));
        if ($difference > TOLERANCE) {
            $problems[] = "{$label} {$key}";
        }
    }
    foreach ($php['confusion'] as $key => $value) {
        if (abs($value - $weka['confusion'][$key]) > TOLERANCE) {
            $problems[] = "{$label} confusion {$key}";
        }
    }

    return $problems;
}

/** @return list<string> */
function compareTraining(array $rows, array $weka): array
{
    $problems = [];
    $started = microtime(true);
    $fit = app(RidgeSelector::class)->fit($rows);
    $wekaModel = LogisticModel::fromWekaExport($weka);
    say(sprintf('  ridge: php %s  weka %s  (%.1fs)', $fit['selection']['ridge'], $weka['ridge'], microtime(true) - $started));

    if ($fit['selection']['ridge'] !== (float) $weka['ridge']) {
        $problems[] = 'ridge';
    }

    foreach ($weka['candidates'] as $index => $candidate) {
        $php = $fit['selection']['candidates'][$index] ?? null;
        if ($php === null || abs($php['ridge'] - $candidate['ridge']) > 1e-12 || abs($php['error_rate'] - $candidate['error_rate']) > 1e-12) {
            $problems[] = "candidate {$index}";
        }
    }

    $largest = abs($fit['model']->intercept - $wekaModel->intercept);
    foreach ($wekaModel->inputs as $key => $input) {
        $largest = max($largest, abs(($fit['model']->inputs[$key]['coefficient'] ?? NAN) - $input['coefficient']));
    }
    say(sprintf('  largest coefficient difference %.2e', $largest));
    if (!($largest <= TOLERANCE)) {
        $problems[] = 'coefficients';
    }

    $started = microtime(true);
    $evaluation = app(LetModelEvaluator::class)->evaluate($rows);
    say(sprintf('  evaluation, 10 x 10 folds: %.1fs', microtime(true) - $started));

    return [
        ...$problems,
        ...compareFigures('model', $evaluation['model'], $weka['evaluation']['model']),
        ...compareFigures('zero_r', $evaluation['zero_r'], $weka['evaluation']['zero_r']),
    ];
}

/** @return list<array{key: string, features: array<string, mixed>, label: string, source: string}> */
function arffRows(string $path): array
{
    return (new LetBaseDataset($path))->rows();
}

switch ($command) {
    case 'vectors':
        $model = LogisticModel::fromWekaExport(json_decode(file_get_contents("{$ml}/models/logistic-v1.json"), true));
        $handle = fopen("{$ml}/tools/logistic_vectors.csv", 'r');
        $header = fgetcsv($handle);
        $count = 0;
        $largest = 0.0;
        $mismatches = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $features = ['exam_type' => $line[0] === 'mockboard' ? 'mock_board' : 'pre_test'];
            foreach (LogisticModel::SUBJECTS as $position => $subject) {
                $features[$subject] = $line[$position + 1] === '' ? null : (float) $line[$position + 1];
            }
            $difference = abs($model->probabilityOfPassing($features) - (float) $line[7]);
            $largest = max($largest, $difference);
            $mismatches += $difference > TOLERANCE ? 1 : 0;
            $count++;
        }

        say(sprintf('%d vectors, largest difference %.2e, %d over %.0e', $count, $largest, $mismatches, TOLERANCE));
        $ok = $mismatches === 0 && $count > 0;
        break;

    case 'base':
        $weka = json_decode(file_get_contents("{$ml}/models/logistic-v1.json"), true);
        $rows = (new LetBaseDataset())->rows();
        say(sprintf('base dataset: %d rows', count($rows)));
        $problems = compareTraining($rows, $weka);
        foreach ($problems as $problem) {
            say("  MISMATCH {$problem}");
        }
        $ok = $problems === [];
        break;

    case 'generate':
        // Made-up review exams: a pass depends on the scores and the exam
        // type, plus noise; a fifth of the scores are missing in some sets.
        $sets = [['n40_s11', 40, 11, 0.0], ['n90_s7', 90, 7, 0.1], ['n156_s23', 156, 23, 0.2], ['n300_s37', 300, 37, 0.15]];
        @mkdir("{$ml}/tools/compare", 0777, true);

        foreach ($sets as [$name, $size, $seed, $missing]) {
            mt_srand($seed);
            $lines = ["@relation {$name}", '', '@attribute Exam_Type {pretest,mockboard}'];
            foreach (['Library_Organization_and_Management', 'Reference_Bibliography_and_User_Services', 'Indexing_and_Abstracting', 'Selection_and_Acquisition', 'Cataloging_and_Classification', 'Information_Technology'] as $attribute) {
                $lines[] = "@attribute {$attribute} numeric";
            }
            $lines[] = '@attribute LLE_Result {FAILED,PASSED}';
            $lines[] = '';
            $lines[] = '@data';

            for ($i = 0; $i < $size; $i++) {
                $mock = mt_rand(0, 2) === 0;
                $ability = mt_rand(0, 1000) / 1000;
                $scores = [];
                for ($k = 0; $k < 6; $k++) {
                    $scores[] = mt_rand(0, 1000) / 1000 < $missing ? '?' : (string) max(10, min(100, round(30 + 50 * $ability + mt_rand(-150, 150) / 10 + ($mock ? 10 : 0))));
                }
                $passes = $ability + mt_rand(-300, 300) / 1000 > 0.35;
                $lines[] = ($mock ? 'mockboard' : 'pretest') . ',' . implode(',', $scores) . ',' . ($passes ? 'PASSED' : 'FAILED');
            }

            file_put_contents("{$ml}/tools/compare/logistic_{$name}.arff", implode("\n", $lines) . "\n");
            say("wrote tools/compare/logistic_{$name}.arff");
        }
        break;

    case 'check':
        $files = glob("{$ml}/tools/compare/logistic_*.arff") ?: [];
        foreach ($files as $file) {
            $wekaFile = preg_replace('/\.arff$/', '.weka.json', $file);
            if (!is_file($wekaFile)) {
                say("no WEKA result for {$file}: run LleLogistic compare first");
                $ok = false;
                continue;
            }
            say(basename($file));
            $problems = compareTraining(arffRows($file), json_decode(file_get_contents($wekaFile), true));
            foreach ($problems as $problem) {
                say("  MISMATCH {$problem}");
            }
            $ok = $ok && $problems === [];
        }
        $ok = $ok && $files !== [];
        break;

    default:
        say('usage: php ml/tools/compare_logistic.php vectors|base|generate|check');
        exit(2);
}

say($ok ? 'ALL MATCH' : 'MISMATCH');
exit($ok ? 0 : 1);
