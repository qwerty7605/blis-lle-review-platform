<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\SettingsController;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\ReportExportService;
use App\Support\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The students' passing mark (System Settings): students keep their real
 * score and are judged against it; teachers read the same score as a grade
 * where the mark is 75, so both see the same students passing. At 75 nothing
 * changes.
 */
class StudentsPassingMarkTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Room $room;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->makeTeacher();
        $this->student = $this->makeStudent();
        $this->room = $this->makeRoom($this->teacher, [$this->student]);
        $this->exam = $this->makeExam($this->teacher, $this->makeBank($this->teacher, 10), [$this->room]);
    }

    private function setMark(int $mark): void
    {
        SystemSetting::updateOrCreate(['key' => 'students_passing_mark'], ['value' => (string) $mark]);
        app(SystemSettings::class)->forget();
    }

    /** A handed-in attempt with this many of the ten right. */
    private function scores(int $right, ?Exam $exam = null): void
    {
        $this->makeSubmittedAttempt(
            $exam ?? $this->exam,
            $this->room,
            $this->student,
            array_merge(array_fill(0, $right, true), array_fill(0, 10 - $right, false)),
        );
    }

    public function test_an_administrator_sets_the_mark_and_it_is_logged_with_what_it_was(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/settings/system')->assertOk()->assertJsonPath('settings.students_passing_mark', 75);

        $this->putJson('/api/settings/system', ['students_passing_mark' => 85])->assertOk();

        $this->getJson('/api/settings/system')->assertOk()->assertJsonPath('settings.students_passing_mark', 85);

        $log = AuditLog::query()->where('action', 'settings.update')->latest('id')->firstOrFail();
        $this->assertEquals(['from' => 75, 'to' => 85], $log->metadata['students_passing_mark']);
    }

    public function test_the_mark_is_a_whole_number_from_75_to_95_set_by_an_administrator(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        foreach ([74, 96, 82.5, 'eighty'] as $value) {
            $this->putJson('/api/settings/system', ['students_passing_mark' => $value])
                ->assertStatus(422)
                ->assertJsonPath('errors.students_passing_mark.0', SettingsController::PASSING_MARK_MESSAGE);
        }

        Sanctum::actingAs($this->teacher);
        $this->putJson('/api/settings/system', ['students_passing_mark' => 85])->assertForbidden();

        $this->assertDatabaseMissing('system_settings', ['key' => 'students_passing_mark']);
    }

    public function test_a_stored_mark_outside_the_range_reads_as_75(): void
    {
        SystemSetting::create(['key' => 'students_passing_mark', 'value' => '120']);
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/settings/system')->assertOk()->assertJsonPath('settings.students_passing_mark', 75);
    }

    public function test_a_student_keeps_their_score_and_is_judged_against_the_mark(): void
    {
        $this->scores(8);
        Sanctum::actingAs($this->student);

        $subject = fn ($response) => collect($response->json('subjects'))->firstWhere('attempts_count', 1);

        $atDefault = $this->getJson('/api/student/analytics')->assertOk();
        $this->assertSame('developing', $subject($atDefault)['performance_band']);
        $atDefault->assertJsonPath('stats.passing_threshold', 75)->assertJsonPath('passing.converts', false);

        $this->setMark(85);
        $response = $this->getJson('/api/student/analytics')->assertOk()
            ->assertJsonPath('stats.passing_threshold', 85)
            ->assertJsonPath('stats.focus_subjects_count', 1)
            ->assertJsonPath('passing.mark', 85)
            ->assertJsonPath('passing.converts', true);

        $this->assertSame('focus', $subject($response)['performance_band']);
        // The score itself is never converted.
        $this->assertEquals(80, $response->json('recent_attempts.0.score_percent'));
    }

    public function test_the_teacher_gets_the_same_figures_and_verdicts_for_the_profile(): void
    {
        $this->scores(8);
        $this->setMark(85);
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson("/api/students/{$this->student->id}/analytics")->assertOk()
            ->assertJsonPath('stats.passing_threshold', 85)
            ->assertJsonPath('stats.focus_subjects_count', 1)
            ->assertJsonPath('passing.converts', true);

        $this->assertEquals(80, $response->json('recent_attempts.0.score_percent'));
    }

    public function test_the_result_page_reads_the_students_mark(): void
    {
        $this->scores(8);
        $attempt = $this->student->examAttempts()->firstOrFail();
        Sanctum::actingAs($this->student);

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertOk()
            ->assertJsonPath('feedback.pass_status.is_passing', true)
            ->assertJsonPath('feedback.summary.tone', 'passing');

        $this->setMark(85);

        $this->getJson("/api/student/exam-attempts/{$attempt->id}")->assertOk()
            ->assertJsonPath('feedback.pass_status.is_passing', false)
            ->assertJsonPath('feedback.pass_status.passing_score', 85)
            ->assertJsonPath('feedback.pass_status.delta', -5)
            // 60 on the teachers' scale is 68% at an 85 mark: 80% is "within reach".
            ->assertJsonPath('feedback.summary.tone', 'review')
            ->assertJsonPath('feedback.readiness_level.level', 'developing');
    }

    public function test_the_dashboard_counts_passes_by_the_mark_and_bands_by_grade(): void
    {
        $this->scores(8);
        $this->setMark(85);
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/reports/overview')->assertOk()
            ->assertJsonPath('passing.mark', 85)
            ->assertJsonPath('passing.converts', true);

        $this->assertEquals(80, $response->json('metrics.average_score_percent'));
        $this->assertEquals(0, $response->json('metrics.pass_rate_percent'));

        // 80% is a grade of 70.6, in the 60-74 band and below passing.
        $band = collect($response->json('analytics.score_distribution'))->firstWhere('label', '60-74');
        $this->assertSame(1, $band['count']);
        $this->assertFalse($band['is_passing']);
    }

    public function test_averages_are_taken_from_the_scores_and_converted_last(): void
    {
        // 60% and 100% average 80%: below an 85 mark. Converted first, they
        // would be grades of 52.9 and 100, averaging 76.5, a pass.
        $this->scores(6);
        $this->scores(10, $this->makeExam($this->teacher, $this->makeBank($this->teacher, 10), [$this->room]));
        $this->setMark(85);
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/reports/overview')->assertOk();

        $watchlist = collect($response->json('analytics.students_needing_attention'));
        $this->assertSame([$this->student->id], $watchlist->pluck('id')->all());
        $this->assertEquals(80, $watchlist->first()['average_score_percent']);
        $this->assertSame([], $response->json('analytics.top_performers'));
    }

    public function test_the_students_list_counts_passes_by_the_mark(): void
    {
        $this->scores(8);
        Sanctum::actingAs($this->teacher);

        $row = fn ($response) => collect($response->json('students'))->firstWhere('id', $this->student->id);

        $this->assertEquals(100, $row($this->getJson('/api/students/directory')->assertOk())['pass_rate_percent']);

        $this->setMark(85);
        $response = $this->getJson('/api/students/directory')->assertOk()->assertJsonPath('passing.mark', 85);
        $this->assertEquals(0, $row($response)['pass_rate_percent']);
        $this->assertEquals(80, $row($response)['average_score_percent']);
    }

    public function test_the_class_results_file_gives_teachers_the_grade_beside_the_score(): void
    {
        $this->scores(8);
        Sanctum::actingAs($this->teacher);
        $url = "/api/reports/exams/{$this->exam->id}/rooms/{$this->room->id}/complete-results.csv";

        $atDefault = $this->get($url)->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Grade', $atDefault);
        $this->assertStringContainsString('Passed', $atDefault);

        $this->setMark(85);
        $csv = $this->get($url)->assertOk()->streamedContent();

        $this->assertStringContainsString('"Score %",Grade,Correct', $csv);
        $this->assertStringContainsString('80,70.59,8,10,"Below grade 75"', $csv);
    }

    public function test_the_other_files_still_build_with_grades(): void
    {
        $this->scores(8);
        $this->setMark(85);
        Sanctum::actingAs($this->teacher);
        $base = "/api/reports/exams/{$this->exam->id}/rooms/{$this->room->id}";

        foreach (['complete-results.xlsx', 'summary.pdf', "students/{$this->student->id}/student-report.pdf"] as $file) {
            $this->get("{$base}/{$file}")->assertOk();
        }
        foreach (['xlsx', 'csv', 'pdf'] as $format) {
            $this->get("/api/rooms/{$this->room->id}/export-grades?format={$format}")->assertOk();
        }
    }

    public function test_the_gradebook_adds_a_grade_after_each_score(): void
    {
        $table = app(ReportExportService::class)->buildRoomGradebookTable([
            'passing' => ['mark' => 85, 'staff_mark' => 75, 'converts' => true],
            'exams' => [['title' => 'Mock Board']],
            'rows' => [[
                'student' => ['name' => 'Ana Cruz', 'student_id' => '2300001', 'email' => 'ana@lnu.edu.ph'],
                'summary' => ['completed_exams' => 1, 'average_score_percent' => 84.0],
                'exam_entries' => [['score_percent' => 84.0]],
            ]],
        ]);

        $this->assertSame(
            ['Student Name', 'Student ID', 'Email', 'Exams Taken', 'Average %', 'Average Grade', 'Mock Board (%)', 'Mock Board (Grade)'],
            $table['headers'],
        );
        $this->assertSame(['Ana Cruz', '2300001', 'ana@lnu.edu.ph', 1, 84.0, 74.12, 84.0, 74.12], $table['rows'][0]);
        $this->assertSame(['text', 'text', 'text', 'count', 'score', 'grade', 'score', 'grade'], $table['kinds']);
    }
}
