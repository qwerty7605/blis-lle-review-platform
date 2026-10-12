<?php

/**
 * Checks DecisionTreeTrainer against Weka's J48 on the same data.
 *
 *   php ml/tools/compare_with_weka.php generate   writes sample datasets to ml/tools/compare/
 *   (run CompareWithWeka.java over them -- see ml/README.md)
 *   php ml/tools/compare_with_weka.php check      trains each in PHP and compares the two trees
 *
 * The datasets are made up, with the same 15 features the real model uses and
 * outcomes that follow a rule with some noise, at several sizes.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\MachineLearning\DecisionTreeTrainer;
use App\Services\MachineLearning\LetPredictionService;

$directory = __DIR__ . '/compare';
$attributes = LetPredictionService::requiredFeatures();
$mode = $argv[1] ?? 'check';

if ($mode === 'generate') {
    if (!is_dir($directory)) {
        mkdir($directory);
    }

    foreach ([[30, 11, 0.05], [60, 23, 0.1], [150, 37, 0.15], [500, 41, 0.2], [500, 97, 0.05], [1000, 53, 0.25]] as [$size, $seed, $noise]) {
        mt_srand($seed);
        $handle = fopen("{$directory}/n{$size}_s{$seed}.csv", 'w');
        fputcsv($handle, [...$attributes, 'result']);

        for ($row = 0; $row < $size; $row++) {
            $features = [];
            foreach ($attributes as $attribute) {
                $features[$attribute] = round(45 + mt_rand(0, 5000) / 100, 2);
            }

            $pass = $features['mock_total'] > 62
                ? ($features['sem8'] > 60 || $features['mock_catclass'] > 80)
                : ($features['mock_reference'] > 85 && $features['sem3'] > 70);

            if (mt_rand(0, 9999) / 10000 < $noise) {
                $pass = !$pass;
            }

            fputcsv($handle, [...array_values($features), $pass ? 'PASS' : 'FAIL']);
        }

        fclose($handle);
    }

    echo "wrote sample datasets to {$directory}\n";
    exit(0);
}

$render = function (array $node, int $depth = 0) use (&$render): string {
    $leaf = static function (array $leaf): string {
        $counts = number_format($leaf['instances'], 1, '.', '');
        if ($leaf['errors'] > 0) {
            $counts .= '/' . number_format($leaf['errors'], 1, '.', '');
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

foreach ($files as $file) {
    $rows = array_map('str_getcsv', file($file, FILE_IGNORE_NEW_LINES));
    $header = array_shift($rows);
    $data = [];

    foreach ($rows as $row) {
        $values = array_combine($header, $row);
        $label = $values['result'];
        unset($values['result']);
        $data[] = ['features' => array_map('floatval', $values), 'label' => $label];
    }

    $ours = trim($render((new DecisionTreeTrainer())->train($data, $attributes)));

    $wekaFile = preg_replace('/\.csv$/', '.weka.txt', $file);
    if (!is_file($wekaFile)) {
        echo basename($file) . ": no Weka output yet -- run CompareWithWeka.java first\n";
        $different++;
        continue;
    }

    preg_match('/-{5,}\n\n?(.*?)\n\nNumber of Leaves/s', (string) file_get_contents($wekaFile), $match);
    $weka = trim($match[1] ?? '');

    if ($ours === $weka) {
        echo sprintf("%-18s same tree (%d lines)\n", basename($file), substr_count($ours, "\n") + 1);
        continue;
    }

    $different++;
    echo basename($file) . ": DIFFERENT\n--- Weka ---\n{$weka}\n--- PHP ---\n{$ours}\n";
}

echo $different === 0 && $files !== []
    ? "ALL MATCH -- the PHP trainer grows the same trees as Weka's J48.\n"
    : "{$different} dataset(s) differ.\n";

exit($different === 0 && $files !== [] ? 0 : 1);
