<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\MachineLearning\LetPredictionService;

$csvPath = __DIR__ . '/test_vectors.csv';
$rows = array_map('str_getcsv', file($csvPath));
$header = array_shift($rows);
$wekaColIndex = array_search('weka_prediction', $header);
$featureNames = array_slice($header, 0, $wekaColIndex);

$service = new LetPredictionService();
$mismatches = [];
$total = 0;

foreach ($rows as $row) {
    if (count($row) < count($header)) continue;
    $total++;

    $features = [];
    foreach ($featureNames as $i => $name) {
        $features[$name] = (float) $row[$i];
    }
    $expected = trim($row[$wekaColIndex]);

    $result = $service->predict($features);
    $actual = $result['prediction'];

    if ($actual !== $expected) {
        $mismatches[] = [
            'features' => $features,
            'expected' => $expected,
            'actual' => $actual,
            'path' => $result['path'],
        ];
    }
}

echo "Total cases: {$total}\n";
echo "Mismatches: " . count($mismatches) . "\n";

if (count($mismatches) > 0) {
    foreach ($mismatches as $m) {
        echo "---\n";
        echo "Expected: {$m['expected']}, Got: {$m['actual']}\n";
        echo "Features: " . json_encode($m['features']) . "\n";
        echo "Path taken: " . implode(' -> ', $m['path']) . "\n";
    }
    exit(1);
}

echo "ALL MATCH — PHP port is faithful to the Weka model.\n";
exit(0);
