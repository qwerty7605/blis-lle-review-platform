<?php

namespace App\Services\MachineLearning;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Support\LleTaxonomy;
use App\Support\SchoolCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Turns a student's battery exams into what the Estimated Outcome model can
 * learn from: one row per semester, with the six subject scores of every
 * battery exam they sat in it. A battery exam covers all six board subjects
 * (one question set per subject), and LNU gives each year level one a
 * semester.
 *
 *  - A semester is the term its exams are filed under (exams.school_year and
 *    exams.semester, from their start date by the school calendar), so a
 *    battery split over two exams in one semester still makes one row.
 *  - A subject's score is the percent of its items the student got right,
 *    over every item they were dealt in that semester's battery exams: each
 *    exam by its latest submitted sitting, a blank counted wrong, as the exam
 *    itself was scored. An item's subject is its question's, as the subject
 *    scores in analytics read it.
 *  - Complete means all six subjects have at least one item.
 *  - Only the 3rd year on counts: year level then = the semester's school
 *    year - admission year + 1 (an irregular student's 5th year counts too).
 *    In the 1st and 2nd years most subjects have not been taught yet, so those
 *    scores say little about the LLE and would blur what the later ones say.
 *    A student with no admission year on file gives no battery rows.
 *
 * $until leaves out anything submitted later: a graduate's battery exams
 * must have been sat before their LLE date.
 */
class BatterySemesterFeatureBuilder
{
    /** The first year level whose battery exams the model learns from. */
    public const MIN_YEAR_LEVEL = 3;

    /**
     * Every semester the student sat a battery exam in from their 3rd year
     * on, oldest first.
     *
     * @return list<array{school_year: int, semester: int, label: string, year_level: int, scores: array<string, ?float>, items: array<string, int>, missing: list<string>, complete: bool, exam_ids: list<int>, last_submitted_at: string}>
     */
    public function semestersFor(User $student, ?CarbonInterface $until = null): array
    {
        if ($student->admission_year === null) {
            return [];
        }

        $attempts = ExamAttempt::latestSubmittedPerExam($student->id, $until)
            ->load('exam:id,exam_type,school_year,semester');

        $semesters = [];
        $attemptSemester = [];

        foreach ($attempts as $attempt) {
            $exam = $attempt->exam;

            if (!$exam || $exam->exam_type !== Exam::TYPE_BATTERY || !$exam->school_year || !$exam->semester) {
                continue;
            }

            $yearLevel = (int) $exam->school_year - (int) $student->admission_year + 1;

            if ($yearLevel < self::MIN_YEAR_LEVEL) {
                continue;
            }

            $key = sprintf('%d-%d', $exam->school_year, $exam->semester);
            $semesters[$key] ??= [
                'school_year' => (int) $exam->school_year,
                'semester' => (int) $exam->semester,
                'year_level' => $yearLevel,
                'correct' => array_fill_keys(LogisticModel::SUBJECTS, 0),
                'items' => array_fill_keys(LogisticModel::SUBJECTS, 0),
                'exam_ids' => [],
                'last_submitted_at' => '',
            ];
            $semesters[$key]['exam_ids'][] = (int) $exam->id;
            $semesters[$key]['last_submitted_at'] = max($semesters[$key]['last_submitted_at'], $attempt->submitted_at->toIso8601String());
            $attemptSemester[(int) $attempt->id] = $key;
        }

        if ($semesters === []) {
            return [];
        }

        $items = DB::table('exam_attempt_questions')
            ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_questions.question_bank_question_id')
            ->leftJoin('topics', 'topics.id', '=', 'question_bank_questions.topic_id')
            ->leftJoin('exam_attempt_answers', function ($join): void {
                $join->on('exam_attempt_answers.exam_attempt_id', '=', 'exam_attempt_questions.exam_attempt_id')
                    ->on('exam_attempt_answers.question_bank_question_id', '=', 'exam_attempt_questions.question_bank_question_id');
            })
            ->whereIn('exam_attempt_questions.exam_attempt_id', array_keys($attemptSemester))
            ->get([
                'exam_attempt_questions.exam_attempt_id',
                'question_bank_questions.subject',
                'topics.subject as topic_subject',
                'exam_attempt_answers.is_correct',
            ]);

        foreach ($items as $item) {
            $subject = trim((string) ($item->subject ?? '')) ?: trim((string) ($item->topic_subject ?? ''));
            $subjectKey = LleTaxonomy::subjectKey($subject);

            // An item outside the six board subjects informs no input.
            if ($subjectKey === null || !in_array($subjectKey, LogisticModel::SUBJECTS, true)) {
                continue;
            }

            $key = $attemptSemester[(int) $item->exam_attempt_id];
            $semesters[$key]['items'][$subjectKey]++;
            $semesters[$key]['correct'][$subjectKey] += (bool) $item->is_correct ? 1 : 0;
        }

        $firstMonth = app(SchoolCalendar::class)->startMonths()['first'];

        $result = array_map(static function (array $entry) use ($firstMonth): array {
            $scores = [];
            foreach (LogisticModel::SUBJECTS as $subject) {
                $scores[$subject] = $entry['items'][$subject] > 0
                    ? round($entry['correct'][$subject] / $entry['items'][$subject] * 100, 2)
                    : null;
            }

            // In the Table of Specifications' order, as scores are shown.
            $missing = array_values(array_filter(LleTaxonomy::keys(), static fn (string $key): bool => ($scores[$key] ?? null) === null));

            return [
                'school_year' => $entry['school_year'],
                'semester' => $entry['semester'],
                'label' => SchoolCalendar::label($entry['school_year'], $entry['semester'], $firstMonth),
                'year_level' => $entry['year_level'],
                'scores' => $scores,
                'items' => $entry['items'],
                'missing' => array_map(static fn (string $key): string => (string) LleTaxonomy::subjectName($key), $missing),
                'complete' => $missing === [],
                'exam_ids' => $entry['exam_ids'],
                'last_submitted_at' => $entry['last_submitted_at'],
            ];
        }, array_values($semesters));

        usort($result, static fn (array $left, array $right): int => [$left['school_year'], $left['semester']] <=> [$right['school_year'], $right['semester']]);

        return $result;
    }

    /**
     * The model's inputs for a complete battery semester.
     *
     * @param  array{scores: array<string, ?float>}  $semester
     * @return array<string, mixed>
     */
    public static function features(array $semester): array
    {
        return ['exam_type' => Exam::TYPE_BATTERY] + $semester['scores'];
    }
}
