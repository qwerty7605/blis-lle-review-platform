<?php

namespace App\Services\MachineLearning;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Support\LleTaxonomy;
use Carbon\CarbonInterface;

/**
 * Turns a student's exams into what the Estimated Outcome model reads: the
 * six subject scores of one pre-test or mock board series, and its type.
 *
 * A series is complete for a student once they have a submitted sitting of
 * each of its six subject exams; an exam sat twice counts by its latest
 * sitting, as everywhere else. Battery and regular mock exams never feed the
 * model. Only exams taken in this system are read, so a transferee is treated
 * like anyone else.
 */
class ReviewSeriesFeatureBuilder
{
    public const LOCKED_MESSAGE = 'Your Estimated Outcome appears after you finish all six subjects of a pre-test or mock board.';

    /**
     * Every pre-test and mock board series the student has sat at least one
     * subject of, most recently active first.
     *
     * $until leaves out anything submitted later: a graduate's series must
     * have been finished before their LLE date.
     *
     * @return list<array{series_id: int, name: string, type: string, type_label: string, scores: array<string, ?float>, done: int, required: int, missing: list<string>, complete: bool, completed_at: ?string, last_submitted_at: string}>
     */
    public function seriesFor(User $student, ?CarbonInterface $until = null): array
    {
        $attempts = ExamAttempt::latestSubmittedPerExam($student->id, $until)
            ->load('exam:id,exam_type,exam_series_id,series_subject', 'exam.series:id,name,type');

        $series = [];

        foreach ($attempts as $attempt) {
            $exam = $attempt->exam;

            if (!$exam || !Exam::isSeriesType($exam->exam_type) || !$exam->series || !$exam->series_subject) {
                continue;
            }

            $id = (int) $exam->series->id;
            $series[$id] ??= [
                'series_id' => $id,
                'name' => $exam->series->name,
                'type' => $exam->series->type,
                'type_label' => $exam->series->typeLabel(),
                'scores' => array_fill_keys(LogisticModel::SUBJECTS, null),
                'last_submitted_at' => '',
            ];

            $series[$id]['scores'][$exam->series_subject] = $attempt->score_percent === null ? null : (float) $attempt->score_percent;
            $series[$id]['last_submitted_at'] = max($series[$id]['last_submitted_at'], $attempt->submitted_at->toIso8601String());
        }

        $result = array_map(function (array $entry): array {
            // In the Table of Specifications' order, as the scores are shown.
            $missing = array_values(array_filter(LleTaxonomy::keys(), static fn (string $key): bool => $entry['scores'][$key] === null));
            $complete = $missing === [];

            return $entry + [
                'done' => count(LogisticModel::SUBJECTS) - count($missing),
                'required' => count(LogisticModel::SUBJECTS),
                'missing' => array_map(static fn (string $key): string => (string) LleTaxonomy::subjectName($key), $missing),
                'complete' => $complete,
                // The day its last subject was submitted.
                'completed_at' => $complete ? $entry['last_submitted_at'] : null,
            ];
        }, array_values($series));

        usort($result, static fn (array $left, array $right): int => strcmp($right['last_submitted_at'], $left['last_submitted_at']) ?: $right['series_id'] <=> $left['series_id']);

        return $result;
    }

    /**
     * The model's inputs for a complete series.
     *
     * @param  array{type: string, scores: array<string, ?float>}  $series
     * @return array<string, mixed>
     */
    public static function features(array $series): array
    {
        return ['exam_type' => $series['type']] + $series['scores'];
    }
}
