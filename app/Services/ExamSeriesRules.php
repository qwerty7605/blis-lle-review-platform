<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamSeries;
use App\Support\LleTaxonomy;

/**
 * What an exam's type and series may be.
 *
 *  - A regular mock exam or a battery exam belongs to no series; one that had
 *    a series loses it when its type changes.
 *  - A pre-test or a mock board belongs to exactly one series of the same
 *    type, draws on exactly one of the six board subjects (its question
 *    sets'), and is that series' only exam in that subject.
 */
class ExamSeriesRules
{
    /**
     * The type, series and series subject to save, or why they cannot be.
     *
     * @param  string|null  $examSubject  the exam's subject as its question sets give it (Exam::summarizeQuestionBankSubject())
     * @return array{exam_type: string, exam_series_id: ?int, series_subject: ?string}|string
     */
    public function resolve(string $type, ?int $seriesId, ?string $examSubject, ?int $examId = null): array|string
    {
        if (!Exam::isSeriesType($type)) {
            return ['exam_type' => $type, 'exam_series_id' => null, 'series_subject' => null];
        }

        $label = Exam::TYPE_LABELS[$type];

        if ($seriesId === null) {
            return sprintf('A %s is one of six subject exams in a series. Choose its series, or create one.', strtolower($label));
        }

        $series = ExamSeries::query()->find($seriesId);

        if ($series === null) {
            return 'That series no longer exists.';
        }

        if ($series->type !== $type) {
            return sprintf('"%s" is a %s series; this exam is a %s.', $series->name, strtolower($series->typeLabel()), strtolower($label));
        }

        $subjectKey = LleTaxonomy::subjectKey($examSubject);

        if ($subjectKey === null) {
            return sprintf(
                'A %s exam must draw on one board subject; this one\'s question sets are %s.',
                strtolower($label),
                filled($examSubject) ? "\"{$examSubject}\"" : 'not filed under a subject',
            );
        }

        $clash = Exam::query()
            ->where('exam_series_id', $series->id)
            ->where('series_subject', $subjectKey)
            ->when($examId !== null, fn ($query) => $query->where('id', '!=', $examId))
            ->first(['id', 'title']);

        if ($clash !== null) {
            return sprintf('"%s" already has its %s exam ("%s").', $series->name, LleTaxonomy::subjectName($subjectKey), $clash->title);
        }

        return ['exam_type' => $type, 'exam_series_id' => (int) $series->id, 'series_subject' => $subjectKey];
    }
}
