<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
use App\Models\Topic;
use App\Models\User;
use App\Services\Demo\DemoData;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The same shape as the real demo, at a fraction of the rows.
        putenv('SHOWCASE_COHORT_SIZE=6');
        putenv('SHOWCASE_HISTORY_YEARS=1');
    }

    protected function tearDown(): void
    {
        putenv('SHOWCASE_COHORT_SIZE');
        putenv('SHOWCASE_HISTORY_YEARS');

        parent::tearDown();
    }

    public function test_demo_accounts_are_flagged_verified_and_sign_in_with_their_passwords(): void
    {
        $this->seed(ShowcaseSeeder::class);

        $demo = User::query()->where('is_demo', true);

        $this->assertGreaterThan(0, (clone $demo)->count());
        $this->assertSame(0, (clone $demo)->whereNull('email_verified_at')->count());
        $this->assertSame(0, User::query()->where('is_demo', false)->count());

        foreach ([
            [DemoData::DEFAULT_ADMIN_EMAIL, DemoData::DEFAULT_ADMIN_PASSWORD, User::ROLE_ADMIN],
            ['teacher1@lnu.edu.ph', DemoData::DEFAULT_PASSWORD, User::ROLE_STAFF_MASTER_EXAMINER],
            ['teacher3@lnu.edu.ph', DemoData::DEFAULT_PASSWORD, User::ROLE_STAFF_MASTER_EXAMINER],
            ['markanthony.villareal@' . DemoData::EMAIL_DOMAIN, DemoData::DEFAULT_PASSWORD, User::ROLE_STUDENT],
        ] as [$email, $password, $role]) {
            $this->postJson('/api/auth/login', ['identifier' => $email, 'password' => $password])
                ->assertOk()
                ->assertJsonPath('user.role', $role);
        }
    }

    public function test_seeding_stops_rather_than_take_over_a_real_account_on_a_staff_address(): void
    {
        $real = User::factory()->create(['email' => DemoData::DEFAULT_ADMIN_EMAIL, 'name' => 'Real Student']);

        try {
            $this->seed(ShowcaseSeeder::class);
            $this->fail('Seeding should have stopped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(DemoData::DEFAULT_ADMIN_EMAIL, $exception->getMessage());
        }

        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('users', ['id' => $real->id, 'name' => 'Real Student', 'role' => User::ROLE_STUDENT, 'is_demo' => false]);
    }

    public function test_demo_accounts_are_never_mailed_a_password_reset_link(): void
    {
        $this->seed(ShowcaseSeeder::class);
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'teacher1@lnu.edu.ph'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_mapped_demo_items_are_tagged_to_curated_subtopics(): void
    {
        $this->seed(ShowcaseSeeder::class);

        $bank = QuestionBank::query()->where('title', 'Cataloging and Classification Mock Board Reviewer')->firstOrFail();
        $items = QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->orderBy('item_number')->get();

        // One per curated subtopic; all tagged except the last, which is left
        // for a teacher to confirm on purpose.
        $this->assertCount(18, $items);
        $this->assertSame(17, $items->whereNotNull('subtopic_id')->count());
        $this->assertNull($items->last()->topic_id);
    }

    public function test_teachers_own_every_section_their_exams_are_given_in(): void
    {
        $this->seed(ShowcaseSeeder::class);

        // Reports and the live board need the teacher to own both, so demo
        // data must never pair one teacher's exam with another's section.
        $mismatched = DB::table('exam_room')
            ->join('exams', 'exams.id', '=', 'exam_room.exam_id')
            ->join('rooms', 'rooms.id', '=', 'exam_room.room_id')
            ->whereColumn('exams.created_by', '!=', 'rooms.created_by')
            ->count();

        $this->assertSame(0, $mismatched);
    }

    public function test_a_featured_fourth_year_has_the_history_an_estimate_is_shown_from(): void
    {
        $this->seed(ShowcaseSeeder::class);

        Sanctum::actingAs(User::query()->where('email', 'markanthony.villareal@' . DemoData::EMAIL_DOMAIN)->firstOrFail());
        $this->getJson('/api/student/analytics')
            ->assertOk()
            ->assertJsonPath('predictive_model.status', 'connected');

        // The lower years are told when theirs comes.
        Sanctum::actingAs(User::query()->where('is_demo', true)->where('year_level', 1)->where('is_active', true)->firstOrFail());
        $this->getJson('/api/student/analytics')
            ->assertOk()
            ->assertJsonPath('predictive_model.prediction', null)
            ->assertJsonPath('predictive_model.description', fn (string $text) => str_starts_with($text, 'Available in 4th year.'));
    }

    public function test_purge_removes_the_demo_and_leaves_real_records_alone(): void
    {
        $real = $this->realCourse();
        $realTopic = Topic::query()->create(['subject' => 'Cataloging and Classification', 'name' => 'Local Topic', 'created_by' => $real['teacher']->id]);

        $this->seed(ShowcaseSeeder::class);
        $this->artisan('demo:purge')->assertSuccessful();

        $this->assertSame(0, User::query()->where('is_demo', true)->count());
        $this->assertSame(2, User::query()->count());
        $this->assertSame([$real['room']->id], Room::query()->pluck('id')->all());
        $this->assertSame([$real['exam']->id], Exam::query()->pluck('id')->all());
        $this->assertSame([$real['attempt']->id], ExamAttempt::query()->pluck('id')->all());
        $this->assertSame([$real['bank']->id], QuestionBank::query()->pluck('id')->all());
        $this->assertDatabaseHas('topics', ['id' => $realTopic->id]);
        $this->assertSame(0, DB::table('lle_results')->count());
        $this->assertSame(0, DB::table('review_bot_attempts')->count());
    }

    public function test_a_demo_library_file_a_real_exam_uses_is_kept_for_that_exams_author(): void
    {
        $real = $this->realCourse();
        $this->seed(ShowcaseSeeder::class);

        $demoBank = QuestionBank::query()->where('title', 'Cataloging and Classification Mock Board Reviewer')->firstOrFail();
        $exam = $this->makeExam($real['teacher'], $demoBank, $real['room']);

        $this->artisan('demo:purge')->assertSuccessful();

        $this->assertDatabaseHas('question_banks', ['id' => $demoBank->id, 'created_by' => $real['teacher']->id]);
        $this->assertSame(18, QuestionBankQuestion::query()->where('question_bank_id', $demoBank->id)->count());
        $this->assertDatabaseHas('exams', ['id' => $exam->id, 'question_bank_id' => $demoBank->id]);
    }

    public function test_a_real_review_bot_bank_the_demo_generated_into_is_left_as_it_was(): void
    {
        $real = $this->realCourse();
        $subject = 'Cataloging and Classification';

        // The subject's Review Bot bank already exists, so the demo's
        // generated questions land in it rather than in a bank of its own.
        $bank = QuestionBank::query()->create([
            'title' => QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX . $subject,
            'subject' => $subject,
            'total_items' => 1,
            'created_by' => $real['teacher']->id,
        ]);
        $realQuestion = QuestionBankQuestion::query()->create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
            'source_question_id' => QuestionBankQuestion::query()->where('question_bank_id', $real['bank']->id)->value('id'),
            'subject' => $subject,
            'status' => QuestionBankQuestion::STATUS_PUBLISHED,
            'item_number' => 1,
            'question_text' => 'Which field of a MARC 21 record holds the title statement?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => '245',
        ]);

        $this->seed(ShowcaseSeeder::class);
        $this->assertGreaterThan(1, QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->count());

        $this->artisan('demo:purge')->assertSuccessful();

        $this->assertSame([$realQuestion->id], QuestionBankQuestion::query()->where('question_bank_id', $bank->id)->pluck('id')->all());
        $this->assertDatabaseHas('question_banks', ['id' => $bank->id, 'created_by' => $real['teacher']->id, 'total_items' => 1]);
    }

    public function test_purge_refuses_while_real_attempts_sit_in_a_demo_room(): void
    {
        $real = $this->realCourse();
        $this->seed(ShowcaseSeeder::class);

        // A real exam handed to a demo section, and a real student sitting it
        // there: deleting the room would take the attempt with it.
        // Same name as the real section on purpose: the purge goes by owner.
        $demoRoom = Room::query()->where('name', 'BLIS 4-A')->whereKeyNot($real['room']->id)->firstOrFail();
        DB::table('exam_room')->insert(['exam_id' => $real['exam']->id, 'room_id' => $demoRoom->id, 'assigned_by' => $real['teacher']->id]);
        $attempt = $this->makeAttempt($real['exam'], $demoRoom, $real['student']);
        $demoAccounts = User::query()->where('email', 'like', '%@' . DemoData::EMAIL_DOMAIN)->count();

        $this->artisan('demo:purge')->assertFailed();

        $this->assertSame($demoAccounts, User::query()->where('email', 'like', '%@' . DemoData::EMAIL_DOMAIN)->count());
        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id]);
    }

    public function test_dry_run_removes_nothing(): void
    {
        $this->seed(ShowcaseSeeder::class);
        $accounts = User::query()->count();

        $this->artisan('demo:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($accounts, User::query()->count());
    }

    public function test_seeding_again_replaces_the_previous_demo(): void
    {
        $this->seed(ShowcaseSeeder::class);
        $first = [User::query()->count(), ExamAttempt::query()->count(), QuestionBank::query()->count()];

        $this->seed(ShowcaseSeeder::class);

        $this->assertSame($first, [User::query()->count(), ExamAttempt::query()->count(), QuestionBank::query()->count()]);
    }

    public function test_report_emails_are_not_sent_to_demo_accounts(): void
    {
        Mail::fake();
        $this->seed(ShowcaseSeeder::class);

        $attempt = ExamAttempt::query()
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->whereIn('exam_id', Exam::query()->where('title', 'Cataloging and Classification Mock Board Exam')->select('id'))
            ->firstOrFail();

        Sanctum::actingAs(User::query()->where('email', 'teacher1@lnu.edu.ph')->firstOrFail());

        $this->postJson("/api/reports/exams/{$attempt->exam_id}/rooms/{$attempt->room_id}/students/{$attempt->user_id}/student-report.email")
            ->assertStatus(422);
        $this->postJson("/api/reports/exams/{$attempt->exam_id}/rooms/{$attempt->room_id}/student-reports.email")
            ->assertOk();

        Mail::assertNothingSent();
    }

    /**
     * A real teacher's room, Library file and exam, and a real student's
     * attempt on it.
     *
     * @return array{teacher: User, student: User, room: Room, bank: QuestionBank, exam: Exam, attempt: ExamAttempt}
     */
    private function realCourse(): array
    {
        $teacher = User::factory()->create(['email' => 'teacher@lnu.edu.ph', 'role' => User::ROLE_STAFF_MASTER_EXAMINER]);
        $student = User::factory()->create(['email' => 'student@lnu.edu.ph', 'student_id' => '2301001']);

        $room = Room::query()->create(['name' => 'BLIS 4-A', 'code' => 'REAL01', 'created_by' => $teacher->id]);
        $room->members()->attach($student->id);

        $bank = QuestionBank::query()->create(['title' => 'Real Reviewer', 'subject' => 'Cataloging and Classification', 'total_items' => 1, 'created_by' => $teacher->id]);
        $question = QuestionBankQuestion::query()->create([
            'question_bank_id' => $bank->id,
            'usage_type' => QuestionBankQuestion::USAGE_ASSESSMENT,
            'subject' => 'Cataloging and Classification',
            'item_number' => 1,
            'question_text' => 'Which MARC field holds the title statement?',
            'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'answer_label' => 'A',
            'answer_text' => '245',
        ]);
        DB::table('question_bank_options')->insert([
            ['question_bank_question_id' => $question->id, 'sort_order' => 1, 'option_label' => 'A', 'option_text' => '245', 'is_correct' => true],
            ['question_bank_question_id' => $question->id, 'sort_order' => 2, 'option_label' => 'B', 'option_text' => '100', 'is_correct' => false],
        ]);

        $exam = $this->makeExam($teacher, $bank, $room);

        return [
            'teacher' => $teacher,
            'student' => $student,
            'room' => $room,
            'bank' => $bank,
            'exam' => $exam,
            'attempt' => $this->makeAttempt($exam, $room, $student),
        ];
    }

    private function makeExam(User $teacher, QuestionBank $bank, Room $room): Exam
    {
        $exam = Exam::query()->create([
            'title' => 'Real Quiz ' . $bank->id,
            'subject' => $bank->subject,
            'question_bank_id' => $bank->id,
            'total_items' => 1,
            'duration_minutes' => 30,
            'schedule_start_at' => now()->subDays(2),
            'schedule_end_at' => now()->addDay(),
            'delivery_mode' => Exam::DELIVERY_MODE_OPEN_NAVIGATION,
            'created_by' => $teacher->id,
        ]);
        $exam->syncQuestionBanks([$bank->id]);
        $exam->rooms()->attach($room->id, ['assigned_by' => $teacher->id]);

        return $exam;
    }

    private function makeAttempt(Exam $exam, Room $room, User $student): ExamAttempt
    {
        $attempt = ExamAttempt::query()->create([
            'exam_id' => $exam->id,
            'room_id' => $room->id,
            'user_id' => $student->id,
            'status' => ExamAttempt::STATUS_SUBMITTED,
            'total_items' => 1,
            'duration_minutes' => 30,
            'answered_count' => 1,
            'correct_answers' => 1,
            'score_percent' => 100,
            'started_at' => now()->subDay(),
            'submitted_at' => now()->subDay()->addMinutes(10),
        ]);

        $questionId = (int) QuestionBankQuestion::query()->where('question_bank_id', $exam->question_bank_id)->value('id');
        DB::table('exam_attempt_questions')->insert([
            'exam_attempt_id' => $attempt->id,
            'question_bank_question_id' => $questionId,
            'item_number' => 1,
        ]);

        return $attempt;
    }
}
