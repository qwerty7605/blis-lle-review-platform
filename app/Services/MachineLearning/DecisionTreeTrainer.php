<?php

namespace App\Services\MachineLearning;

/**
 * Grows a pass/fail decision tree from numeric features, the way Weka's J48
 * (C4.5) grew the tree LetPredictionService shipped with: splits chosen by
 * gain ratio, at least two examples on each side of a split, and branches
 * pruned back when they are not expected to do better than a single leaf.
 *
 * Written here because the app has no Java to run Weka with, and a model that
 * can only be retrained on somebody's laptop does not get retrained. The
 * output is the same nested array LetPredictionService walks.
 *
 * `ml/tools/CompareWithWeka.java` trains J48 on the same file so the two can
 * be compared; see ml/README.md.
 */
class DecisionTreeTrainer
{
    /** Fewest examples allowed on either side of a split (J48's -M). */
    private const MIN_PER_BRANCH = 2;

    /** Pruning confidence (J48's -C). Lower prunes harder. */
    private const PRUNING_CONFIDENCE = 0.25;

    /** The normal deviate for PRUNING_CONFIDENCE, i.e. normalInverse(0.75). */
    private const PRUNING_Z = 0.6744897501960817;

    /**
     * @param  array<int, array{features: array<string, float|int>, label: string}>  $rows
     * @param  array<int, string>  $attributes  feature names a split may use
     * @return array<string, mixed> a node: ['attribute', 'threshold', 'le', 'gt'] or ['leaf', 'instances', 'errors']
     */
    public function train(array $rows, array $attributes): array
    {
        $rows = array_values($rows);

        // Every score seen for each feature, lowest first: a cut is always
        // placed at one of them (see bestSplit()).
        $seen = [];
        foreach ($attributes as $attribute) {
            $values = array_map(static fn (array $row): float => (float) $row['features'][$attribute], $rows);
            sort($values);
            $seen[$attribute] = $values;
        }

        return $this->prune($this->collapse($this->grow($rows, $attributes, $seen)));
    }

    /**
     * @param  array<int, array{features: array<string, float|int>, label: string}>  $rows
     * @param  array<int, string>  $attributes
     * @param  array<string, array<int, float>>  $seen
     * @return array<string, mixed>
     */
    private function grow(array $rows, array $attributes, array $seen): array
    {
        $counts = $this->countLabels($rows);
        $total = count($rows);

        if (count($counts) <= 1 || $total < 2 * self::MIN_PER_BRANCH) {
            return $this->leaf($counts);
        }

        $split = $this->bestSplit($rows, $attributes, $counts, $seen);
        if ($split === null) {
            return $this->leaf($counts);
        }

        $left = [];
        $right = [];
        foreach ($rows as $row) {
            if ((float) $row['features'][$split['attribute']] <= $split['threshold']) {
                $left[] = $row;
            } else {
                $right[] = $row;
            }
        }

        return [
            'attribute' => $split['attribute'],
            'threshold' => $split['threshold'],
            'le' => $this->grow($left, $attributes, $seen),
            'gt' => $this->grow($right, $attributes, $seen),
            // Kept until pruning has run: what this node would be as a leaf.
            'counts' => $counts,
        ];
    }

    /**
     * The split C4.5 would take: of the attributes whose information gain is
     * at least average, the one with the highest gain ratio.
     *
     * @param  array<int, array{features: array<string, float|int>, label: string}>  $rows
     * @param  array<int, string>  $attributes
     * @param  array<string, int>  $counts
     * @param  array<string, array<int, float>>  $seen
     * @return array{attribute: string, threshold: float}|null
     */
    private function bestSplit(array $rows, array $attributes, array $counts, array $seen): ?array
    {
        $total = count($rows);
        $baseEntropy = $this->entropy($counts, $total);
        $candidates = [];

        // A large node may not shave off a sliver: each side needs a tenth of
        // the node per outcome, capped at 25 (and never under the minimum).
        $minPerBranch = min(25, max(self::MIN_PER_BRANCH, 0.1 * $total / count($counts)));

        foreach ($attributes as $attribute) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = [(float) $row['features'][$attribute], $row['label']];
            }
            usort($values, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

            $leftCounts = [];
            $best = null;
            $thresholds = 0;

            for ($index = 0; $index < $total - 1; $index++) {
                $leftCounts[$values[$index][1]] = ($leftCounts[$values[$index][1]] ?? 0) + 1;

                if ($values[$index][0] === $values[$index + 1][0]) {
                    continue; // a threshold cannot fall between equal values
                }

                $leftTotal = $index + 1;
                $rightTotal = $total - $leftTotal;
                if ($leftTotal < $minPerBranch || $rightTotal < $minPerBranch) {
                    continue;
                }

                $thresholds++;

                $rightCounts = [];
                foreach ($counts as $label => $count) {
                    $rightCounts[$label] = $count - ($leftCounts[$label] ?? 0);
                }

                $gain = $baseEntropy
                    - ($leftTotal / $total) * $this->entropy($leftCounts, $leftTotal)
                    - ($rightTotal / $total) * $this->entropy($rightCounts, $rightTotal);

                if ($best === null || $gain > $best['gain'] + 1e-12) {
                    $best = [
                        'gain' => $gain,
                        'threshold' => ($values[$index][0] + $values[$index + 1][0]) / 2,
                        'left' => $leftTotal,
                    ];
                }
            }

            if ($best === null) {
                continue;
            }

            // C4.5 charges a numeric attribute for the number of places it
            // could have been cut, so that one with many distinct values does
            // not win on chance alone.
            $gain = $best['gain'] - log($thresholds, 2) / $total;
            if ($gain <= 1e-9) {
                continue;
            }

            $leftShare = $best['left'] / $total;
            $splitInfo = -($leftShare * log($leftShare, 2) + (1 - $leftShare) * log(1 - $leftShare, 2));

            $candidates[] = [
                'attribute' => $attribute,
                'threshold' => $best['threshold'],
                'gain' => $gain,
                'ratio' => $gain / $splitInfo,
            ];
        }

