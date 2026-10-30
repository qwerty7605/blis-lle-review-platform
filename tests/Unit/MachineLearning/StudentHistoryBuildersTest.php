<?php

namespace Tests\Unit\MachineLearning;

use App\Models\Exam;
use App\Models\ExamSeries;
use App\Models\User;
use App\Services\MachineLearning\MasteryProfileBuilder;
use App\Services\MachineLearning\ReviewSeriesFeatureBuilder;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The classes that turn a student's exam history into model inputs:
 * ReviewSeriesFeatureBuilder (a pre-test's or mock board's six subject
 * scores) and MasteryProfileBuilder (knowledge tracing per topic and
 * subtopic). The training set and the card have their own feature tests.
 */
class StudentHistoryBuildersTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
    }

    /** One submitted exam in a subject. */
    private function sat(string $subject, float $score, ?Carbon $at = null, array $questionAttributes = [], array $examAttributes = []): Exam
    {
        $room = $this->makeRoom($this->teacher, [$this->student]);
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 1, ['subject' => $subject], $questionAttributes), [$room], [
            'subject' => $subject,
            ...$examAttributes,
        ]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $this->student, [true], $at ?? Carbon::create(2025, 10, 1));
        $attempt->forceFill(['score_percent' => $score])->save();

        return $exam;
    }

    public function test_a_series_collects_each_subject_s_latest_sitting_and_says_what_is_missing(): void
    {
        $series = ExamSeries::create(['name' => '2026 Mock Board 1', 'type' => Exam::TYPE_MOCK_BOARD]);
        foreach (['reference' => 80.0, 'it' => 65.5] as $key => $score) {
            $this->sat(LleTaxonomy::subjectName($key), $score, null, [], ['exam_type' => Exam::TYPE_MOCK_BOARD, 'exam_series_id' => $series->id, 'series_subject' => $key]);
        }
        // A regular exam in the same subject is no part of it.
        $this->sat(LleTaxonomy::subjectName('management'), 99);

        $found = (new ReviewSeriesFeatureBuilder())->seriesFor($this->student);

        $this->assertCount(1, $found);
        $this->assertSame('2026 Mock Board 1', $found[0]['name']);
        $this->assertSame(2, $found[0]['done']);
        $this->assertFalse($found[0]['complete']);
        $this->assertNull($found[0]['completed_at']);
        $this->assertSame(80.0, $found[0]['scores']['reference']);
        $this->assertNull($found[0]['scores']['management']);
        $this->assertSame(['Cataloging and Classification', 'Indexing and Abstracting', 'Library Organization and Management', 'Selection and Acquisition'], $found[0]['missing']);
        $this->assertSame(['exam_type' => 'mock_board', ...$found[0]['scores']], ReviewSeriesFeatureBuilder::features($found[0]));
    }

    public function test_mastery_is_traced_per_topic_and_subtopic_never_subject_and_reused_for_the_same_student(): void
    {
        $topic = $this->makeTopic('Reference, Bibliography and User Services', '2. Reference and Information Sources', $this->teacher);
        $subtopic = $this->makeSubtopic($topic, '2.2 Identify / classify the different types of reference sources', $this->teacher);
        $this->sat('Reference, Bibliography and User Services', 100, null, ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id]);
        $this->sat('Reference, Bibliography and User Services', 100);

        $builder = app(MasteryProfileBuilder::class);
        $profile = $builder->build($this->student);

        // Subjects show their percent correct instead; the untagged exam is no skill.
        $this->assertSame([], $profile['subjects']);
        $this->assertSame([$topic->id], array_keys($profile['topics']));
        $this->assertSame(1, $profile['topics'][$topic->id]['opportunities']);
        $this->assertSame(1, $profile['subtopics'][$subtopic->id]['opportunities']);
        $this->assertSame($profile, $builder->build($this->student));
    }
}
