<?php

namespace App\Services\MachineLearning;

use App\Models\LetModel;
use Throwable;

/**
 * Librarian Licensure Examination pass/fail predictor.
 *
 * The starting model is a Weka J48 (C4.5, `-C 0.25 -M 2`) decision tree,
 * `ml/models/New_Model_LLE.model`, trained on `lle_board_exam_clean`: 156
 * review exams' scores in the six board subjects, each with the student's real
 * board result. The app has no Java to load that file with, so its tree is
 * written out below, read off the file with `ml/tools/InspectModel.java` and
 * checked against it by `ml/tools/verify_port.php`.
 *
 * The tree below is the starting point, not the last word. When an
 * administrator retrains (LetModelTrainer) and the new tree earns its place,
 * it is stored in let_models and predictions switch to it (see liveModel()).
 * self::TREE stays as version 1, to go back to.
 *
 * A prediction is a guide, never a certainty: the data behind it is small,
 * and the tree looks at two of the six subjects.
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
     * `instances`/`errors` are the Weka-reported training weight that reached
     * that leaf and how much of it was the other outcome. They are fractions
     * because a training row with a missing score is shared between branches.
     */
    private const TREE = [
        'attribute' => 'selection',
        'threshold' => 43.0,
        'le' => ['leaf' => self::RESULT_FAIL, 'instances' => 13.168831, 'errors' => 2.084416],
        'gt' => [
            'attribute' => 'it',
            'threshold' => 32.0,
            'le' => ['leaf' => self::RESULT_FAIL, 'instances' => 3.794813, 'errors' => 0.664213],
            'gt' => ['leaf' => self::RESULT_PASS, 'instances' => 139.036356, 'errors' => 15.784985],
        ],
    ];

    /** How many records the built-in tree was trained on (the sum of its leaves). */
    public const BUILT_IN_TRAINING_COUNT = 156;

    /** The version number of the built-in tree; retrained models count on from it. */
    public const BUILT_IN_VERSION = 1;

    /** @var array{source: string, id: ?int, version: int, trained_on: int, trained_at: ?string, tree: array<string, mixed>}|null */
    private ?array $liveModel = null;

    /**
     * The model's attributes, in the order of the training file:
     * reference, management, indexing, selection, cataloging, it, overall.
     *
     * @return array<int, string>
     */
    public static function requiredFeatures(): array
    {
        return [...array_keys(LetReadinessFeatureBuilder::SUBJECTS), 'overall'];
    }

    /**
     * The tree the system shipped with, whatever is live now.
     *
     * @return array<string, mixed>
     */
    public static function builtInTree(): array
    {
        return self::TREE;
    }

    /**
     * The model predictions are made with: the retrained tree that is active,
     * or the built-in one when there is none.
     *
     * A failed lookup falls back to the built-in tree rather than failing the
     * page. Migrations are run by hand on the server, so code that reads
     * let_models can be live before the table is.
     *
     * @return array{source: string, id: ?int, version: int, trained_on: int, trained_at: ?string, tree: array<string, mixed>}
     */
    public function liveModel(): array
    {
        if ($this->liveModel !== null) {
            return $this->liveModel;
        }

        try {
            $model = LetModel::query()->active()->latest('id')->first();
        } catch (Throwable) {
            $model = null;
        }

        return $this->liveModel = $model
            ? [
                'source' => 'retrained',
                'id' => (int) $model->id,
                'version' => (int) $model->version,
                'trained_on' => (int) $model->training_count,
                'trained_at' => $model->created_at?->toIso8601String(),
                'tree' => $model->tree,
            ]
            : [
                'source' => 'built_in',
                'id' => null,
                'version' => self::BUILT_IN_VERSION,
                'trained_on' => self::BUILT_IN_TRAINING_COUNT,
                'trained_at' => null,
                'tree' => self::TREE,
            ];
    }

    /**
     * @param  array<string, int|float|null>  $features  Keyed by
     *   requiredFeatures(). A score that is absent or null is missing, and is
     *   treated as Weka treats `?`: where the tree asks for it, the case goes
     *   down both branches in the proportion the training data did, and the
     *   outcomes found are weighed together. It is never read as zero.
     * @param  array<string, mixed>|null  $tree  a particular tree to walk; the live model's when omitted
     * @return array{prediction: string, confidence: float, support: int, leaf_errors: int, path: string[]}
     */
    public function predict(array $features, ?array $tree = null): array
    {
        $shares = [self::RESULT_FAIL => 0.0, self::RESULT_PASS => 0.0];
        $path = [];
        $reached = ['instances' => 0.0, 'errors' => 0.0];

        $this->descend($tree ?? $this->liveModel()['tree'], $features, 1.0, $shares, $path, $reached);

        // On a tie the first outcome in the model's class order: FAILED.
        $prediction = $shares[self::RESULT_PASS] > $shares[self::RESULT_FAIL] ? self::RESULT_PASS : self::RESULT_FAIL;
        $total = array_sum($shares);

        return [
            'prediction' => $prediction,
            'confidence' => $total > 0 ? round($shares[$prediction] / $total * 100, 1) : 0.0,
            'support' => (int) round($reached['instances']),
            'leaf_errors' => (int) round($reached['errors']),
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, int|float|null>  $features
     * @param  array<string, float>  $shares
     * @param  array<int, string>  $path
     * @param  array{instances: float, errors: float}  $reached
     */
    private function descend(array $node, array $features, float $weight, array &$shares, array &$path, array &$reached): void
    {
        if (isset($node['leaf'])) {
            $instances = (float) $node['instances'];
            $right = $instances > 0 ? ($instances - (float) $node['errors']) / $instances : 0.0;
            $other = $node['leaf'] === self::RESULT_PASS ? self::RESULT_FAIL : self::RESULT_PASS;

            $shares[$node['leaf']] += $weight * $right;
            $shares[$other] += $weight * ($instances > 0 ? 1 - $right : 0.0);
            $reached['instances'] += $weight * $instances;
            $reached['errors'] += $weight * (float) $node['errors'];

            return;
        }

        $attribute = $node['attribute'];
        $value = $features[$attribute] ?? null;

        if (!is_numeric($value)) {
            $below = $this->weightUnder($node['le']);
            $above = $this->weightUnder($node['gt']);
            $path[] = sprintf('%s is missing (both sides of %s weighed)', $attribute, $this->number($node['threshold']));

            if ($below + $above > 0) {
                $this->descend($node['le'], $features, $weight * $below / ($below + $above), $shares, $path, $reached);
                $this->descend($node['gt'], $features, $weight * $above / ($below + $above), $shares, $path, $reached);
            }

            return;
        }

        $goLeft = (float) $value <= (float) $node['threshold'];
        $path[] = sprintf('%s %s %s (was %s)', $attribute, $goLeft ? '<=' : '>', $this->number($node['threshold']), $this->number($value));

        $this->descend($goLeft ? $node['le'] : $node['gt'], $features, $weight, $shares, $path, $reached);
    }

    /**
     * How much training weight ended up under a node.
     *
     * @param  array<string, mixed>  $node
     */
    private function weightUnder(array $node): float
    {
        return isset($node['leaf'])
            ? (float) $node['instances']
            : $this->weightUnder($node['le']) + $this->weightUnder($node['gt']);
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
