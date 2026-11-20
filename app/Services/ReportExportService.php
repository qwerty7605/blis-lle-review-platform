<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\User;
use App\Support\PassingMark;
use App\Support\Reports\ReportOutput;
use Illuminate\Support\Collection;

class ReportExportService
{
    /**
     * Build a room-level gradebook across all exams assigned to the room.
     *
     * @return array<string, mixed>
     */
    public function buildRoomGradebook(Room $room): array
    {
        $exams = $room->exams()
            ->select('exams.id', 'exams.title', 'exams.subject', 'exams.total_items')
            ->orderBy('exam_room.created_at')
            ->get();

        $students = $room->currentStudentMembers()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id')
            ->orderBy('users.name')
            ->get();

        $examIds = $exams->pluck('id')->map(fn ($id) => (int) $id)->all();
        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();

        $attempts = ExamAttempt::query()
            ->where('room_id', (int) $room->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->when(
                count($examIds) > 0,
                fn ($query) => $query->whereIn('exam_id', $examIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->when(
                count($studentIds) > 0,
                fn ($query) => $query->whereIn('user_id', $studentIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'exam_id',
                'user_id',
                'correct_answers',
                'score_percent',
                'submitted_at',
            ]);

        $latestAttemptsByStudentExam = $attempts
            ->groupBy(fn (ExamAttempt $attempt): string => $attempt->user_id . ':' . $attempt->exam_id)
            ->map(fn (Collection $group): ?ExamAttempt => $group->first());

        $rows = $students->map(function (User $student) use ($exams, $latestAttemptsByStudentExam): array {
            $examEntries = [];
            $scoreValues = collect();

            foreach ($exams as $exam) {
                /** @var ExamAttempt|null $attempt */
                $attempt = $latestAttemptsByStudentExam->get($student->id . ':' . $exam->id);
                $scorePercent = !is_null($attempt?->score_percent)
                    ? round((float) $attempt->score_percent, 2)
                    : null;

                if (!is_null($scorePercent)) {
                    $scoreValues->push($scorePercent);
                }

                $examEntries[] = [
                    'exam_id' => (int) $exam->id,
                    'exam_title' => (string) $exam->title,
                    'subject' => $exam->subject,
                    'total_items' => (int) ($exam->total_items ?? 0),
                    'attempt_id' => $attempt ? (int) $attempt->id : null,
                    'correct_answers' => $attempt ? (int) $attempt->correct_answers : null,
                    'score_percent' => $scorePercent,
                    'submitted_at' => $attempt?->submitted_at,
                ];
            }

            return [
                'student' => [
                    'id' => (int) $student->id,
                    'name' => (string) $student->name,
                    'email' => (string) $student->email,
                    'student_id' => $student->student_id,
                ],
                'summary' => [
                    'completed_exams' => $scoreValues->count(),
                    'average_score_percent' => $scoreValues->isNotEmpty()
                        ? round((float) $scoreValues->avg(), 2)
                        : null,
                ],
                'exam_entries' => $examEntries,
            ];
        })->values()->all();

        $allScores = collect($rows)
            ->flatMap(fn (array $row) => collect($row['exam_entries'] ?? [])
                ->map(fn (array $entry) => $entry['score_percent'] ?? null))
            ->filter(fn ($value) => !is_null($value))
            ->map(fn ($value) => (float) $value)
            ->values();

        return [
            'room' => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            // Scores are real percentages; the teacher's file adds grades
            // beside them when the students' mark is above 75.
            'passing' => PassingMark::current()->toArray(),
            'exams' => $exams->map(fn (Exam $exam) => [
                'id' => (int) $exam->id,
                'title' => (string) $exam->title,
                'subject' => $exam->subject,
                'total_items' => (int) ($exam->total_items ?? 0),
            ])->values()->all(),
            'rows' => $rows,
            'summary' => [
                'students_total' => (int) $students->count(),
                'exams_total' => (int) $exams->count(),
                'submitted_attempts' => (int) $attempts->count(),
                'average_score_percent' => $allScores->isNotEmpty()
                    ? round((float) $allScores->avg(), 2)
                    : null,
            ],
            'generated_at' => now(),
        ];
    }

    /**
     * The Gradebook's columns. Once the students' passing mark is above 75
     * each score is followed by its grade (PassingMark), the average's grade
     * taken from the average score. `kinds` says what each column holds, for
     * the Excel file's amber: a score under the mark, a grade under 75.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, kinds: array<int, string>}
     */
    public function buildRoomGradebookTable(array $gradebook): array
    {
        $mark = self::markOf($gradebook['passing'] ?? null);
        $grades = $mark->converts();

        $headers = ['Student Name', 'Student ID', 'Email', 'Exams Taken', 'Average %'];
        $kinds = ['text', 'text', 'text', 'count', 'score'];

        if ($grades) {
            $headers[] = 'Average Grade';
            $kinds[] = 'grade';
        }

        foreach (($gradebook['exams'] ?? []) as $exam) {
            $headers[] = (string) ($exam['title'] ?? 'Exam') . ' (%)';
            $kinds[] = 'score';

            if ($grades) {
                $headers[] = (string) ($exam['title'] ?? 'Exam') . ' (Grade)';
                $kinds[] = 'grade';
            }
        }

        // Blank, not "N/A": a column of numbers stays numbers when it is
        // opened or imported somewhere else.
        $cells = function (mixed $score) use ($mark, $grades): array {
            $value = is_null($score) ? '' : (float) $score;

            return $grades ? [$value, $value === '' ? '' : (float) $mark->grade($value)] : [$value];
        };

        $rows = [];

        foreach (($gradebook['rows'] ?? []) as $row) {
            $student = $row['student'] ?? [];
            $summary = $row['summary'] ?? [];
            $line = [
                (string) ($student['name'] ?? ''),
                (string) ($student['student_id'] ?? ''),
                (string) ($student['email'] ?? ''),
                (int) ($summary['completed_exams'] ?? 0),
                ...$cells($summary['average_score_percent'] ?? null),
            ];

            foreach (($row['exam_entries'] ?? []) as $entry) {
                array_push($line, ...$cells($entry['score_percent'] ?? null));
            }

            $rows[] = $line;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'kinds' => $kinds,
        ];
    }

    /**
     * The students' passing mark a report was built with (`passing` in its
     * data), so a file reads the same mark throughout. Data without one, as
     * built by hand in tests, reads as 75: no grades.
     */
    public static function markOf(mixed $passing): PassingMark
    {
        return new PassingMark((int) (is_array($passing) ? ($passing['mark'] ?? PassingMark::DEFAULT) : PassingMark::DEFAULT));
    }

    /**
     * Class results for one exam in one room: each current student's latest
     * sitting, and how the class did on each question.
     *
     * Questions are columns by which question they are, not by the position
     * a student met them in: with shuffling on, a student's item 1 is a
     * different question for everyone, and counting by position mixed them.
     * The columns follow the answer key's numbering (examQuestionIds()), so
     * Q5 here is item 5 there; a question a student never got is "Not Given".
     *
     * @return array<string, mixed>
     */
    public function buildSessionLatestResults(Exam $exam, Room $room): array
    {
        $students = $room->members()
            ->select('users.id', 'users.name', 'users.email', 'users.student_id', 'users.role')
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->orderBy('users.name')
            ->get();

        $studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();

        $attempts = ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->when(
                count($studentIds) > 0,
                fn ($query) => $query->whereIn('user_id', $studentIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with([
                'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
                'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
                'answers.selectedOption:id,option_label,option_text,is_correct',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        $attemptsByStudentId = $attempts->groupBy('user_id');
        $latestAttemptsByStudentId = $attemptsByStudentId->map(fn (Collection $group) => $group->first());
        $mark = PassingMark::current();

        // The answer key's order, then anything a student was given that is no
        // longer in the exam's sets (a set changed after they sat it).
        $columnIds = $this->examQuestionIds($exam);
        foreach ($latestAttemptsByStudentId as $attempt) {
            foreach ($attempt->attemptQuestions->sortBy('item_number') as $attemptQuestion) {
                $questionId = (int) $attemptQuestion->question_bank_question_id;
                if (!in_array($questionId, $columnIds, true)) {
                    $columnIds[] = $questionId;
                }
            }
        }

        $columnByQuestionId = [];
        foreach ($columnIds as $index => $questionId) {
            $columnByQuestionId[$questionId] = $index + 1;
        }

        $questions = QuestionBankQuestion::query()
            ->whereIn('id', $columnIds === [] ? [0] : $columnIds)
            ->with('options:id,question_bank_question_id,option_label,option_text,is_correct')
            ->get(['id', 'question_text', 'question_type', 'answer_label', 'answer_text'])
            ->keyBy('id');

        $stats = [];
        foreach ($columnIds as $index => $questionId) {
            $stats[$index + 1] = ['given' => 0, 'answered' => 0, 'correct' => 0, 'wrong_choices' => []];
        }

        $shortAnswerDump = [];

        $rows = $students->map(function (User $student) use (
            $mark,
            $attemptsByStudentId,
            $latestAttemptsByStudentId,
            $columnIds,
            $columnByQuestionId,
            $questions,
            &$stats,
            &$shortAnswerDump
        ): array {
            /** @var ExamAttempt|null $attempt */
            $attempt = $latestAttemptsByStudentId->get((int) $student->id);
            $labels = [];
            foreach ($columnIds as $index => $questionId) {
                $labels[$index + 1] = $attempt ? 'Not Given' : '';
            }

            if ($attempt) {
                $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

                foreach ($attempt->attemptQuestions as $attemptQuestion) {
                    $questionId = (int) $attemptQuestion->question_bank_question_id;
                    $column = $columnByQuestionId[$questionId] ?? null;
                    if ($column === null) {
                        continue;
                    }

                    /** @var ExamAttemptAnswer|null $answer */
                    $answer = $answersByQuestionId->get($questionId);
                    $isCorrect = $answer && !is_null($answer->is_correct) ? (bool) $answer->is_correct : null;

                    $stats[$column]['given']++;

                    if (!$answer) {
                        $labels[$column] = 'No Answer';
                        continue;
                    }

                    $stats[$column]['answered']++;
                    $labels[$column] = match ($isCorrect) {
                        true => 'Correct',
                        false => 'Wrong',
                        null => 'Answered',
                    };

                    if ($isCorrect === true) {
                        $stats[$column]['correct']++;
                    } elseif ($isCorrect === false && $answer->selectedOption) {
                        $choice = $this->formatResponseText($answer);
                        $stats[$column]['wrong_choices'][$choice] = ($stats[$column]['wrong_choices'][$choice] ?? 0) + 1;
                    }

                    $question = $questions->get($questionId);
                    $responseText = $this->formatResponseText($answer);
                    if ($question?->question_type === QuestionBankQuestion::TYPE_OPEN_ENDED && $responseText !== null) {
                        $shortAnswerDump[$column] ??= [
                            'item_number' => $column,
                            'question_text' => trim((string) $question->question_text) ?: ('Item ' . $column),
                            'responses' => [],
                        ];
                        $shortAnswerDump[$column]['responses'][] = [
                            'student_name' => $student->name,
                            'student_id' => $student->student_id,
                            'answer_text' => $responseText,
                            'is_correct' => $isCorrect,
                        ];
                    }
                }
            }

            $submitted = $attempt?->status === ExamAttempt::STATUS_SUBMITTED;
            $score = $attempt && !is_null($attempt->score_percent) ? (float) $attempt->score_percent : null;

            return [
                'student' => [
                    'id' => (int) $student->id,
                    'name' => $student->name,
                    'email' => $student->email,
                    'student_id' => $student->student_id,
                ],
                'attempt' => $attempt
                    ? [
                        'id' => (int) $attempt->id,
                        'status' => $attempt->status,
                        'total_items' => (int) $attempt->total_items,
                        'answered_count' => (int) $attempt->answered_count,
                        'correct_answers' => (int) $attempt->correct_answers,
                        'score_percent' => $score,
                        'passed' => $submitted && $score !== null ? $mark->passes($score) : null,
                        'attempt_number' => (int) $attemptsByStudentId->get((int) $student->id)->count(),
                        'started_at' => $attempt->started_at,
                        'submitted_at' => $attempt->submitted_at,
                    ]
                    : null,
                'item_status_labels' => $labels,
            ];
        })->values()->all();

        $itemSummary = [];
        foreach ($columnIds as $index => $questionId) {
            $column = $index + 1;
            $question = $questions->get($questionId);
            $given = (int) $stats[$column]['given'];
            $answered = (int) $stats[$column]['answered'];
            $correct = (int) $stats[$column]['correct'];

            // Of everyone who was given the question, as in their score and the
            // Dashboard: a blank is a miss.
            $correctPercent = $given > 0 ? round(($correct / $given) * 100, 2) : null;

            $wrongChoices = $stats[$column]['wrong_choices'];
            arsort($wrongChoices);
            $commonWrong = array_key_first($wrongChoices);

            $itemSummary[] = [
                'item_number' => $column,
                'question_id' => (int) $questionId,
                'question_text' => $question ? trim((string) $question->question_text) : null,
                'correct_answer' => $question ? $this->resolveCorrectAnswer($question)['display'] : null,
                'started_count' => $given,
                'answered_count' => $answered,
                'correct_count' => $correct,
                'answered_percent' => $given > 0 ? round(($answered / $given) * 100, 2) : 0.0,
                'correct_percent' => $correctPercent,
                'difficulty' => self::difficultyLabel($correctPercent),
                'common_wrong_answer' => $commonWrong,
                'common_wrong_count' => $commonWrong === null ? 0 : (int) $wrongChoices[$commonWrong],
            ];
        }

        // A shuffled exam drawing from a larger set (60 items from 120) lists
        // only the questions someone was given, still under their Answer Key
        // numbers: a column for every question in the set is mostly empty.
        if ($exam->shuffle_questions && count($columnIds) > (int) $exam->total_items) {
            $itemSummary = array_values(array_filter($itemSummary, fn (array $item) => $item['started_count'] > 0));
            $kept = array_column($itemSummary, 'item_number');
            foreach ($rows as &$row) {
                $labels = [];
                foreach ($kept as $position => $itemNumber) {
                    $labels[$position + 1] = $row['item_status_labels'][$itemNumber] ?? '';
                }
                $row['item_status_labels'] = $labels;
            }
            unset($row);
        }

        $scoreValues = collect($rows)
            ->map(fn (array $row) => $row['attempt']['score_percent'] ?? null)
            ->filter(fn ($value) => !is_null($value))
            ->map(fn ($value) => (float) $value)
            ->values();

        $rankableItems = collect($itemSummary)
            ->filter(fn (array $item) => !is_null($item['correct_percent']))
            ->values();

        $submittedRows = collect($rows)->filter(fn (array $row) => ($row['attempt']['status'] ?? null) === ExamAttempt::STATUS_SUBMITTED);
        $passedCount = $submittedRows->filter(fn (array $row) => ($row['attempt']['passed'] ?? false) === true)->count();

        ksort($shortAnswerDump);

        return [
            'exam' => [
                'id' => (int) $exam->id,
                'title' => $exam->title,
                'subject' => $exam->subject,
                'total_items' => (int) $exam->total_items,
                'duration_minutes' => (int) $exam->duration_minutes,
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                'shuffle_questions' => (bool) $exam->shuffle_questions,
            ],
            'room' => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ],
            'summary' => [
                'students_total' => (int) $students->count(),
                'attempts_started' => (int) collect($rows)->filter(fn (array $row) => !is_null($row['attempt']))->count(),
                'attempts_submitted' => (int) $submittedRows->count(),
                'passed_count' => (int) $passedCount,
                'pass_rate_percent' => $submittedRows->isNotEmpty() ? round(($passedCount / $submittedRows->count()) * 100, 2) : null,
                // The students' passing mark, as a score.
                'passing_score' => (float) $mark->mark,
                'passing' => $mark->toArray(),
                'average_score_percent' => $scoreValues->isNotEmpty() ? round((float) $scoreValues->avg(), 2) : null,
                'highest_score_percent' => $scoreValues->isNotEmpty() ? (float) $scoreValues->max() : null,
                'lowest_score_percent' => $scoreValues->isNotEmpty() ? (float) $scoreValues->min() : null,
            ],
            'rows' => $rows,
            // How many question columns; item_summary's item_number is each
            // one's Answer Key number.
            'max_items' => count($itemSummary),
            'item_summary' => $itemSummary,
            'hardest_items' => $rankableItems->sortBy(fn (array $item) => (float) $item['correct_percent'])->take(5)->values()->all(),
            'easiest_items' => $rankableItems->sortByDesc(fn (array $item) => (float) $item['correct_percent'])->take(5)->values()->all(),
            'short_answer_dump' => array_values($shortAnswerDump),
            'generated_at' => now(),
        ];
    }

    /**
     * Item difficulty from the share of the class that got it right, in the
     * bands Philippine item analysis commonly uses (Kubiszyn and Borich):
     * 76% and up is easy, 25% and below is hard.
     */
    public static function difficultyLabel(?float $correctPercent): ?string
    {
        return match (true) {
            $correctPercent === null => null,
            $correctPercent >= 76 => 'Easy',
            $correctPercent <= 25 => 'Hard',
            default => 'Average',
        };
    }

    /** What a student's mark on one question reads as in an export. */
    public const STATUS_TEXT = [
        'Correct' => 'Correct',
        'Wrong' => 'Wrong',
        'No Answer' => 'No answer',
        'Answered' => 'Not marked yet',
        'Not Given' => '',
        '' => '',
    ];

    /**
     * The Class Results columns, one row per student, written out as text for
     * the CSV (the Excel file formats the same rows itself).
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function buildCompleteResultsTable(array $session): array
    {
        $maxItems = (int) ($session['max_items'] ?? 0);
        $mark = self::markOf($session['summary']['passing'] ?? null);
        $headers = self::resultsHeaders($mark);
        $submittedColumn = array_search('Submitted', $headers, true);

        foreach (self::questionHeaders($session) as $header) {
            $headers[] = $header;
        }

        $rows = [];

        foreach (($session['rows'] ?? []) as $row) {
            $line = $this->resultsLine($row, $mark);
            $line[$submittedColumn] = ReportOutput::local($row['attempt']['submitted_at'] ?? null)?->format('Y-m-d H:i') ?? '';

            for ($itemNumber = 1; $itemNumber <= $maxItems; $itemNumber++) {
                $line[] = self::STATUS_TEXT[$row['item_status_labels'][$itemNumber] ?? ''] ?? '';
            }

            $rows[] = $line;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * "Q1", "Q2"... for the question columns, by Answer Key number.
     *
     * @return array<int, string>
     */
    public static function questionHeaders(array $session): array
    {
        return array_map(fn ($item) => 'Q' . (int) $item['item_number'], $session['item_summary'] ?? []);
    }

    /**
     * The leading columns. With the students' passing mark above 75 a Grade
     * column follows Score % (PassingMark), so find a column by its name,
     * not its position.
     *
     * @return array<int, string>
     */
    public static function resultsHeaders(?PassingMark $mark = null): array
    {
        $headers = ['Student Name', 'Student ID', 'Email', 'Status', 'Score %', 'Correct', 'Items', 'Result', 'Attempt', 'Submitted'];

        if ($mark?->converts()) {
            array_splice($headers, 5, 0, ['Grade']);
        }

        return $headers;
    }

    /**
     * One student's leading cells, in resultsHeaders() order; Submitted is
     * left as the moment itself for the caller to write.
     *
     * @return array<int, mixed>
     */
    public function resultsLine(array $row, ?PassingMark $mark = null): array
    {
        $mark ??= new PassingMark();
        $attempt = $row['attempt'] ?? null;
        $student = $row['student'] ?? [];
        $submitted = ($attempt['status'] ?? null) === ExamAttempt::STATUS_SUBMITTED;
        $score = $submitted && isset($attempt['score_percent']) ? round((float) $attempt['score_percent'], 2) : '';

        $line = [
            (string) ($student['name'] ?? ''),
            (string) ($student['student_id'] ?? ''),
            (string) ($student['email'] ?? ''),
            match (true) {
                $attempt === null => 'Not taken',
                $submitted => 'Submitted',
                default => 'In progress',
            },
            $score,
            $submitted ? (int) ($attempt['correct_answers'] ?? 0) : '',
            $attempt ? (int) ($attempt['total_items'] ?? 0) : '',
            match ($attempt['passed'] ?? null) {
                true => 'Passed',
                // The teacher's file reads on the teacher's scale.
                false => $mark->converts() ? 'Below grade ' . PassingMark::STAFF_MARK : 'Below ' . $mark->mark . '%',
                default => '',
            },
            $attempt ? (int) ($attempt['attempt_number'] ?? 1) : '',
            $attempt['submitted_at'] ?? null,
        ];

        if ($mark->converts()) {
            array_splice($line, 5, 0, [$score === '' ? '' : (float) $mark->grade($score)]);
        }

        return $line;
    }

    /**
     * The questions an exam can put in front of a student, in the order its
     * answer key numbers them: its sets in order, each set in its own order.
     * Without shuffling every student gets the first total_items of these;
     * with it, each student gets total_items drawn from all of them.
     *
     * @return array<int, int>
     */
    public function examQuestionIds(Exam $exam): array
    {
        $questionBankIds = $exam->resolvedQuestionBankIds();

        if ($questionBankIds === []) {
            return [];
        }

        $orderLookup = array_flip($questionBankIds);
        $questions = QuestionBankQuestion::query()
            ->whereIn('question_bank_id', $questionBankIds)
            ->get(['id', 'question_bank_id', 'item_number'])
            // Each entry of a multi-key sortBy() compares two questions; a
            // one-argument function here sorted the key out of order.
            ->sortBy([
                fn (QuestionBankQuestion $a, QuestionBankQuestion $b) => ($orderLookup[(int) $a->question_bank_id] ?? PHP_INT_MAX)
                    <=> ($orderLookup[(int) $b->question_bank_id] ?? PHP_INT_MAX),
                fn (QuestionBankQuestion $a, QuestionBankQuestion $b) => (int) $a->item_number <=> (int) $b->item_number,
                fn (QuestionBankQuestion $a, QuestionBankQuestion $b) => (int) $a->id <=> (int) $b->id,
            ]);

        if (!$exam->shuffle_questions && (int) $exam->total_items > 0) {
            $questions = $questions->take((int) $exam->total_items);
        }

        return $questions->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildAnswerKey(Exam $exam): array
    {
        $questionIds = $this->examQuestionIds($exam);

        if ($questionIds === []) {
            return [];
        }

        $questions = QuestionBankQuestion::query()
            ->whereIn('id', $questionIds)
            ->with('options:id,question_bank_question_id,option_label,option_text,is_correct')
            ->get()
            ->keyBy('id');

        return collect($questionIds)
            ->map(fn (int $id) => $questions->get($id))
            ->filter()
            ->values()
            ->map(function (QuestionBankQuestion $question, int $index): array {
                $correctAnswer = $this->resolveCorrectAnswer($question);

                return [
                    'item_number' => $index + 1,
                    'question_text' => (string) $question->question_text,
                    'question_type' => (string) $question->question_type,
                    'correct_answer' => $correctAnswer,
                    'options' => $question->options
                        ->map(fn ($option) => [
                            'label' => $option->option_label,
                            'text' => $option->option_text,
                            'is_correct' => (bool) $option->is_correct,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    public function findLatestAttemptForStudent(Exam $exam, Room $room, int $studentId): ?ExamAttempt
    {
        return ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->where('user_id', $studentId)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return Collection<int, ExamAttempt>
     */
    public function getSessionAttempts(Exam $exam, Room $room): Collection
    {
        $studentIds = $room->members()
            ->where('users.role', User::ROLE_STUDENT)
            ->whereNull('users.archived_at')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ExamAttempt::query()
            ->where('exam_id', (int) $exam->id)
            ->where('room_id', (int) $room->id)
            ->when(
                count($studentIds) > 0,
                fn ($query) => $query->whereIn('user_id', $studentIds),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->with([
                'student:id,name,email,student_id',
                'exam:id,title,subject,total_items,duration_minutes',
                'room:id,name,code',
                'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
                'attemptQuestions.question:id,question_text,question_type,answer_label,answer_text',
                'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
                'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
                'answers.selectedOption:id,option_label,option_text,is_correct',
            ])
            ->orderBy('user_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Return one latest attempt per student for an exam session.
     *
     * @return Collection<int, ExamAttempt>
     */
    public function getLatestSessionAttemptsByStudent(Exam $exam, Room $room): Collection
    {
        return $this->getSessionAttempts($exam, $room)
            ->groupBy('user_id')
            ->map(function (Collection $attempts): ?ExamAttempt {
                /** @var ExamAttempt|null $latest */
                $latest = $attempts->reduce(
                    function (?ExamAttempt $carry, ExamAttempt $attempt): ExamAttempt {
                        if (is_null($carry)) {
                            return $attempt;
                        }

                        $attemptTimestamp = optional($attempt->started_at)->timestamp ?? 0;
                        $carryTimestamp = optional($carry->started_at)->timestamp ?? 0;

                        if ($attemptTimestamp > $carryTimestamp) {
                            return $attempt;
                        }

                        if ($attemptTimestamp === $carryTimestamp && (int) $attempt->id > (int) $carry->id) {
                            return $attempt;
                        }

                        return $carry;
                    },
                    null
                );

                return $latest;
            })
            ->filter(fn ($attempt) => $attempt instanceof ExamAttempt)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * One student's report as a PDF, with its file name.
     *
     * $forStudent is the copy that goes to the student (by email): it follows
     * the exam's results setting, so a "Hidden" exam's copy leaves out the
     * correct answers and which items were right, as the student's own result
     * page does. The teacher's download always has them.
     *
     * @return array{report: array<string, mixed>, binary: string, filename: string}
     */
    public function studentReportPdf(ExamAttempt $attempt, bool $forStudent = false): array
    {
        $report = $this->buildAttemptReportData($attempt);
        $showAnswers = !$forStudent || $this->answersShownToStudent($attempt);

        $binary = ReportOutput::pdf('reports.student_report_pdf', [
            'report' => $report,
            'showAnswers' => $showAnswers,
            // The student's copy reads their score against their mark; the
            // teacher's adds the grade.
            'forStudent' => $forStudent,
        ])->output();

        return ['report' => $report, 'binary' => $binary, 'filename' => $this->studentReportFilename($report)];
    }

    /** "Dela Cruz, Angelica (202300448) - Cataloging Mock Board Exam - Student Report - 2026-10-20.pdf" */
    public function studentReportFilename(array $report): string
    {
        $studentId = trim((string) ($report['student']['student_id'] ?? ''));

        return ReportOutput::filename([
            trim((string) ($report['student']['name'] ?? 'Student')) . ($studentId !== '' ? " ({$studentId})" : ''),
            $report['exam']['title'] ?? null,
            'Student Report',
        ], 'pdf');
    }

    /**
     * Whether the student may see this attempt's correct answers: the exam's
     * results are Visible, and, when answers come after the last attempt,
     * the student cannot sit it again (Exam::answersShownFor()).
     */
    public function answersShownToStudent(ExamAttempt $attempt): bool
    {
        // Read afresh: the report data loads the exam with only the columns it
        // prints, and the settings this needs are not among them.
        return (bool) $attempt->exam()->first()?->answersShownFor($attempt);
    }

    public function buildAttemptReportData(ExamAttempt $attempt): array
    {
        $attempt->loadMissing([
            'student:id,name,email,student_id',
            'exam:id,title,subject,total_items,duration_minutes,delivery_mode,one_take_only,shuffle_questions',
            'room:id,name,code',
            'attemptQuestions:id,exam_attempt_id,question_bank_question_id,item_number',
            'attemptQuestions.question:id,question_text,question_type,answer_label,answer_text',
            'attemptQuestions.question.options:id,question_bank_question_id,option_label,option_text,is_correct',
            'answers:id,exam_attempt_id,question_bank_question_id,question_bank_option_id,answer_text,is_correct,answered_at',
            'answers.selectedOption:id,option_label,option_text,is_correct',
        ]);

        $answersByQuestionId = $attempt->answers->keyBy('question_bank_question_id');

        // Which attempt at the exam in this room this is: "Attempt 2 of 2".
        $sittingIds = ExamAttempt::query()
            ->where('exam_id', (int) $attempt->exam_id)
            ->where('room_id', (int) $attempt->room_id)
            ->where('user_id', (int) $attempt->user_id)
            ->orderBy('started_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $sittingNumber = array_search((int) $attempt->id, $sittingIds, true);

        $totalItems = max(
            (int) $attempt->total_items,
            (int) ($attempt->attemptQuestions->max('item_number') ?? 0),
        );

        $answeredCount = (int) $attempt->answers->count();
        $correctAnswers = (int) $attempt->answers->where('is_correct', true)->count();
        $mark = PassingMark::current();
        $scorePercent = is_null($attempt->score_percent)
            ? ($totalItems > 0 ? round(($correctAnswers / $totalItems) * 100, 2) : 0.0)
            : (float) $attempt->score_percent;

        $items = $attempt->attemptQuestions
            ->sortBy('item_number')
            ->values()
            ->map(function (ExamAttemptQuestion $attemptQuestion) use ($answersByQuestionId): array {
                $question = $attemptQuestion->question;
                $questionId = (int) $attemptQuestion->question_bank_question_id;

                /** @var ExamAttemptAnswer|null $answer */
                $answer = $answersByQuestionId->get($questionId);
                $hasAnswer = !is_null($answer);

                $statusLabel = 'No Answer';
                if ($hasAnswer && $answer?->is_correct === true) {
                    $statusLabel = 'Correct';
                } elseif ($hasAnswer && $answer?->is_correct === false) {
                    $statusLabel = 'Wrong';
                } elseif ($hasAnswer) {
                    $statusLabel = 'Pending Review';
                }

                $selectedAnswerText = $this->formatResponseText($answer);
                $correctAnswer = $question ? $this->resolveCorrectAnswer($question) : [
                    'label' => null,
                    'text' => null,
                    'display' => null,
                ];

                return [
                    'item_number' => (int) $attemptQuestion->item_number,
                    'question_id' => $questionId,
                    'question_text' => $question?->question_text,
                    'question_type' => $question?->question_type,
                    'selected_answer' => $selectedAnswerText,
                    'status_label' => $statusLabel,
                    'is_correct' => $hasAnswer ? $answer?->is_correct : null,
                    'correct_answer' => $correctAnswer,
                    'answered_at' => $answer?->answered_at,
                ];
            })
            ->all();

        return [
            'attempt' => [
                'id' => (int) $attempt->id,
                'status' => $attempt->status,
                'total_items' => $totalItems,
                'answered_count' => $answeredCount,
                'correct_answers' => $correctAnswers,
                'score_percent' => $scorePercent,
                'passed' => $attempt->status === ExamAttempt::STATUS_SUBMITTED ? $mark->passes($scorePercent) : null,
                // The students' passing mark, as a score; the teacher's copy
                // also shows the grade (`passing`, PassingMark).
                'passing_score' => (float) $mark->mark,
                'grade' => $mark->grade($scorePercent),
                'passing' => $mark->toArray(),
                'attempt_number' => $sittingNumber === false ? 1 : $sittingNumber + 1,
                'attempts_total' => max(1, count($sittingIds)),
                'started_at' => $attempt->started_at,
                'submitted_at' => $attempt->submitted_at,
            ],
            'student' => [
                'id' => (int) ($attempt->student?->id ?? $attempt->user_id),
                'name' => $attempt->student?->name,
                'email' => $attempt->student?->email,
                'student_id' => $attempt->student?->student_id,
            ],
            'exam' => [
                'id' => (int) ($attempt->exam?->id ?? $attempt->exam_id),
                'title' => $attempt->exam?->title,
                'subject' => $attempt->exam?->subject,
                'duration_minutes' => (int) ($attempt->exam?->duration_minutes ?? $attempt->duration_minutes),
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            ],
            'room' => [
                'id' => (int) ($attempt->room?->id ?? $attempt->room_id),
                'name' => $attempt->room?->name,
                'code' => $attempt->room?->code,
            ],
            'items' => $items,
        ];
    }

    private function formatResponseText(?ExamAttemptAnswer $answer): ?string
    {
        if (is_null($answer)) {
            return null;
        }

        if ($answer->selectedOption) {
            $optionLabel = trim((string) $answer->selectedOption->option_label);
            $optionText = trim((string) $answer->selectedOption->option_text);
            $display = $optionLabel !== ''
                ? trim($optionLabel . '. ' . $optionText)
                : $optionText;

            return $display !== '' ? $display : null;
        }

        $answerText = trim((string) ($answer->answer_text ?? ''));

        return $answerText !== '' ? $answerText : null;
    }

    /**
     * @return array{label: string|null, text: string|null, display: string|null}
     */
    private function resolveCorrectAnswer(QuestionBankQuestion $question): array
    {
        $correctOption = $question->options->first(fn ($option) => (bool) $option->is_correct);

        $label = trim((string) ($question->answer_label ?? ''));
        $text = trim((string) ($question->answer_text ?? ''));

        if ($label === '' && $correctOption) {
            $label = trim((string) ($correctOption->option_label ?? ''));
        }

        if ($text === '' && $correctOption) {
            $text = trim((string) ($correctOption->option_text ?? ''));
        }

        $display = null;
        if ($label !== '' || $text !== '') {
            $display = $label !== ''
                ? trim($label . '. ' . $text)
                : $text;
        }

        return [
            'label' => $label !== '' ? $label : null,
            'text' => $text !== '' ? $text : null,
            'display' => $display,
        ];
    }
}
