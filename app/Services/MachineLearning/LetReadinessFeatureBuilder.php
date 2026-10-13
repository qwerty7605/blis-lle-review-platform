<?php

namespace App\Services\MachineLearning;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Turns a student's exams into what the board exam model reads: one score in
 * each of the six board subjects, from one semester, and their weighted
 * average.
 *
 * A semester is the school year and semester each exam is filed under
 * (exams.school_year, exams.semester). Within it an exam counts once, by its
 * latest sitting, and a subject's score is the average of its exams. A
 * semester is complete when all six subjects have a score, and only a
 * complete semester is ever predicted from or learned from.
 *
 * Only exams taken in this system are read, so a transferee is treated like
 * anyone else: nothing from a previous school is known here.
 */
class LetReadinessFeatureBuilder
{
    /** The model's subject attributes, in its own order, and the subject each one is. */
    public const SUBJECTS = [
        'reference' => 'Reference Services',
        'management' => 'Library Management',
        'indexing' => 'Indexing and Abstracting',
        'selection' => 'Selection and Acquisition',
        'cataloging' => 'Cataloging and Classification',
        'it' => 'Information Technology',
    ];

    /** Each subject's share of `overall`. */
    public const WEIGHTS = [
        'reference' => 0.20,
        'management' => 0.20,
        'indexing' => 0.15,
        'selection' => 0.15,
        'cataloging' => 0.20,
        'it' => 0.10,
    ];

    public const LOCKED_MESSAGE = 'Complete all 6 subject exams this semester to unlock your result.';

    /**
     * The same subject spellings the student analytics page folds together
     * (see $buildStudentAnalytics in routes/api/student.php).
     */
    private const SUBJECT_ALIASES = [
        'cataloging and classification' => 'Cataloging and Classification',
        'cataloguing and classification' => 'Cataloging and Classification',
        'cataloging' => 'Cataloging and Classification',
        'indexing and abstracting' => 'Indexing and Abstracting',
        'indexing' => 'Indexing and Abstracting',
        'information technology' => 'Information Technology',
        'it' => 'Information Technology',
        'reference' => 'Reference Services',
        'reference services' => 'Reference Services',
        'library management' => 'Library Management',
        'selection and acquisition' => 'Selection and Acquisition',
        'selection' => 'Selection and Acquisition',
    ];

    /**
     * Every semester the student has a graded exam in, oldest first.
     *
     * $until leaves out anything submitted later. A graduate's record has to
     * stop at the day they sat the board exam: exams taken afterwards (by
     * someone preparing to retake it, say) are not what the result was
     * earned on.
     *
     * @return array<int, array{school_year: int, semester: int, label: string, scores: array<string, ?float>, complete: bool, missing: array<int, string>}>
     */
    public function semesters(User $student, ?CarbonInterface $until = null): array
    {
        $attempts = ExamAttempt::query()
            ->with(['exam:id,subject,question_bank_id,school_year,semester', 'exam.questionBank:id,subject', 'exam.questionBanks:id,subject'])
            ->where('user_id', $student->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->whereNotNull('score_percent')
            ->when($until !== null, fn ($query) => $query->where('submitted_at', '<=', $until))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get()
            // Newest first, so the first sitting met for an exam is its latest.
            ->unique('exam_id');

        $subjectKeys = array_flip(self::SUBJECTS);
        $collected = [];

        foreach ($attempts as $attempt) {
            $exam = $attempt->exam;
            $key = $exam ? ($subjectKeys[$this->subjectOf($exam)] ?? null) : null;

            if ($key === null) {
                continue; // not one of the six board subjects
            }

            $term = $exam->school_year && $exam->semester
                ? ['school_year' => (int) $exam->school_year, 'semester' => (int) $exam->semester]
                : Exam::termForDate($attempt->submitted_at);

            $collected[$term['school_year'] * 10 + $term['semester']][$key][] = (float) $attempt->score_percent;
        }

        ksort($collected);
        $semesters = [];

        foreach ($collected as $order => $bySubject) {
            $scores = [];
            foreach (array_keys(self::SUBJECTS) as $key) {
                $scores[$key] = isset($bySubject[$key])
                    ? round(array_sum($bySubject[$key]) / count($bySubject[$key]), 2)
                    : null;
            }

            $missing = array_keys(array_filter($scores, static fn (?float $score): bool => $score === null));
            $schoolYear = intdiv($order, 10);

            $semesters[] = [
                'school_year' => $schoolYear,
                'semester' => $order % 10,
                'label' => Exam::termLabel($schoolYear, $order % 10),
                'scores' => $scores,
                'complete' => $missing === [],
                'missing' => array_map(static fn (string $key): string => self::SUBJECTS[$key], $missing),
            ];
        }

        return $semesters;
    }

    /**
     * The model's attributes for a set of subject scores: the six scores as
     * they are (a subject with none stays null, i.e. missing) and `overall`.
     *
     * @param  array<string, ?float>  $scores  keyed like SUBJECTS
     * @return array<string, ?float>
     */
    public static function features(array $scores): array
    {
        $features = [];
        foreach (array_keys(self::SUBJECTS) as $key) {
            $features[$key] = is_numeric($scores[$key] ?? null) ? (float) $scores[$key] : null;
        }

        return $features + ['overall' => self::overall($features)];
    }

    /**
     * The weighted average of the subjects that have a score, the weights of
     * the others left out of the division so that a missing subject does not
     * pull the average down.
     *
     * @param  array<string, ?float>  $scores
     */
    public static function overall(array $scores): ?float
    {
        $sum = 0.0;
        $weight = 0.0;

        foreach (self::WEIGHTS as $key => $share) {
            if (is_numeric($scores[$key] ?? null)) {
                $sum += $share * (float) $scores[$key];
                $weight += $share;
            }
        }

        return $weight > 0 ? round($sum / $weight, 2) : null;
    }

    private function subjectOf(Exam $exam): string
    {
        $subject = trim((string) ($exam->subject ?? ''));
        if ($subject === '') {
            $subject = (string) (Exam::summarizeQuestionBankSubject($exam->resolvedQuestionBanks()) ?? '');
        }

        $normalized = Str::of($subject)->lower()->replace('&', 'and')->replace('/', ' ')->replace('-', ' ')->squish()->toString();

        return self::SUBJECT_ALIASES[$normalized] ?? $subject;
    }
}
