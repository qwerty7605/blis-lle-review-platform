<?php

namespace App\Services\MachineLearning;

use App\Models\User;

/**
 * What a student, and staff looking at that student, are shown from the board
 * exam model.
 *
 * Locked until the student has completed all six subject exams in at least
 * one semester in this system. After that the model is read the six scores of
 * the latest complete semester, never a semester with an exam still missing.
 *
 * What is said about the answer depends on the student's current year level:
 *  - years 1 to 3, an early warning: "On track" or "At risk", never the words
 *    passed or failed, since these are not the scores a student takes to the
 *    board exam;
 *  - year 4 (and above, and graduates), a board readiness prediction:
 *    "Likely to pass" or "At risk of failing".
 *
 * A transferee is treated like anyone else: the year level they are in now
 * picks the wording, and only exams taken here count.
 */
class BoardExamOutlook
{
    public const MODE_EARLY_WARNING = 'early_warning';
    public const MODE_BOARD_READINESS = 'board_readiness';

    public const STATUS_LOCKED = 'locked';
    public const STATUS_READY = 'ready';

    public const OUTCOME_ON_TRACK = 'on_track';
    public const OUTCOME_AT_RISK = 'at_risk';

    public const NOTE_EARLY_WARNING = 'Early warning only. Based on your current semester scores, not a board exam prediction.';
    public const NOTE_BOARD_READINESS = 'This prediction is based on past review-exam data and is a guide, not a guarantee.';

    /** The year level a board readiness prediction is given from. */
    public const BOARD_READINESS_FROM_YEAR = 4;

    public function __construct(
        private readonly LetReadinessFeatureBuilder $features,
        private readonly LetPredictionService $predictor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function for(User $student): array
    {
        $mode = $this->modeFor($student);
        $semesters = $this->features->semesters($student);

        $complete = null;
        foreach (array_reverse($semesters) as $semester) {
            if ($semester['complete']) {
                $complete = $semester;
                break;
            }
        }

        $payload = [
            'title' => 'Exam Outcome Model',
            'mode' => $mode,
            'mode_label' => $mode === self::MODE_BOARD_READINESS ? 'Board readiness prediction' : 'Early warning',
        ];

        if ($complete === null) {
            $latest = $semesters === [] ? null : end($semesters);

            return $payload + [
                'status' => self::STATUS_LOCKED,
                'message' => LetReadinessFeatureBuilder::LOCKED_MESSAGE,
                // How far along the semester in progress is, if there is one.
                'progress' => $latest === null ? null : [
                    'semester' => $latest['label'],
                    'completed' => count(LetReadinessFeatureBuilder::SUBJECTS) - count($latest['missing']),
                    'required' => count(LetReadinessFeatureBuilder::SUBJECTS),
                    'missing' => $latest['missing'],
                ],
            ];
        }

        $features = LetReadinessFeatureBuilder::features($complete['scores']);
        $prediction = $this->predictor->predict($features);
        $passes = $prediction['prediction'] === LetPredictionService::RESULT_PASS;

        $scores = [];
        foreach (LetReadinessFeatureBuilder::SUBJECTS as $key => $subject) {
            $scores[] = ['key' => $key, 'subject' => $subject, 'score' => $complete['scores'][$key]];
        }

        // The two lowest subjects. On equal scores the model's own subject
        // order decides, so the same scores always name the same two.
        $lowest = $scores;
        usort($lowest, static fn (array $left, array $right): int => $left['score'] <=> $right['score']);

        return $payload + [
            'status' => self::STATUS_READY,
            'outcome' => $passes ? self::OUTCOME_ON_TRACK : self::OUTCOME_AT_RISK,
            'label' => match (true) {
                $mode === self::MODE_BOARD_READINESS && $passes => 'Likely to pass',
                $mode === self::MODE_BOARD_READINESS => 'At risk of failing',
                $passes => 'On track',
                default => 'At risk',
            },
            'note' => $mode === self::MODE_BOARD_READINESS ? self::NOTE_BOARD_READINESS : self::NOTE_EARLY_WARNING,
            'semester' => [
                'school_year' => $complete['school_year'],
                'semester' => $complete['semester'],
                'label' => $complete['label'],
            ],
            'scores' => $scores,
            'overall' => $features['overall'],
            'risk_flags' => $this->riskFlags($this->predictor->liveModel()['tree'], $features),
            'focus_areas' => array_slice($lowest, 0, 2),
            'confidence' => $prediction['confidence'],
            'model_version' => $this->predictor->liveModel()['version'],
        ];
    }

    public function modeFor(User $student): string
    {
        $finished = $student->student_status === User::STUDENT_STATUS_GRADUATED;

        return $finished || (int) $student->year_level >= self::BOARD_READINESS_FROM_YEAR
            ? self::MODE_BOARD_READINESS
            : self::MODE_EARLY_WARNING;
    }

    /**
     * The subjects the model in use treats as risk indicators, where this
     * student is on the wrong side of them: every cut in the tree with a
     * FAILED leaf directly on one side. For the original model that is
     * Selection and Acquisition at or below 43, and Information Technology at
     * or below 32. They are read off the tree, so they follow a retrained one.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, ?float>  $features
     * @return array<int, array{key: string, subject: string, score: float, limit: float, rule: string}>
     */
    private function riskFlags(array $node, array $features): array
    {
        if (isset($node['leaf'])) {
            return [];
        }

        $flags = [];
        $key = $node['attribute'];
        $score = $features[$key] ?? null;
        $limit = (float) $node['threshold'];

        foreach (['le' => '≤', 'gt' => '>'] as $side => $sign) {
            $child = $node[$side];
            $onThisSide = $score !== null && ($side === 'le' ? $score <= $limit : $score > $limit);

            if ($onThisSide && ($child['leaf'] ?? null) === LetPredictionService::RESULT_FAIL) {
                $flags[] = [
                    'key' => $key,
                    'subject' => LetReadinessFeatureBuilder::SUBJECTS[$key] ?? 'Overall average',
                    'score' => (float) $score,
                    'limit' => $limit,
                    'rule' => sprintf('%s %s %s', $key, $sign, rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.')),
                ];
            }

            $flags = [...$flags, ...$this->riskFlags($child, $features)];
        }

        // A subject is flagged once, however many cuts the tree makes on it.
        return array_values(array_reduce($flags, static function (array $unique, array $flag): array {
            $unique[$flag['key']] ??= $flag;

            return $unique;
        }, []));
    }
}
