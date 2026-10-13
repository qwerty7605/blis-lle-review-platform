<?php

/**
 * Checks the app's own J48 against Weka's on the same data: the tree
 * DecisionTreeTrainer grows, and the accuracy, Kappa and FAILED recall
 * LetModelEvaluator reports for 10-fold cross-validation with seed 1.
 *
 *   php ml/tools/compare_with_weka.php generate   writes sample datasets to ml/tools/compare/
 *   (run CompareWithWeka.java over them -- see ml/README.md)
 *   php ml/tools/compare_with_weka.php check      trains each in PHP and compares
 *
 * The datasets are made up, with the model's seven attributes, some scores
 * missing, and outcomes that follow a rule with some noise, at several sizes.
 * To check a real file instead, save it as CSV (attributes, then result) in
 * ml/tools/compare/ and run the two steps after `generate`.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\LetModelEvaluator;
use App\Services\MachineLearning\LetPredictionService;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;

$directory = __DIR__ . '/compare';
$attributes = LetPredictionService::requiredFeatures();
$mode = $argv[1] ?? 'check';

if ($mode === 'generate') {
    if (!is_dir($directory)) {
        mkdir($directory);
    }

    // size, seed, noise, share of scores missing, which rule decides the outcome
    $datasets = [
        [40, 11, 0.05, 0.00, 0], [156, 23, 0.12, 0.05, 0], [156, 31, 0.18, 0.10, 1], [300, 37, 0.15, 0.08, 1],
        [500, 41, 0.20, 0.03, 2], [500, 97, 0.05, 0.15, 2], [1000, 53, 0.25, 0.06, 1], [90, 7, 0.30, 0.20, 0],
    ];

    foreach ($datasets as [$size, $seed, $noise, $missing, $rule]) {
        mt_srand($seed);
        $handle = fopen("{$directory}/n{$size}_s{$seed}.csv", 'w');
        fputcsv($handle, [...$attributes, 'result']);

        for ($row = 0; $row < $size; $row++) {
            $scores = [];
            foreach (array_keys(LetReadinessFeatureBuilder::SUBJECTS) as $subject) {
                $scores[$subject] = mt_rand(0, 9999) / 10000 < $missing ? null : (float) mt_rand(20, 95);
            }

            $features = LetReadinessFeatureBuilder::features($scores);
            $value = static fn (string $key): float => $features[$key] ?? 60.0;

            $pass = match ($rule) {
                0 => $value('selection') > 43 && $value('it') > 32,
                1 => $value('overall') > 55 ? ($value('cataloging') > 40 || $value('reference') > 70) : $value('management') > 80,
                default => $value('indexing') > 50 ? $value('selection') > 35 : ($value('it') > 70 && $value('overall') > 45),
            };

            if (mt_rand(0, 9999) / 10000 < $noise) {
                $pass = !$pass;
            }

            fputcsv($handle, [...array_map(static fn (?float $score): string => $score === null ? '?' : (string) $score, array_values($features)), $pass ? 'PASS' : 'FAIL']);
        }

        fclose($handle);
    }

    echo "wrote sample datasets to {$directory}\n";
    exit(0);
}

$count = static function (float $value): string {
    $text = (string) round($value, 2);

    return str_contains($text, '.') ? $text : $text . '.0';
};

$render = function (array $node, int $depth = 0) use (&$render, $count): string {
    $leaf = static function (array $leaf) use ($count): string {
        $counts = $count($leaf['instances']);
        if ($leaf['errors'] > 1e-6) {
            $counts .= '/' . $count($leaf['errors']);
        }

        return ": {$leaf['leaf']} ({$counts})";
    };

    if (isset($node['leaf'])) {
        return $leaf($node) . "\n";
    }

    $threshold = rtrim(rtrim(number_format($node['threshold'], 6, '.', ''), '0'), '.');
    $text = '';

    foreach (['le' => '<=', 'gt' => '>'] as $branch => $operator) {
        $text .= str_repeat('|   ', $depth) . "{$node['attribute']} {$operator} {$threshold}";
        $text .= isset($node[$branch]['leaf'])
            ? $leaf($node[$branch]) . "\n"
            : "\n" . $render($node[$branch], $depth + 1);
    }

    return $text;
};

$different = 0;
$files = glob("{$directory}/*.csv") ?: [];
$trainer = new DecisionTreeTrainer();
$evaluator = new LetModelEvaluator($trainer, new LetPredictionService());

foreach ($files as $file) {
    $rows = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);
    $data = [];

    foreach ($rows as $row) {
        $values = array_combine($header, $row);
        $label = $values['result'];
        unset($values['result']);
        $data[] = [
            'features' => array_map(static fn (string $cell): ?float => is_numeric($cell) ? (float) $cell : null, $values),
            'label' => $label,
        ];
    }

    $wekaFile = preg_replace('/\.csv$/', '.weka.txt', $file);
    if (!is_file($wekaFile)) {
        echo basename($file) . ": no Weka output yet -- run CompareWithWeka.java first\n";
        $different++;
        continue;
    }

    $wekaText = (string) file_get_contents($wekaFile);
    preg_match('/-{5,}\n\n?(.*?)\n\nNumber of Leaves/s', $wekaText, $match);
    $weka = trim($match[1] ?? '');
    preg_match('/^CV .*$/m', $wekaText, $cv);

    $ours = trim($render($trainer->train($data, $attributes)));
    $scores = $evaluator->evaluate($data, $attributes);
    $oursCv = sprintf('CV accuracy=%.2f kappa=%.4f failed_recall=%.2f', $scores['accuracy'], $scores['kappa'], $scores['failed_recall'] ?? 0);

    $sameTree = $ours === $weka;
    $sameCv = $oursCv === trim($cv[0] ?? '');

    if ($sameTree && $sameCv) {
        echo sprintf("%-18s same tree (%d lines), same %s\n", basename($file), substr_count($ours, "\n") + 1, $oursCv);
        continue;
    }

    $different++;
    echo basename($file) . ": DIFFERENT\n";
    if (!$sameTree) {
        echo "--- Weka tree ---\n{$weka}\n--- PHP tree ---\n{$ours}\n";
    }
    if (!$sameCv) {
        echo '--- Weka: ' . trim($cv[0] ?? '(none)') . "\n--- PHP:  {$oursCv}\n";
    }
}

echo $different === 0 && $files !== []
    ? "ALL MATCH -- the same trees and the same cross-validation figures as Weka's J48.\n"
    : "{$different} dataset(s) differ.\n";

exit($different === 0 && $files !== [] ? 0 : 1);
