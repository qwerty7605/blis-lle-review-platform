<?php

namespace Tests\Unit\MachineLearning;

use App\Models\Exam;
use App\Models\LetModel;
use App\Models\LleResult;
use App\Models\User;
use App\Services\MachineLearning\LetModelTrainer;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use App\Services\MachineLearning\LetTrainingSetBuilder;
use App\Services\MachineLearning\MasteryProfileBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The classes that turn a student's exam history into model inputs:
 * LetReadinessFeatureBuilder (subject scores per semester),
 * LetTrainingSetBuilder (one training row per graduate with a board result),
 * MasteryProfileBuilder (knowledge tracing per subject, topic and subtopic),
 * and LetModelTrainer's list of model versions.
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

    /** One submitted exam in a subject, filed under a school year and semester. */
    private function sat(string $subject, float $score, int $schoolYear, int $semester, ?Carbon $at = null, array $questionAttributes = []): Exam
    {
        $room = $this->makeRoom($this->teacher, [$this->student]);
        $exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 1, ['subject' => $subject], $questionAttributes), [$room], [
            'subject' => $subject, 'school_year' => $schoolYear, 'semester' => $semester,
        ]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $this->student, [true], $at ?? Carbon::create($schoolYear, 10, 1));
        $attempt->forceFill(['score_percent' => $score])->save();

        return $exam;
    }

    private function allSix(int $schoolYear, int $semester, float $score = 70, ?Carbon $at = null): void
    {
        foreach (LetReadinessFeatureBuilder::SUBJECTS as $subject) {
            $this->sat($subject, $score, $schoolYear, $semester, $at);
        }
    }

    public function test_semesters_average_each_subject_and_say_what_is_missing(): void
    {
        $this->sat('Reference, Bibliography and User Services', 80, 2025, 1);
        $this->sat('Reference, Bibliography and User Services', 60, 2025, 1);
        $this->sat('Information Technology', 90, 2025, 1);
        // Not a board subject: ignored.
        $this->sat('Archival Studies', 10, 2025, 1);

        $semesters = (new LetReadinessFeatureBuilder())->semesters($this->student);

        $this->assertCount(1, $semesters);
        $this->assertSame('1st Semester, AY 2025-2026', $semesters[0]['label']);
        $this->assertSame(70.0, $semesters[0]['scores']['reference']);
        $this->assertSame(90.0, $semesters[0]['scores']['it']);
        $this->assertFalse($semesters[0]['complete']);
        $this->assertContains('Cataloging and Classification', $semesters[0]['missing']);
    }

    public function test_an_exam_with_no_term_is_filed_by_the_date_it_was_handed_in(): void
    {
        $exam = $this->sat('Reference, Bibliography and User Services', 75, 2025, 1, Carbon::create(2026, 3, 1));
        DB::table('exams')->where('id', $exam->id)->update(['school_year' => null, 'semester' => null]);

        $semesters = (new LetReadinessFeatureBuilder())->semesters($this->student);

        // March 2026 is the second semester of AY 2025-2026.
        $this->assertSame([2025, 2], [$semesters[0]['school_year'], $semesters[0]['semester']]);
    }

    public function test_an_exam_with_no_subject_of_its_own_takes_its_set_s(): void
    {
        $exam = $this->sat('Indexing', 66, 2025, 1);
        DB::table('exams')->where('id', $exam->id)->update(['subject' => '']);

        $this->assertSame(66.0, (new LetReadinessFeatureBuilder())->semesters($this->student)[0]['scores']['indexing']);
    }

    public function test_a_graduate_s_final_year_semester_becomes_a_training_row(): void
    {
        $this->allSix(2025, 2, 70, Carbon::create(2026, 3, 1));
        LleResult::create(['user_id' => $this->student->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertCount(1, $set['graduates']);
        $this->assertSame('student:' . $this->student->id, $set['graduates'][0]['key']);
        $this->assertSame(70.0, $set['graduates'][0]['features']['overall']);
        $this->assertSame(0, $set['skipped']);
    }

    public function test_a_graduate_without_a_complete_final_year_semester_is_skipped(): void
    {
        // Complete, but a year before their last exams.
        $this->allSix(2023, 1, 70, Carbon::create(2023, 10, 1));
        $this->sat('Reference, Bibliography and User Services', 50, 2025, 1, Carbon::create(2025, 10, 1));
        LleResult::create(['user_id' => $this->student->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_FAIL]);

        $other = $this->makeStudent();
        LleResult::create(['user_id' => $other->id, 'exam_year' => 2026, 'result' => LleResult::RESULT_PASS]);

        $set = app(LetTrainingSetBuilder::class)->build();

        $this->assertSame([], $set['graduates']);
        $this->assertSame(2, $set['skipped']);
    }

    public function test_mastery_is_traced_per_subject_topic_and_subtopic_and_reused_for_the_same_student(): void
    {
        $topic = $this->makeTopic('Reference, Bibliography and User Services', 'Ready Reference', $this->teacher);
        $subtopic = $this->makeSubtopic($topic, 'Almanacs', $this->teacher);
        $this->sat('Reference, Bibliography and User Services', 100, 2025, 1, null, ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id]);
        $this->sat('Reference, Bibliography and User Services', 100, 2025, 1);

        $builder = app(MasteryProfileBuilder::class);
        $profile = $builder->build($this->student);

        $this->assertSame(2, $profile['subjects']['Reference, Bibliography and User Services']['opportunities']);
        $this->assertSame(1, $profile['topics'][$topic->id]['opportunities']);
        $this->assertSame(1, $profile['subtopics'][$subtopic->id]['opportunities']);
        $this->assertSame($profile, $builder->build($this->student));
    }

    public function test_an_old_model_version_without_figures_is_listed_with_a_note(): void
    {
        LetModel::create([
            'status' => LetModel::STATUS_RETIRED, 'version' => 2, 'tree' => ['leaf' => 'PASS', 'instances' => 1, 'errors' => 0],
            'training_count' => 10, 'graduate_count' => 0, 'imported_count' => 0, 'training_keys' => [], 'metrics' => [], 'trigger' => 'manual',
        ]);

        $versions = collect(app(LetModelTrainer::class)->status()['versions']);

        $this->assertSame('Trained under the earlier rules; cannot be used with the present model.', $versions->firstWhere('version', 2)['note']);
    }
}
