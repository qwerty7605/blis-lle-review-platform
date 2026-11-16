<?php

namespace App\Services\MachineLearning;

use InvalidArgumentException;

/**
 * One fitted Estimated Outcome model: WEKA 3.8.6's Logistic (ridge-penalized
 * logistic regression), as numbers the app computes from. The server has no
 * Java, so it does WEKA's arithmetic itself:
 *
 *   1. a missing input is filled with its training mean (the exam type with
 *      its training mode), as ReplaceMissingValues does;
 *   2. the exam type as NominalToBinary codes it: with two types (pre-test
 *      and mock board, every version trained without battery rows) one 0/1
 *      input, 1 for a mock board; with three (battery rows in the training
 *      set) one 0/1 input per type, `exam_type=pre_test`,
 *      `exam_type=mock_board` and `exam_type=battery`;
 *   3. z = intercept + sum(coefficient x input), the coefficients being in
 *      the inputs' own units (Logistic standardized the inputs to fit, then
 *      turned the coefficients back; `mean` and `sd` are kept for the
 *      record and for the trainer);
 *   4. P(FAILED) = 1 / (1 + e^-z) and P(PASSED) = 1 / (1 + e^z), written as
 *      Logistic.evaluateProbability() writes them.
 *
 * Inputs are keyed by LleTaxonomy subject keys, plus `exam_type` or the
 * `exam_type=<type>` inputs; a prediction is asked with the feature
 * `exam_type` ('pre_test' | 'mock_board' | 'battery') and the six scores.
 */
final class LogisticModel
{
    /** Its inputs, in the order of the training file's attributes. */
    public const INPUTS = ['exam_type', 'management', 'reference', 'indexing', 'selection', 'cataloging', 'it'];

    /** The board subjects, in the same order. */
    public const SUBJECTS = ['management', 'reference', 'indexing', 'selection', 'cataloging', 'it'];

    /** P(FAILED) at or above this is "At risk". */
    public const AT_RISK_CUTOFF = 0.25;

    /** The exam types of a training set without battery rows: one 0/1 input. */
    public const REVIEW_TYPES = ['pre_test', 'mock_board'];

    /** A battery semester's exam type. */
    public const BATTERY_TYPE = 'battery';

    /** The exam types once battery rows are in the training set, in WEKA's declared order. */
    public const ALL_TYPES = ['pre_test', 'mock_board', self::BATTERY_TYPE];

    /** How an exam type's own 0/1 input is keyed: `exam_type=battery`. */
    public const TYPE_INPUT_PREFIX = 'exam_type=';

    /**
     * @param  array<string, array{coefficient: float, fill: float, mean: float, sd: float}>  $inputs  keyed by INPUTS; an input RemoveUseless dropped is absent
     */
    public function __construct(
        public readonly float $intercept,
        public readonly array $inputs,
        public readonly float $ridge,
    ) {
    }

    /**
     * @param  array<string, mixed>  $parameters  as toArray() writes them
     */
    public static function fromArray(array $parameters): self
    {
        if (!isset($parameters['intercept'], $parameters['inputs'], $parameters['ridge'])) {
            throw new InvalidArgumentException('Not the parameters of a logistic model.');
        }

        $inputs = [];
        foreach ($parameters['inputs'] as $key => $input) {
            $inputs[(string) $key] = [
                'coefficient' => (float) $input['coefficient'],
                'fill' => (float) $input['fill'],
                'mean' => (float) $input['mean'],
                'sd' => (float) $input['sd'],
            ];
        }

        return new self((float) $parameters['intercept'], $inputs, (float) $parameters['ridge']);
    }

    /**
     * A model as ml/tools/LleLogistic.java writes WEKA's (`ml/models/*.json`):
     * its `model` section, attributes by their ARFF names, and the ridge.
     *
     * @param  array<string, mixed>  $export
     */
    public static function fromWekaExport(array $export): self
    {
        $inputs = [];
        $isType = static fn (array $attribute): bool => \App\Support\LleTaxonomy::normalize((string) $attribute['name']) === 'exam type';
        // Two types: one input named for the second ("Exam_Type=mockboard");
        // three: one input per type, in the declared order.
        $oneInputPerType = count(array_filter($export['model']['attributes'], $isType)) > 1;
        $typePosition = 0;
        $typeKeys = [];

        foreach ($export['model']['attributes'] as $attribute) {
            $name = (string) $attribute['name'];
            $fill = (float) $attribute['fill_missing_with'];

            if ($isType($attribute) && $oneInputPerType) {
                $type = self::normalizeExamType($attribute['nominal_one'] ?? null);

                if ($type === null) {
                    throw new InvalidArgumentException('Unknown exam type ' . ($attribute['nominal_one'] ?? '?') . '.');
                }

                $key = self::typeInputKey($type);
                // WEKA exports the mode's position among the types; this
                // type's own input is 1 when the mode is this type.
                $fill = (int) round($fill) === $typePosition ? 1.0 : 0.0;
                $typePosition++;
                $typeKeys[] = $key;
            } else {
                $key = $isType($attribute) ? 'exam_type' : \App\Support\LleTaxonomy::subjectKey($name);
            }

            if ($key === null) {
                throw new InvalidArgumentException("Unknown model attribute {$name}.");
            }

            $inputs[$key] = [
                'coefficient' => (float) $attribute['coefficient'],
                'fill' => $fill,
                'mean' => (float) $attribute['mean'],
                'sd' => (float) $attribute['sd'],
            ];
        }

        // In the model's own input order, which is the order WEKA sums in.
        $order = $oneInputPerType ? [...$typeKeys, ...self::SUBJECTS] : self::INPUTS;
        $ordered = array_intersect_key(array_flip($order), $inputs);
        foreach ($ordered as $key => $unused) {
            $ordered[$key] = $inputs[$key];
        }

        return new self((float) $export['model']['intercept'], $ordered, (float) $export['ridge']);
    }

