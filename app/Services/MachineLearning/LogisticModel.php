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
 *   2. the exam type is one 0/1 input, 1 for a mock board (NominalToBinary);
 *   3. z = intercept + sum(coefficient x input), the coefficients being in
 *      the inputs' own units (Logistic standardized the inputs to fit, then
 *      turned the coefficients back; `mean` and `sd` are kept for the
 *      record and for the trainer);
 *   4. P(FAILED) = 1 / (1 + e^-z) and P(PASSED) = 1 / (1 + e^z), written as
 *      Logistic.evaluateProbability() writes them.
 *
 * Inputs are keyed by LleTaxonomy subject keys, plus `exam_type`
 * ('pre_test' | 'mock_board'); the model knows each by its key.
 */
final class LogisticModel
{
    /** Its inputs, in the order of the training file's attributes. */
    public const INPUTS = ['exam_type', 'management', 'reference', 'indexing', 'selection', 'cataloging', 'it'];

    /** The board subjects, in the same order. */
    public const SUBJECTS = ['management', 'reference', 'indexing', 'selection', 'cataloging', 'it'];

    /** P(FAILED) at or above this is "At risk". */
    public const AT_RISK_CUTOFF = 0.25;

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

        foreach ($export['model']['attributes'] as $attribute) {
            $name = (string) $attribute['name'];
            $key = \App\Support\LleTaxonomy::normalize($name) === 'exam type'
                ? 'exam_type'
                : \App\Support\LleTaxonomy::subjectKey($name);

            if ($key === null) {
                throw new InvalidArgumentException("Unknown model attribute {$name}.");
            }

            $inputs[$key] = [
                'coefficient' => (float) $attribute['coefficient'],
                'fill' => (float) $attribute['fill_missing_with'],
                'mean' => (float) $attribute['mean'],
                'sd' => (float) $attribute['sd'],
            ];
        }

        // In the model's own input order, which is the order WEKA sums in.
        $ordered = array_intersect_key(array_flip(self::INPUTS), $inputs);
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
     * The exam type as the model's 0/1 input, or null when unknown.
     */
    public static function encodeExamType(mixed $type): ?float
    {
        return match ($type) {
            'mock_board', 'mockboard', 1, 1.0 => 1.0,
            'pre_test', 'pretest', 0, 0.0 => 0.0,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $features
     */
    private function linear(array $features): float
    {
        $z = $this->intercept;

        foreach ($this->inputs as $key => $input) {
            $value = $key === 'exam_type'
                ? self::encodeExamType($features['exam_type'] ?? null)
                : (is_numeric($features[$key] ?? null) ? (float) $features[$key] : null);

            $z += $input['coefficient'] * ($value ?? $input['fill']);
        }

        return $z;
    }
}
