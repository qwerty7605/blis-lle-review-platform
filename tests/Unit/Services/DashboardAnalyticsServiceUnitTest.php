<?php

namespace Tests\Unit\Services;

use App\Models\ExamAttempt;
use App\Services\DashboardAnalyticsService;
use App\Services\StudentDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * DashboardAnalyticsService, the parts the dashboard and directory feature
 * tests leave out: a teacher with no rooms, a class whose only attempts are
 * still under way, and sorting the student directory by a figure that has to
 * be worked out before the page can be chosen.
 */
class DashboardAnalyticsServiceUnitTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function service(): DashboardAnalyticsService
    {
        return app(DashboardAnalyticsService::class);
    }

    public function test_a_teacher_with_no_rooms_has_an_empty_overview(): void
    {
        $overview = $this->service()->buildStaffReportOverview($this->makeTeacher());

        $this->assertSame(0, $overview['metrics']['managed_rooms']);

    }

    public function test_attempts_still_under_way_give_empty_class_analytics(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room]);
        $this->makeOpenAttempt($exam, $room, $student, [true]);

        $analytics = $this->service()->buildStaffAnalytics($teacher);

        $this->assertSame(0, $analytics['graded_attempts']);
        $this->assertSame([], $analytics['score_distribution']);
        $this->assertSame([], $analytics['students_needing_attention']);
    }

    public function test_the_directory_sorts_by_a_worked_out_figure_with_blanks_last(): void
    {
        $teacher = $this->makeTeacher();
        $high = $this->makeStudent(['name' => 'High']);
        $low = $this->makeStudent(['name' => 'Low']);
        $none = $this->makeStudent(['name' => 'None']);
        $room = $this->makeRoom($teacher, [$high, $low, $none]);
        $exam = $this->makeExam($teacher, $this->makeBank($teacher, 2), [$room]);
        $this->makeSubmittedAttempt($exam, $room, $high, [true, true]);
        $this->makeSubmittedAttempt($exam, $room, $low, [true, false]);

        foreach (['desc' => ['High', 'Low', 'None'], 'asc' => ['Low', 'High', 'None']] as $direction => $expected) {
            $page = app(StudentDirectoryService::class)->paginateStudentDirectory([
                'sort' => 'average_score_percent',
                'direction' => $direction,
                'per_page' => 10,
            ]);

            $this->assertSame($expected, array_column($page['students'], 'name'), $direction);
        }
    }
}
