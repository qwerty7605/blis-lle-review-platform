<?php

namespace Tests\Feature;

use App\Services\MachineLearning\KnowledgeTracingService;
use App\Services\MachineLearning\MasteryProfileBuilder;
use App\Services\QuestionMappingService;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Bayesian Knowledge Tracing, the model behind "What To Review Next", one
 * update per exam.
 *
 * The property that matters most is the first one: percent-correct cannot tell
 * a student climbing out of a weak topic apart from one falling into it, and
 * the whole reason for running BKT is that it can. The order of exams is
 * read; the order of answers inside one exam is not.
 */
class KnowledgeTracingTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private KnowledgeTracingService $tracer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracer = new KnowledgeTracingService();
    }

    public function test_identical_scores_trace_differently_depending_on_the_order_of_exams(): void
    {
        $climbing = $this->tracer->traceExams([$this->exam([false, false, false]), $this->exam([true, true, true])]);
        $falling = $this->tracer->traceExams([$this->exam([true, true, true]), $this->exam([false, false, false])]);

        // Both students got 3 of 6 right.
        $this->assertSame(3, $climbing['correct']);
        $this->assertSame(3, $falling['correct']);

        // The model separates them by a wide margin anyway.
        $this->assertGreaterThan($falling['mastery'] + 0.4, $climbing['mastery']);
        $this->assertSame('improving', $climbing['trend']);
        $this->assertSame('slipping', $falling['trend']);
    }

    public function test_the_order_of_answers_inside_one_exam_is_not_read(): void
    {
        $this->assertSame(
            $this->tracer->traceExams([$this->exam([false, false, true, true])]),
            $this->tracer->traceExams([$this->exam([true, false, true, false])]),
        );
    }

    public function test_mastery_stays_within_probability_bounds(): void
    {
        $allWrong = $this->tracer->traceExams(array_fill(0, 8, $this->exam(array_fill(0, 25, false))));
        $allRight = $this->tracer->traceExams(array_fill(0, 8, $this->exam(array_fill(0, 25, true))));

        $this->assertGreaterThanOrEqual(0.0, $allWrong['mastery']);
        $this->assertLessThanOrEqual(1.0, $allRight['mastery']);
        $this->assertSame('struggling', $allWrong['band']);
        $this->assertSame('mastered', $allRight['band']);
    }

    public function test_the_profile_reads_each_exam_s_latest_sitting_with_blanks_as_wrong(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $mapping = app(QuestionMappingService::class);
        $subject = 'Cataloging and Classification';
        $topic = $mapping->resolveTopic($subject, '4');
        $ddc = $mapping->resolveSubtopic($topic, '4.3');
        $other = $mapping->resolveTopic($subject, LleTaxonomy::OTHER_TOPIC);
        $room = $this->makeRoom($teacher, [$student]);

        // Exam A, three DDC items: wrong at the first sitting, right at the second.
        $bankA = $this->makeBank($teacher, 3, ['subject' => $subject], ['topic_id' => $topic->id, 'subtopic_id' => $ddc->id]);
        $examA = $this->makeExam($teacher, $bankA, [$room]);
        $this->makeSubmittedAttempt($examA, $room, $student, [false, false, false], now()->subDays(3));
        $this->makeSubmittedAttempt($examA, $room, $student, [true, true, true], now()->subDays(2));

        // Exam B, taken last: two DDC items, one right and one left blank, and
        // one "Other / Unclassified" item.
        $bankB = $this->makeBank($teacher, 0, ['subject' => $subject]);
        $this->makeQuestion($bankB, 1, ['topic_id' => $topic->id, 'subtopic_id' => $ddc->id]);
        $this->makeQuestion($bankB, 2, ['topic_id' => $topic->id, 'subtopic_id' => $ddc->id]);
        $this->makeQuestion($bankB, 3, ['topic_id' => $other->id]);
        $examB = $this->makeExam($teacher, $bankB, [$room]);
        $this->makeSubmittedAttempt($examB, $room, $student, [true, null, true], now()->subDay());

        $profile = app(MasteryProfileBuilder::class)->build($student);
        $expected = $this->tracer->traceExams([
            $this->exam([true, true, true]),   // exam A's latest sitting
            $this->exam([true, false]),        // exam B: the blank is wrong; Other left out
        ]);

        $this->assertSame([], $profile['subjects']);
        $this->assertSame([$topic->id], array_keys($profile['topics']));
        $this->assertSame($expected, $profile['topics'][$topic->id]);
        $this->assertSame($expected, $profile['subtopics'][$ddc->id]);
        $this->assertSame(2, $profile['topics'][$topic->id]['opportunities']);
        $this->assertSame(5, $profile['topics'][$topic->id]['items']);
    }

    public function test_topic_and_subtopic_mastery_is_grouped_under_each_subject_s_percent_correct(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $mapping = app(QuestionMappingService::class);
        $subject = 'Cataloging and Classification';
        $topic = $mapping->resolveTopic($subject, '4');
        $ddc = $mapping->resolveSubtopic($topic, '4.3');
        $other = $mapping->resolveTopic($subject, LleTaxonomy::OTHER_TOPIC);
        $room = $this->makeRoom($teacher, [$student]);

        $bank = $this->makeBank($teacher, 0, ['subject' => $subject]);
        $this->makeQuestion($bank, 1, ['topic_id' => $topic->id, 'subtopic_id' => $ddc->id]);
        $this->makeQuestion($bank, 2, ['topic_id' => $topic->id, 'subtopic_id' => $ddc->id]);
        $this->makeQuestion($bank, 3, ['topic_id' => $other->id]);
        $this->makeQuestion($bank, 4, ['topic_id' => $other->id]);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $this->makeSubmittedAttempt($exam, $room, $student, [true, true, false, null]);

        $mastery = app(\App\Services\DashboardAnalyticsService::class)->buildMasteryBySubject($student);

        $this->assertCount(1, $mastery);
        $this->assertSame($subject, $mastery[0]['subject']);
        // The subject counts all four items (Other included): 2 of 4.
        $this->assertSame(50.0, $mastery[0]['score']);
        $this->assertSame(4, $mastery[0]['items']);
        // Only the TOS topic is listed, with its traced mastery and its own percent.
        $this->assertSame(['4. Classification'], array_column($mastery[0]['topics'], 'label'));
        $this->assertSame(100.0, $mastery[0]['topics'][0]['score']);
        $this->assertSame($this->tracer->traceExams([$this->exam([true, true])]), $mastery[0]['topics'][0]['mastery']);
        $this->assertSame('4.3 Summarize the historical background and general characteristics of DDC', $mastery[0]['topics'][0]['subtopics'][0]['label']);
        $this->assertNotNull($mastery[0]['topics'][0]['subtopics'][0]['mastery']);
    }

    /**
     * One exam's items on the skill, all four-choice.
     *
     * @param  list<bool>  $outcomes
     * @return list<array{correct: bool, option_count: int}>
     */
    private function exam(array $outcomes): array
    {
        return array_map(
            fn (bool $correct): array => ['correct' => $correct, 'option_count' => 4],
            $outcomes,
        );
    }
}
