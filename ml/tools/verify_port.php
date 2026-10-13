<?php

/**
 * Checks that the tree written into LetPredictionService answers as the Weka
 * model file does, on the cases GenerateTestVectors.java asked the real model
 * about: the same outcome, and the same confidence, including where scores
 * are missing (? in the file).
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\MachineLearning\LetPredictionService;

$csvPath = __DIR__ . '/test_vectors.csv';
$rows = array_map('str_getcsv', file($csvPath));
$header = array_shift($rows);
$wekaColIndex = array_search('weka_prediction', $header);
$confidenceIndex = array_search('weka_confidence', $header);
$featureNames = array_slice($header, 0, $wekaColIndex);

$service = new LetPredictionService();
$mismatches = [];
$total = 0;
$withMissing = 0;

foreach ($rows as $row) {
    if (count($row) < count($header)) continue;
    $total++;

    $features = [];
    foreach ($featureNames as $i => $name) {
        $features[$name] = is_numeric($row[$i]) ? (float) $row[$i] : null;
    }
    $withMissing += in_array(null, $features, true) ? 1 : 0;
    $expected = trim($row[$wekaColIndex]);
    $expectedConfidence = (float) $row[$confidenceIndex];

    // The built-in tree specifically: this checks the port of the Weka model,
    // and runs without the app (or its database) started.
    $result = $service->predict($features, LetPredictionService::builtInTree());

    if ($result['prediction'] !== $expected || abs($result['confidence'] - $expectedConfidence) > 0.1) {
        $mismatches[] = [
            'features' => $features,
            'expected' => "{$expected} at {$expectedConfidence}%",
            'actual' => "{$result['prediction']} at {$result['confidence']}%",
            'path' => $result['path'],
        ];
    }
}

echo "Total cases: {$total} ({$withMissing} with a missing score)\n";
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
