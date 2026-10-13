<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use App\Models\User;
use App\Services\MachineLearning\LetReadinessFeatureBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The model reads four years of results, but its eight semester inputs can be
 * cut from any eight exams. A first year used to get a prediction from their
 * first eight, read as if they were a whole degree. It is now held until
 * fourth year and seven semesters of exams.
 */
class LetPredictionHeldUntilFourthYearTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECTS = [
        'Information Technology',
        'Cataloging and Classification',
        'Indexing and Abstracting',
        'Reference Services',
        'Library Management',
        'Selection and Acquisition',
    ];

    private User $staff;

    private Room $room;

    /** @var array<string, Exam> */
    private array $exams = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->create(['role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $this->room = Room::create(['name' => 'BLIS 4A', 'code' => 'LET4A1', 'created_by' => $this->staff->id]);

        foreach (self::SUBJECTS as $subject) {
            $this->exams[$subject] = Exam::create([
                'title' => "{$subject} Mock",
                'subject' => $subject,
                'total_items' => 100,
                'duration_minutes' => 60,
                'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
                'one_take_only' => false,
                'shuffle_questions' => false,
                'created_by' => $this->staff->id,
            ]);
        }
    }

    public function test_a_first_year_is_told_it_comes_in_fourth_year_however_many_exams_they_have(): void
    {
        // More than a whole degree's worth, so only the year level holds it.
        $student = $this->student(1, 48);
        Sanctum::actingAs($student);

        $this->getJson('/api/student/analytics')
            ->assertOk()
            ->assertJsonPath('predictive_model.status', 'insufficient_history')
            ->assertJsonPath('predictive_model.prediction', null)
            ->assertJsonPath('predictive_model.description', fn (string $text) => str_starts_with($text, 'Available in 4th year.'));
    }

    public function test_a_fourth_year_waits_for_seven_semesters_of_exams(): void
    {
        $student = $this->student(4, LetReadinessFeatureBuilder::MIN_GRADED_EXAMS - 1);
        Sanctum::actingAs($student);

        $this->getJson('/api/student/analytics')
            ->assertJsonPath('predictive_model.prediction', null)
            ->assertJsonPath('predictive_model.description', 'Available after 42 graded exams, about seven semesters of results (41 so far).');

        $this->attempt($student, 41);

        $this->getJson('/api/student/analytics')
            ->assertJsonPath('predictive_model.status', 'connected')
            ->assertJsonPath('predictive_model.prediction', fn (?string $prediction) => in_array($prediction, ['PASS', 'FAIL'], true));
    }

    public function test_an_irregular_student_past_fourth_year_and_a_graduate_are_shown_theirs(): void
    {
        $irregular = $this->student(5, 42);

        // Year level is not kept once someone has graduated.
        $graduate = $this->student(null, 42);
        $graduate->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();

        Sanctum::actingAs($this->staff);

        foreach ([$irregular, $graduate] as $student) {
            $this->getJson("/api/students/{$student->id}/analytics")
                ->assertOk()
                ->assertJsonPath('predictive_model.status', 'connected');
        }
    }

    public function test_staff_see_the_same_hold_on_a_students_profile(): void
    {
        $student = $this->student(2, 24);
        Sanctum::actingAs($this->staff);

        $this->getJson("/api/students/{$student->id}/analytics")
            ->assertOk()
            ->assertJsonPath('predictive_model.prediction', null)
            ->assertJsonPath('predictive_model.description', fn (string $text) => str_starts_with($text, 'Available in 4th year.'));
    }

    /**
     * A student at $yearLevel with $attempts graded exams, the six subjects in
     * turn, one a week.
     */
    private function student(?int $yearLevel, int $attempts): User
    {
        $student = User::factory()->create(['role' => User::ROLE_STUDENT, 'year_level' => $yearLevel]);

        for ($index = 0; $index < $attempts; $index++) {
            $this->attempt($student, $index);
        }

        return $student;
    }

    private function attempt(User $student, int $index): void
    {
        $when = Carbon::parse('2023-08-07')->addWeeks($index);

        ExamAttempt::create([
            'exam_id' => $this->exams[self::SUBJECTS[$index % count(self::SUBJECTS)]]->id,
            'room_id' => $this->room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 100,
            'duration_minutes' => 60,
            'answered_count' => 100,
            'correct_answers' => 80,
            'score_percent' => 80,
            'started_at' => $when->copy()->subHour(),
            'submitted_at' => $when,
        ]);
    }
}