        if ($candidates === []) {
            return null;
        }

        $averageGain = array_sum(array_column($candidates, 'gain')) / count($candidates);
        $chosen = null;

        foreach ($candidates as $candidate) {
            if ($candidate['gain'] < $averageGain - 1e-3) {
                continue;
            }

            if ($chosen === null || $candidate['ratio'] > $chosen['ratio'] + 1e-12) {
                $chosen = $candidate;
            }
        }

        if ($chosen === null) {
            return null;
        }

        // The cut found lies halfway between two neighbouring scores in this
        // node. It is moved down to a score some student really had: the
        // highest one in the whole training set that is not above it.
        $threshold = $chosen['threshold'];
        foreach ($seen[$chosen['attribute']] as $value) {
            if ($value > $chosen['threshold']) {
                break;
            }

            $threshold = $value;
        }

        return ['attribute' => $chosen['attribute'], 'threshold' => $threshold];
    }

    /**
     * Drop any subtree that gets no more of its own training examples right
     * than a single leaf would: its splits bought nothing.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function collapse(array $node): array
    {
        if (isset($node['leaf'])) {
            return $node;
        }

        $asLeaf = $this->leaf($node['counts']);

        if ($this->trainingErrors($node) >= $asLeaf['errors'] - 1e-3) {
            return $asLeaf;
        }

        $node['le'] = $this->collapse($node['le']);
        $node['gt'] = $this->collapse($node['gt']);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function trainingErrors(array $node): float
    {
        return isset($node['leaf'])
            ? $node['errors']
            : $this->trainingErrors($node['le']) + $this->trainingErrors($node['gt']);
    }

    /**
     * Replace a subtree with a leaf wherever the leaf is not expected to make
     * more mistakes on unseen students than the subtree does. The expectation
     * is C4.5's: the mistakes made in training, plus a margin that is larger
     * the fewer examples stand behind them.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function prune(array $node): array
    {
        if (isset($node['leaf'])) {
            return $node;
        }

        $node['le'] = $this->prune($node['le']);
        $node['gt'] = $this->prune($node['gt']);

        $asLeaf = $this->leaf($node['counts']);

        if ($this->expectedErrors($asLeaf) <= $this->expectedErrors($node) + 0.1) {
            return $asLeaf;
        }

        unset($node['counts']);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function expectedErrors(array $node): float
    {
        if (!isset($node['leaf'])) {
            return $this->expectedErrors($node['le']) + $this->expectedErrors($node['gt']);
        }

        return $node['errors'] + $this->addedErrors($node['instances'], $node['errors']);
    }

    /**
     * The pessimistic margin on top of $errors observed among $instances
     * (Weka's Stats.addErrs).
     */
    private function addedErrors(float $instances, float $errors): float
    {
        if ($errors < 1) {
            $base = $instances * (1 - self::PRUNING_CONFIDENCE ** (1 / $instances));

            if ($errors == 0) {
                return $base;
            }

            return $base + $errors * ($this->addedErrors($instances, 1) - $base);
        }

        if ($errors + 0.5 >= $instances) {
            return max($instances - $errors, 0);
        }

        $z = self::PRUNING_Z;
        $rate = ($errors + 0.5) / $instances;
        $upper = ($rate + ($z * $z) / (2 * $instances)
            + $z * sqrt(($rate / $instances) - ($rate * $rate / $instances) + ($z * $z / (4 * $instances * $instances))))
            / (1 + ($z * $z) / $instances);

        return ($upper * $instances) - $errors;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{leaf: string, instances: float, errors: float}
     */
    private function leaf(array $counts): array
    {
        // On a tie the label that sorts first wins, so the same data always
        // gives the same tree.
        ksort($counts);
        $label = (string) array_key_first($counts);
        foreach ($counts as $candidate => $count) {
            if ($count > $counts[$label]) {
                $label = (string) $candidate;
            }
        }

        $total = array_sum($counts);

        return [
            'leaf' => $label,
            'instances' => (float) $total,
            'errors' => (float) ($total - ($counts[$label] ?? 0)),
        ];
    }

    /**
     * @param  array<int, array{label: string}>  $rows
     * @return array<string, int>
     */
    private function countLabels(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['label']] = ($counts[$row['label']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function entropy(array $counts, int $total): float
    {
        $entropy = 0.0;

        foreach ($counts as $count) {
            if ($count > 0) {
                $share = $count / $total;
                $entropy -= $share * log($share, 2);
            }
        }

        return $entropy;
    }
}
