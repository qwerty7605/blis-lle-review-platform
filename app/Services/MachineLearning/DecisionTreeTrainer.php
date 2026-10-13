<?php

namespace App\Services\MachineLearning;

/**
 * Grows a pass/fail decision tree from numeric features the way Weka's J48
 * (C4.5) does with its options `-C 0.25 -M 2`: splits chosen by gain ratio, at
 * least two examples on each side of a split, branches pruned back (or
 * replaced by their larger half) when that is not expected to do worse.
 *
 * A score that is missing stays missing. As in C4.5, a row with no value for
 * the attribute being split on takes no part in choosing the split, and then
 * goes down both sides, in the proportion the rows with a value did. It is
 * never read as zero.
 *
 * Written here because the app has no Java to run Weka with, and a model that
 * can only be retrained on somebody's laptop does not get retrained. The
 * output is the same nested array LetPredictionService walks.
 *
 * `ml/tools/CompareWithWeka.java` trains J48 on the same files so the two can
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

    /** Weka's tolerance when it compares two numbers (Utils.SMALL). */
    private const SMALL = 1e-6;

    /** @var array<int, string> */
    private array $attributes = [];

    /** @var array<int, string> every outcome in the data, in the order ties are broken */
    private array $labels = [];

    /** @var array<string, array<int, float>> each attribute's known values across the whole data, lowest first */
    private array $seen = [];

    /**
     * @param  array<int, array{features: array<string, float|int|null>, label: string, weight?: float}>  $rows
     * @param  array<int, string>  $attributes  feature names a split may use
     * @return array<string, mixed> a node: ['attribute', 'threshold', 'le', 'gt'] or ['leaf', 'instances', 'errors']
     */
    public function train(array $rows, array $attributes): array
    {
        $this->attributes = array_values($attributes);
        $this->seen = [];

        $prepared = [];
        $labels = [];

        foreach (array_values($rows) as $row) {
            $features = [];
            foreach ($this->attributes as $attribute) {
                $value = $row['features'][$attribute] ?? null;
                $features[$attribute] = is_numeric($value) ? (float) $value : null;

                if ($features[$attribute] !== null) {
                    $this->seen[$attribute][] = $features[$attribute];
                }
            }

            $prepared[] = ['features' => $features, 'label' => (string) $row['label'], 'weight' => (float) ($row['weight'] ?? 1.0)];
            $labels[(string) $row['label']] = true;
        }

        // On a tie the label that sorts first wins, so the same data always
        // gives the same tree (and FAIL comes before PASS, as in the model's
        // own class order).
        $this->labels = array_keys($labels);
        sort($this->labels);

        foreach ($this->attributes as $attribute) {
            $this->seen[$attribute] ??= [];
            sort($this->seen[$attribute]);
        }

        $tree = $this->grow($prepared);
        $tree = $this->collapse($tree);
        $tree = $this->prune($tree);

        return $this->finish($tree);
    }

    /**
     * @param  array<int, array{features: array<string, ?float>, label: string, weight: float}>  $rows
     * @return array<string, mixed>
     */
    private function grow(array $rows): array
    {
        $counts = $this->counts($rows);
        $total = array_sum($counts);
        $node = ['rows' => $rows, 'counts' => $counts];

        if ($total < 2 * self::MIN_PER_BRANCH - self::SMALL || abs($total - max($counts ?: [0])) < self::SMALL) {
            return $node + ['leaf' => true];
        }

        $split = $this->bestSplit($rows, $total);
        if ($split === null) {
            return $node + ['leaf' => true];
        }

        // The cut found lies halfway between two neighbouring scores in this
        // node. It is moved down to a score some student really had: the
        // highest one in the whole training set that is not above it.
        $threshold = -INF;
        foreach ($this->seen[$split['attribute']] as $value) {
            if ($value > $split['threshold'] + self::SMALL) {
                break;
            }

            $threshold = $value;
        }
        $split['threshold'] = $threshold === -INF ? $split['threshold'] : $threshold;

        $node += ['leaf' => false, 'attribute' => $split['attribute'], 'threshold' => $split['threshold']];
        $node['bags'] = $this->bagWeights($rows, $split['attribute'], $split['threshold']);

        [$left, $right] = $this->divide($rows, $split['attribute'], $split['threshold'], $node['bags']);
        $node['le'] = $this->grow($left);
        $node['gt'] = $this->grow($right);

        return $node;
    }

    /**
     * The split C4.5 would take: of the attributes whose information gain is
     * at least average, the one with the highest gain ratio.
     *
     * @param  array<int, array{features: array<string, ?float>, label: string, weight: float}>  $rows
     * @return array{attribute: string, threshold: float}|null
     */
    private function bestSplit(array $rows, float $total): ?array
    {
        $candidates = [];

        foreach ($this->attributes as $attribute) {
            $candidate = $this->splitOn($rows, $attribute, $total);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $averageGain = array_sum(array_column($candidates, 'gain')) / count($candidates);
        $chosen = null;
        $bestRatio = 0.0;

        foreach ($candidates as $candidate) {
            if ($candidate['gain'] >= $averageGain - 1e-3 && $candidate['ratio'] - $bestRatio > self::SMALL) {
                $chosen = $candidate;
                $bestRatio = $candidate['ratio'];
            }
        }

        return $chosen;
    }

    /**
     * The best place to cut one attribute, judged on the rows that have a
     * value for it.
     *
     * @param  array<int, array{features: array<string, ?float>, label: string, weight: float}>  $rows
     * @return array{attribute: string, threshold: float, gain: float, ratio: float}|null
     */
    private function splitOn(array $rows, string $attribute, float $total): ?array
    {
        $known = array_values(array_filter($rows, static fn (array $row): bool => $row['features'][$attribute] !== null));
        usort($known, static fn (array $left, array $right): int => $left['features'][$attribute] <=> $right['features'][$attribute]);

        $knownCount = count($known);
        $knownCounts = $this->counts($known);
        $knownWeight = array_sum($knownCounts);

        // A large node may not shave off a sliver: each side needs a tenth of
        // the node per outcome, capped at 25 (and never under the minimum).
        $minPerBranch = 0.1 * $knownWeight / max(1, count($this->labels));
        if ($minPerBranch <= self::MIN_PER_BRANCH + self::SMALL) {
            $minPerBranch = self::MIN_PER_BRANCH;
        } elseif ($minPerBranch > 25 + self::SMALL) {
            $minPerBranch = 25;
        }

        if ($knownCount < 2 * $minPerBranch - self::SMALL) {
            return null;
        }

        $oldEntropy = $this->spread($knownCounts);
        $leftCounts = array_fill_keys($this->labels, 0.0);
        $rightCounts = $knownCounts + array_fill_keys($this->labels, 0.0);
        $leftWeight = 0.0;

        $bestGain = 0.0;
        $bestIndex = -1;
        $bestLeft = 0.0;
        $places = 0;
        $moved = 0;

        for ($next = 1; $next < $knownCount; $next++) {
            if ($known[$next - 1]['features'][$attribute] + 1e-5 >= $known[$next]['features'][$attribute]) {
                continue; // a threshold cannot fall between equal values
            }

            for (; $moved < $next; $moved++) {
                $leftCounts[$known[$moved]['label']] += $known[$moved]['weight'];
                $rightCounts[$known[$moved]['label']] -= $known[$moved]['weight'];
                $leftWeight += $known[$moved]['weight'];
            }

            $rightWeight = $knownWeight - $leftWeight;
            if ($leftWeight < $minPerBranch - self::SMALL || $rightWeight < $minPerBranch - self::SMALL) {
                continue;
            }

            // The gain among the rows with a value, scaled down by the share
            // of the node that has none.
            $gain = ($oldEntropy - $this->spread($leftCounts) - $this->spread($rightCounts)) * ($knownWeight / $total);
            $gain = abs($gain) < self::SMALL ? 0.0 : $gain / $knownWeight;

            if ($gain - $bestGain > self::SMALL) {
                $bestGain = $gain;
                $bestIndex = $next - 1;
                $bestLeft = $leftWeight;
            }

            $places++;
        }

        if ($places === 0 || $bestIndex < 0) {
            return null;
        }

        // C4.5 charges a numeric attribute for the number of places it could
        // have been cut, so that one with many distinct values does not win
        // on chance alone.
        $gain = $bestGain - log($places, 2) / $total;
        if ($gain <= self::SMALL) {
            return null;
        }

        $lower = $known[$bestIndex]['features'][$attribute];
        $upper = $known[$bestIndex + 1]['features'][$attribute];
        $threshold = ($lower + $upper) / 2;
        if ($threshold === $upper) {
            $threshold = $lower;
        }

        // The split is also charged for how it scatters the node, the rows
        // with no value counting as a group of their own.
        $splitInfo = ($this->xlogx($total)
            - $this->xlogx($bestLeft)
            - $this->xlogx($knownWeight - $bestLeft)
            - $this->xlogx($total - $knownWeight)) / log(2);

        if (abs($splitInfo) < self::SMALL) {
            return null;
        }

        return [
            'attribute' => $attribute,
            'threshold' => $threshold,
            'gain' => $gain,
            'ratio' => $gain / ($splitInfo / $total),
        ];
    }

    /**
     * How much of a node's weight lies on each side of a cut, counting only
     * the rows that have a value. Rows without one follow these proportions.
     *
     * @param  array<int, array{features: array<string, ?float>, label: string, weight: float}>  $rows
     * @return array{0: float, 1: float}
     */
    private function bagWeights(array $rows, string $attribute, float $threshold): array
    {
        $bags = [0.0, 0.0];

        foreach ($rows as $row) {
            $value = $row['features'][$attribute];

            if ($value !== null) {
                $bags[$value <= $threshold ? 0 : 1] += $row['weight'];
            }
        }

        return $bags;
    }

    /**
     * @param  array<int, array{features: array<string, ?float>, label: string, weight: float}>  $rows
     * @param  array{0: float, 1: float}  $bags
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function divide(array $rows, string $attribute, float $threshold, array $bags): array
    {
        $sides = [[], []];
        $known = $bags[0] + $bags[1];

        foreach ($rows as $row) {
            $value = $row['features'][$attribute];

            if ($value !== null) {
                $sides[$value <= $threshold ? 0 : 1][] = $row;

                continue;
            }

            foreach ([0, 1] as $side) {
                $share = $known > 0 ? $bags[$side] / $known : 0.0;

                if ($share > self::SMALL) {
                    $sides[$side][] = ['weight' => $row['weight'] * $share] + $row;
                }
            }
        }

        return $sides;
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
        if ($node['leaf']) {
            return $node;
        }

        if ($this->trainingErrors($node) >= $this->incorrect($node['counts']) - 1e-3) {
            return $this->asLeaf($node);
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
        return $node['leaf']
            ? $this->incorrect($node['counts'])
            : $this->trainingErrors($node['le']) + $this->trainingErrors($node['gt']);
    }

    /**
     * Replace a subtree with a leaf, or with its own larger branch, wherever
     * that is not expected to make more mistakes on unseen students than the
     * subtree does. The expectation is C4.5's: the mistakes made in training,
     * plus a margin that is larger the fewer examples stand behind them.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function prune(array $node): array
    {
        if ($node['leaf']) {
            return $node;
        }

        $node['le'] = $this->prune($node['le']);
        $node['gt'] = $this->prune($node['gt']);

        $largest = $node['bags'][1] > $node['bags'][0] ? 'gt' : 'le';
        $errorsLargestBranch = $this->expectedErrorsOn($node[$largest], $node['rows']);
        $errorsLeaf = $this->expectedErrorsOf($node['counts']);
        $errorsTree = $this->expectedErrors($node);

        if ($errorsLeaf <= $errorsTree + 0.1 + self::SMALL && $errorsLeaf <= $errorsLargestBranch + 0.1 + self::SMALL) {
            return $this->asLeaf($node);
        }

        // The larger branch, given every example that reached this node, is
        // expected to do as well as the two branches together: it takes
        // their place (C4.5's subtree raising).
        if ($errorsLargestBranch <= $errorsTree + 0.1 + self::SMALL) {
            return $this->prune($this->redistribute($node[$largest], $node['rows']));
        }

        return $node;
    }

    /**
     * The same subtree, with $rows sent down it in place of the examples it
     * was grown from.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function redistribute(array $node, array $rows): array
    {
        $node['rows'] = $rows;
        $node['counts'] = $this->counts($rows);

        if ($node['leaf']) {
            return $node;
        }

        $node['bags'] = $this->bagWeights($rows, $node['attribute'], $node['threshold']);
        [$left, $right] = $this->divide($rows, $node['attribute'], $node['threshold'], $node['bags']);
        $node['le'] = $this->redistribute($node['le'], $left);
        $node['gt'] = $this->redistribute($node['gt'], $right);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function expectedErrors(array $node): float
    {
        return $node['leaf']
            ? $this->expectedErrorsOf($node['counts'])
            : $this->expectedErrors($node['le']) + $this->expectedErrors($node['gt']);
    }

    /**
     * What $node would be expected to get wrong if $rows were its examples.
     *
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function expectedErrorsOn(array $node, array $rows): float
    {
        if ($node['leaf']) {
            return $this->expectedErrorsOf($this->counts($rows));
        }

        $bags = $this->bagWeights($rows, $node['attribute'], $node['threshold']);
        [$left, $right] = $this->divide($rows, $node['attribute'], $node['threshold'], $bags);

        return $this->expectedErrorsOn($node['le'], $left) + $this->expectedErrorsOn($node['gt'], $right);
    }

    /**
     * @param  array<string, float>  $counts
     */
    private function expectedErrorsOf(array $counts): float
    {
        $total = array_sum($counts);
        if (abs($total) < self::SMALL) {
            return 0.0;
        }

        $errors = $this->incorrect($counts);

        return $errors + $this->addedErrors($total, $errors);
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
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function asLeaf(array $node): array
    {
        return ['rows' => $node['rows'], 'counts' => $node['counts'], 'leaf' => true];
    }

    /**
     * The tree as it is stored: no training rows, and each leaf with the
     * outcome it gives and what stands behind it.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function finish(array $node): array
    {
        if ($node['leaf']) {
            return [
                'leaf' => $this->majority($node['counts']),
                'instances' => round(array_sum($node['counts']), 6),
                'errors' => round($this->incorrect($node['counts']), 6),
            ];
        }

        return [
            'attribute' => $node['attribute'],
            'threshold' => $node['threshold'],
            'le' => $this->finish($node['le']),
            'gt' => $this->finish($node['gt']),
        ];
    }

    /**
     * @param  array<string, float>  $counts
     */
    private function majority(array $counts): string
    {
        $label = $this->labels[0] ?? '';

        foreach ($this->labels as $candidate) {
            if (($counts[$candidate] ?? 0.0) > ($counts[$label] ?? 0.0)) {
                $label = $candidate;
            }
        }

        return $label;
    }

    /**
     * @param  array<string, float>  $counts
     */
    private function incorrect(array $counts): float
    {
        return array_sum($counts) - ($counts[$this->majority($counts)] ?? 0.0);
    }

    /**
     * The weight of each outcome among $rows.
     *
     * @param  array<int, array{label: string, weight: float}>  $rows
     * @return array<string, float>
     */
    private function counts(array $rows): array
    {
        $counts = array_fill_keys($this->labels, 0.0);

        foreach ($rows as $row) {
            $counts[$row['label']] += $row['weight'];
        }

        return $counts;
    }

    /**
     * Entropy times weight, in bits: what C4.5 adds up to compare splits.
     *
     * @param  array<string, float>  $counts
     */
    private function spread(array $counts): float
    {
        $sum = 0.0;
        foreach ($counts as $count) {
            $sum -= $this->xlogx($count);
        }

        return ($sum + $this->xlogx(array_sum($counts))) / log(2);
    }

    private function xlogx(float $value): float
    {
        return $value < self::SMALL ? 0.0 : $value * log($value);
    }
}
