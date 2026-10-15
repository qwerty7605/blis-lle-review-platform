<?php

namespace Tests\Unit\Services;

use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\QuestionBankQuestion;
use App\Services\ReportExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * ReportExportService builds the data behind every teacher export: the room
 * gradebook, one session's results and item analysis, the answer key, and one
 * attempt's report. These tests call it directly, branch by branch.
 */
class ReportExportServiceTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private ReportExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReportExportService();
    }

    public function test_the_gradebook_has_a_column_per_exam_and_na_where_a_student_has_no_score(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent(['name' => 'Ana']);
        $ben = $this->makeStudent(['name' => 'Ben']);
        $room = $this->makeRoom($teacher, [$ana, $ben]);
        $first = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room], ['title' => 'First']);
        $second = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room], ['title' => 'Second']);

        $this->makeSubmittedAttempt($first, $room, $ana, [true, true]);
        $this->makeSubmittedAttempt($second, $room, $ana, [true, false]);
        // Ben's attempt carries no score at all.
        $this->makeSubmittedAttempt($first, $room, $ben, [false, false])->update(['score_percent' => null]);

        $gradebook = $this->service->buildRoomGradebook($room);

        $this->assertSame(2, $gradebook['summary']['students_total']);
        $this->assertSame(2, $gradebook['summary']['exams_total']);
        $this->assertSame(3, $gradebook['summary']['submitted_attempts']);
        $this->assertEquals(75.0, $gradebook['summary']['average_score_percent']);

        $anaRow = collect($gradebook['rows'])->firstWhere('student.name', 'Ana');
        $this->assertSame(2, $anaRow['summary']['completed_exams']);
        $this->assertEquals(75.0, $anaRow['summary']['average_score_percent']);

        $benRow = collect($gradebook['rows'])->firstWhere('student.name', 'Ben');
        $this->assertNull($benRow['summary']['average_score_percent']);
        $this->assertNull($benRow['exam_entries'][0]['score_percent']);

        $table = $this->service->buildRoomGradebookTable($gradebook);
        $this->assertSame(['Student Name', 'Student ID', 'Email', 'Completed Exams', 'Average Score %', 'First (%)', 'Second (%)'], $table['headers']);
        $benLine = collect($table['rows'])->first(fn ($line) => $line[0] === 'Ben');
        $this->assertSame(['N/A', 'N/A', 'N/A'], [$benLine[4], $benLine[5], $benLine[6]]);
    }

    public function test_a_room_with_no_exams_or_students_gives_an_empty_gradebook(): void
    {
        $room = $this->makeRoom($this->makeTeacher());

        $gradebook = $this->service->buildRoomGradebook($room);

        $this->assertSame([], $gradebook['rows']);
        $this->assertNull($gradebook['summary']['average_score_percent']);
        $this->assertSame(5, count($this->service->buildRoomGradebookTable($gradebook)['headers']));
    }

    public function test_session_results_label_every_item_and_rank_the_hardest_and_easiest(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent(['name' => 'Ana']);
        $ben = $this->makeStudent(['name' => 'Ben']);
        $absent = $this->makeStudent(['name' => 'Cara']);
        $room = $this->makeRoom($teacher, [$ana, $ben, $absent]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 3), [$room]);

        $this->makeSubmittedAttempt($exam, $room, $ana, [true, true, false]);
        $this->makeSubmittedAttempt($exam, $room, $ben, [true, false, null]);

        $session = $this->service->buildSessionLatestResults($exam, $room);

        $this->assertSame(3, $session['summary']['students_total']);
        $this->assertSame(2, $session['summary']['attempts_started']);
        $this->assertSame(2, $session['summary']['attempts_submitted']);
        $this->assertEquals(66.67, $session['summary']['highest_score_percent']);
        $this->assertEquals(33.33, $session['summary']['lowest_score_percent']);

        $ben = collect($session['rows'])->firstWhere('student.name', 'Ben');
        $this->assertSame([1 => 'Correct', 2 => 'Wrong', 3 => 'No Answer'], $ben['item_status_labels']);
        $this->assertNull(collect($session['rows'])->firstWhere('student.name', 'Cara')['attempt']);

        // Item 1: both right. Item 2: one of two. Item 3: one answered, wrong.
        $this->assertEquals([100.0, 50.0, 0.0], array_column($session['item_summary'], 'correct_percent'));
        $this->assertEquals([100.0, 100.0, 50.0], array_column($session['item_summary'], 'answered_percent'));
        $this->assertSame(3, $session['hardest_items'][0]['item_number']);
        $this->assertSame(1, $session['easiest_items'][0]['item_number']);

        $table = $this->service->buildCompleteResultsTable($session);
        $this->assertSame('Q3', end($table['headers']));
        $caraLine = collect($table['rows'])->first(fn ($line) => $line[0] === 'Cara');
        $this->assertSame('not_started', $caraLine[4]);
        $this->assertSame('', $caraLine[7]);
    }

    public function test_session_results_use_each_student_s_latest_sitting(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$ana]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room]);

        $this->makeSubmittedAttempt($exam, $room, $ana, [false, false], now()->subDay());
        $latest = $this->makeSubmittedAttempt($exam, $room, $ana, [true, true]);

        $session = $this->service->buildSessionLatestResults($exam, $room);

        $this->assertSame($latest->id, $session['rows'][0]['attempt']['id']);
        $this->assertSame($latest->id, $this->service->findLatestAttemptForStudent($exam, $room, $ana->id)->id);
        $this->assertSame([$latest->id], $this->service->getLatestSessionAttemptsByStudent($exam, $room)->pluck('id')->all());
        $this->assertCount(2, $this->service->getSessionAttempts($exam, $room));
    }

    public function test_when_two_sittings_started_at_the_same_moment_the_later_one_counts(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$ana]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 1), [$room]);
        $moment = now()->subHour();

        $first = $this->makeSubmittedAttempt($exam, $room, $ana, [false]);
        $second = $this->makeSubmittedAttempt($exam, $room, $ana, [true]);
        ExamAttempt::whereIn('id', [$first->id, $second->id])->update(['started_at' => $moment]);

        $this->assertSame([$second->id], $this->service->getLatestSessionAttemptsByStudent($exam, $room)->pluck('id')->all());

        // An earlier-started sitting after the latest one does not replace it.
        $third = $this->makeSubmittedAttempt($exam, $room, $ana, [true]);
        $third->update(['started_at' => $moment->copy()->subDay()]);

        $this->assertSame([$second->id], $this->service->getLatestSessionAttemptsByStudent($exam, $room)->pluck('id')->all());
    }

    public function test_written_answers_are_gathered_and_an_unmarked_one_reads_answered(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent(['name' => 'Ana']);
        $room = $this->makeRoom($teacher, [$ana]);
        $bank = $this->makeBank($teacher, 1, [], ['question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED]);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $ana, [null]);

        ExamAttemptAnswer::create([
            'exam_attempt_id' => $attempt->id,
            'question_bank_question_id' => $bank->questions->first()->id,
            'answer_text' => 'A catalog is a list of holdings.',
            'is_correct' => null,
            'answered_at' => now(),
        ]);

        $session = $this->service->buildSessionLatestResults($exam, $room);

        $this->assertSame('Answered', $session['rows'][0]['item_status_labels'][1]);
        $this->assertSame('A catalog is a list of holdings.', $session['short_answer_dump'][0]['responses'][0]['answer_text']);
        $this->assertSame('Ana', $session['short_answer_dump'][0]['responses'][0]['student_name']);

        $report = $this->service->buildAttemptReportData($attempt->fresh());
        $this->assertSame('Pending Review', $report['items'][0]['status_label']);
        $this->assertSame('A catalog is a list of holdings.', $report['items'][0]['selected_answer']);
    }

    public function test_a_shorter_attempt_is_not_counted_as_starting_items_it_never_had(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$ana]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 3), [$room], ['total_items' => 3]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $ana, [true, true, true]);

        // Only the first item was dealt to this attempt.
        $attempt->attemptQuestions()->where('item_number', '>', 1)->delete();
        $attempt->answers()->whereNotIn('question_bank_question_id', $attempt->attemptQuestions()->pluck('question_bank_question_id'))->delete();
        $attempt->update(['total_items' => 1]);

        $session = $this->service->buildSessionLatestResults($exam, $room);

        $this->assertSame([1, 0, 0], array_column($session['item_summary'], 'started_count'));
    }

    public function test_the_answer_key_follows_the_set_order_and_stops_at_the_exam_s_item_count(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 4), [], ['total_items' => 2]);

        $key = $this->service->buildAnswerKey($exam);

        $this->assertCount(2, $key);
        $this->assertSame([1, 2], array_column($key, 'item_number'));
        $this->assertSame('A. Correct answer 1', $key[0]['correct_answer']['display']);
        $this->assertCount(4, $key[0]['options']);
    }

    public function test_the_answer_key_takes_the_sets_in_the_exam_s_order_then_by_item_number(): void
    {
        $teacher = $this->makeTeacher();
        $first = $this->makeBank($teacher, 2, ['subject' => 'Cataloging']);
        $second = $this->makeBank($teacher, 2, ['subject' => 'Indexing']);
        foreach ([$first, $second] as $bank) {
            foreach ($bank->questions as $question) {
                $question->update(['question_text' => "{$bank->subject} item {$question->item_number}"]);
            }
        }
        $exam = $this->makeExam($teacher, null, [], ['total_items' => 4]);
        // The second set was created later but is first in the exam.
        $exam->syncQuestionBanks([$second->id, $first->id]);

        $key = $this->service->buildAnswerKey($exam);

        $this->assertSame(
            ['Indexing item 1', 'Indexing item 2', 'Cataloging item 1', 'Cataloging item 2'],
            array_column($key, 'question_text'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($key, 'item_number'));
    }

    public function test_an_exam_with_no_question_set_has_an_empty_answer_key(): void
    {
        $exam = $this->makeExam($this->makeTeacher());

        $this->assertSame([], $this->service->buildAnswerKey($exam));
    }

    public function test_the_correct_answer_is_read_from_the_marked_choice_when_the_question_does_not_say(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher, 3, [], ['answer_label' => null, 'answer_text' => null]);
        $questions = $bank->questions;
        // Item 2: the marked choice has no letter. Item 3: no choice is marked.
        $questions[1]->options()->where('is_correct', true)->update(['option_label' => '']);
        $questions[2]->options()->update(['is_correct' => false]);
        $exam = $this->makeExam($teacher, $bank);

        $key = $this->service->buildAnswerKey($exam);

        $this->assertSame(['label' => 'A', 'text' => 'Correct answer 1', 'display' => 'A. Correct answer 1'], $key[0]['correct_answer']);
        $this->assertSame(['label' => null, 'text' => 'Correct answer 2', 'display' => 'Correct answer 2'], $key[1]['correct_answer']);
        $this->assertSame(['label' => null, 'text' => null, 'display' => null], $key[2]['correct_answer']);
    }

    public function test_an_attempt_report_works_out_a_missing_score_and_shows_a_choice_without_a_letter(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent(['name' => 'Ana']);
        $room = $this->makeRoom($teacher, [$ana]);
        $bank = $this->makeBank($teacher, 2);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $ana, [true, false]);
        $attempt->update(['score_percent' => null]);
        // Ana's wrong choice has lost its letter.
        $attempt->answers()->where('is_correct', false)->first()->selectedOption->update(['option_label' => '']);

        $report = $this->service->buildAttemptReportData($attempt->fresh());

        $this->assertEquals(50.0, $report['attempt']['score_percent']);
        $this->assertSame('Ana', $report['student']['name']);
        $this->assertSame($room->code, $report['room']['code']);
        $this->assertSame(['Correct', 'Wrong'], array_column($report['items'], 'status_label'));
        $this->assertSame('A. Correct answer 1', $report['items'][0]['selected_answer']);
        $this->assertSame('Wrong answer 2b', $report['items'][1]['selected_answer']);
        $this->assertSame('A. Correct answer 2', $report['items'][1]['correct_answer']['display']);
    }

    public function test_an_attempt_report_of_an_unanswered_attempt_with_no_items_scores_zero(): void
    {
        $teacher = $this->makeTeacher();
        $ana = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$ana]);
        $exam = $this->makeExam($teacher, null, [$room], ['total_items' => 0]);
        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $ana->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 0,
            'duration_minutes' => 30,
            'answered_count' => 0,
            'correct_answers' => 0,
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
        ]);

        $report = $this->service->buildAttemptReportData($attempt);

        $this->assertEquals(0.0, $report['attempt']['score_percent']);
        $this->assertSame([], $report['items']);
    }
}
