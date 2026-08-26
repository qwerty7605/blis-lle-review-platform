<?php

namespace App\Services\MachineLearning;

use InvalidArgumentException;

/**
 * LET (Licensure Examination for Teachers/Librarians) pass/fail predictor.
 *
 * This is a direct, hand-verified port of a Weka J48 (C4.5) decision tree
 * trained on `ml/models/j48_LLE.model`. The tree structure below was
 * extracted by loading the .model file with Weka in a throwaway Java
 * container and dumping J48::toString()/graph() — see
 * `ml/tools/InspectModel.java` for the exact extraction script, and
 * `ml/models/j48_LLE.model` for the original serialized model.
 *
 * IMPORTANT — data quality caveat: this model was trained on a single
 * batch of BLIS students (one cohort, 4 years of semester grades + mock
 * exam scores, labeled with their actual LET outcome). That is a very
 * small dataset for a 15-feature tree, so several leaves are backed by
 * only a handful of training instances (see the `support`/`confidence`
 * returned by predict()). Treat predictions as a rough, low-confidence
 * signal — never as a certainty, and never as the sole basis for any
 * decision about a student. As the system accumulates more mock-exam and
 * LET outcome data, retrain the model in Weka, re-run InspectModel.java
 * against the new .model file, and update self::TREE below to match.
 */
class LetPredictionService
{
    public const RESULT_PASS = 'PASS';
    public const RESULT_FAIL = 'FAIL';

    /**
     * Nested decision tree. Internal nodes: ['attribute' => string,
     * 'threshold' => float, 'le' => node, 'gt' => node] (le = "<=
     * threshold" branch, gt = "> threshold" branch). Leaf nodes:
     * ['leaf' => 'PASS'|'FAIL', 'instances' => float, 'errors' => float].
     * `instances`/`errors` are the Weka-reported training-instance counts
     * that reached that leaf (and how many of those were misclassified),
     * used only to compute a rough confidence score.
     */
    private const TREE = [
        'attribute' => 'mock_total',
        'threshold' => 57.39,
        'le' => [
            'attribute' => 'sem8',
            'threshold' => 79.83,
            'le' => ['leaf' => self::RESULT_FAIL, 'instances' => 113.0, 'errors' => 19.0],
            'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 7.0, 'errors' => 1.0],
        ],
        'gt' => [
            'attribute' => 'sem8',
            'threshold' => 86.41,
            'le' => [
                'attribute' => 'mock_catclass',
                'threshold' => 70.62,
                'le' => [
                    'attribute' => 'sem1',
                    'threshold' => 67.77,
                    'le' => [
                        'attribute' => 'sem5',
                        'threshold' => 83.51,
                        'le' => ['leaf' => self::RESULT_PASS, 'instances' => 51.0, 'errors' => 10.0],
                        'gt' => ['leaf' => self::RESULT_FAIL, 'instances' => 3.0, 'errors' => 0.0],
                    ],
                    'gt' => [
                        'attribute' => 'mock_indexabs',
                        'threshold' => 61.26,
                        'le' => [
                            'attribute' => 'mock_catclass',
                            'threshold' => 58.71,
                            'le' => ['leaf' => self::RESULT_FAIL, 'instances' => 10.0, 'errors' => 0.0],
                            'gt' => [
                                'attribute' => 'mock_it',
                                'threshold' => 60.59,
                                'le' => ['leaf' => self::RESULT_PASS, 'instances' => 6.0, 'errors' => 1.0],
                                'gt' => [
                                    'attribute' => 'mock_libmgmt',
                                    'threshold' => 69.79,
                                    'le' => ['leaf' => self::RESULT_FAIL, 'instances' => 13.0, 'errors' => 0.0],
                                    'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 4.0, 'errors' => 1.0],
                                ],
                            ],
                        ],
                        'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 79.0, 'errors' => 34.0],
                    ],
                ],
                'gt' => [
                    'attribute' => 'mock_selacq',
                    'threshold' => 72.23,
                    'le' => ['leaf' => self::RESULT_PASS, 'instances' => 33.0, 'errors' => 2.0],
                    'gt' => [
                        'attribute' => 'sem1',
                        'threshold' => 80.08,
                        'le' => [
                            'attribute' => 'sem7',
                            'threshold' => 85.18,
                            'le' => [
                                'attribute' => 'sem5',
                                'threshold' => 84.42,
                                'le' => ['leaf' => self::RESULT_PASS, 'instances' => 2.0, 'errors' => 0.0],
                                'gt' => ['leaf' => self::RESULT_FAIL, 'instances' => 2.0, 'errors' => 0.0],
                            ],
                            'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 5.0, 'errors' => 0.0],
                        ],
                        'gt' => ['leaf' => self::RESULT_FAIL, 'instances' => 3.0, 'errors' => 0.0],
                    ],
                ],
            ],
            'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 169.0, 'errors' => 13.0],
        ],
    ];

    /** Feature keys the tree can reference, in the order the training data used. */
    public static function requiredFeatures(): array
    {
        return [
            'sem1', 'sem2', 'sem3', 'sem4', 'sem5', 'sem6', 'sem7', 'sem8',
            'mock_catclass', 'mock_indexabs', 'mock_it', 'mock_reference',
            'mock_libmgmt', 'mock_selacq', 'mock_total',
        ];
    }

    /**
     * @param  array<string, int|float>  $features  Keyed by requiredFeatures();
     *   only the features actually used by the branches taken need to be
     *   present, but passing all of them is safest.
     * @return array{prediction: string, confidence: float, support: int, leaf_errors: int, path: string[]}
     */
    public function predict(array $features): array
    {
        $node = self::TREE;
        $path = [];

        while (!isset($node['leaf'])) {
            $attribute = $node['attribute'];

            if (!array_key_exists($attribute, $features) || !is_numeric($features[$attribute])) {
                throw new InvalidArgumentException("Missing or non-numeric required feature: {$attribute}");
            }

            $value = (float) $features[$attribute];
            $threshold = $node['threshold'];
            $goLeft = $value <= $threshold;

            $path[] = sprintf('%s %s %.2f (was %.2f)', $attribute, $goLeft ? '<=' : '>', $threshold, $value);

            $node = $goLeft ? $node['le'] : $node['gt'];
        }

        $instances = $node['instances'];
        $errors = $node['errors'];
        $confidence = $instances > 0
            ? round((($instances - $errors) / $instances) * 100, 1)
            : 0.0;

        return [
            'prediction' => $node['leaf'],
            'confidence' => $confidence,
            'support' => (int) round($instances),
            'leaf_errors' => (int) round($errors),
            'path' => $path,
        ];
    }
}