    /**
     * @return array{intercept: float, ridge: float, inputs: array<string, array{coefficient: float, fill: float, mean: float, sd: float}>}
     */
    public function toArray(): array
    {
        return ['intercept' => $this->intercept, 'ridge' => $this->ridge, 'inputs' => $this->inputs];
    }

    /**
     * @param  array<string, mixed>  $features  exam_type and the six subject scores (0-100); null or absent is missing
     */
    public function probabilityOfFailing(array $features): float
    {
        return 1 / (1 + exp(-$this->linear($features)));
    }

    /**
     * @param  array<string, mixed>  $features
     */
    public function probabilityOfPassing(array $features): float
    {
        return 1 / (exp($this->linear($features)) + 1);
    }

    /**
     * @param  array<string, mixed>  $features
     */
    public function isAtRisk(array $features): bool
    {
        return $this->probabilityOfFailing($features) >= self::AT_RISK_CUTOFF;
    }

    /**
     * The exam type as the two-type model's 0/1 input, or null when unknown
     * (a battery row included: a two-type model has no input for it).
     */
    public static function encodeExamType(mixed $type): ?float
    {
        return match ($type) {
            'mock_board', 'mockboard', 1, 1.0 => 1.0,
            'pre_test', 'pretest', 0, 0.0 => 0.0,
            default => null,
        };
    }

    /** An exam type under its one name ('pre_test', 'mock_board', 'battery'), or null. */
    public static function normalizeExamType(mixed $type): ?string
    {
        return match ($type) {
            'mock_board', 'mockboard' => 'mock_board',
            'pre_test', 'pretest' => 'pre_test',
            self::BATTERY_TYPE => self::BATTERY_TYPE,
            default => null,
        };
    }

    public static function typeInputKey(string $type): string
    {
        return self::TYPE_INPUT_PREFIX . $type;
    }

    /**
     * The exam types a training set is coded with: three once any of its
     * rows is a battery semester, otherwise the two the model has always had
     * (so a set without battery rows trains exactly the model it always did).
     *
     * @param  iterable<array{features: array<string, mixed>}>  $rows
     * @return list<string>
     */
    public static function examTypesFor(iterable $rows): array
    {
        foreach ($rows as $row) {
            if (self::normalizeExamType($row['features']['exam_type'] ?? null) === self::BATTERY_TYPE) {
                return self::ALL_TYPES;
            }
        }

        return self::REVIEW_TYPES;
    }

    /**
     * The exam types this version has inputs for, in the declared order: the
     * two review types for a two-type version, all three for one trained with
     * battery rows; none when the training set had a single type and
     * RemoveUseless dropped it. (Not read from the order of the stored
     * inputs: a MySQL JSON column sorts an object's keys.)
     *
     * @return list<string>
     */
    public function examTypes(): array
    {
        if (isset($this->inputs['exam_type'])) {
            return self::REVIEW_TYPES;
        }

        return array_values(array_filter(
            self::ALL_TYPES,
            fn (string $type): bool => isset($this->inputs[self::typeInputKey($type)]),
        ));
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function linear(array $features): float
    {
        $z = $this->intercept;
        $type = self::normalizeExamType($features['exam_type'] ?? null);

        foreach ($this->inputs as $key => $input) {
            if ($key === 'exam_type') {
                $value = self::encodeExamType($features['exam_type'] ?? null);
            } elseif (str_starts_with($key, self::TYPE_INPUT_PREFIX)) {
                // One input per type: 1 for the row's own, 0 for the others;
                // a type the model has no input for is missing, and every one
                // of these inputs takes its fill (the training mode's 1 or 0).
                $value = $type !== null && isset($this->inputs[self::typeInputKey($type)])
                    ? ($key === self::typeInputKey($type) ? 1.0 : 0.0)
                    : null;
            } else {
                $value = is_numeric($features[$key] ?? null) ? (float) $features[$key] : null;
            }

            $z += $input['coefficient'] * ($value ?? $input['fill']);
        }

        return $z;
    }
}
