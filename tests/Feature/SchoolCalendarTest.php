<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The school calendar an administrator sets in System Settings (the month
 * each semester starts in) and what reads it: today's term on the settings
 * page, the term an exam is filed under, and a new student's admission year.
 */
class SchoolCalendarTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila'));
    }

    public function test_settings_show_the_calendar_and_todays_term_instead_of_a_typed_term(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/settings/system')
            ->assertOk()
            ->assertJsonPath('settings.first_semester_start_month', 8)
            ->assertJsonPath('settings.second_semester_start_month', 1)
            ->assertJsonPath('current_term.school_year', 2026)
            ->assertJsonPath('current_term.semester', 1)
            ->assertJsonPath('current_term.label', '1st Semester, AY 2026-2027')
            ->assertJsonMissingPath('settings.academic_term');
    }

    public function test_an_administrator_changes_the_calendar_and_the_change_is_logged(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->putJson('/api/settings/system', [
            'first_semester_start_month' => 6,
            'second_semester_start_month' => 11,
        ])
            ->assertOk()
            ->assertJsonPath('current_term.label', '2nd Semester, AY 2026-2027');

        $this->assertSame('6', SystemSetting::query()->where('key', 'first_semester_start_month')->value('value'));
        $this->assertSame('11', SystemSetting::query()->where('key', 'second_semester_start_month')->value('value'));

        $this->getJson('/api/settings/system')
            ->assertJsonPath('settings.first_semester_start_month', 6)
            ->assertJsonPath('settings.second_semester_start_month', 11);

        $entry = AuditLog::query()->where('action', 'settings.update')->latest('id')->first();
        $this->assertSame(['first' => 8, 'second' => 1], $entry->metadata['semester_start_months']['from']);
        $this->assertSame(['first' => 6, 'second' => 11], $entry->metadata['semester_start_months']['to']);

        // A save that leaves the months alone says nothing about them.
        $this->putJson('/api/settings/system', ['announcement_banner' => 'Exams resume Monday.'])->assertOk();
        $this->assertArrayNotHasKey('semester_start_months', AuditLog::query()->where('action', 'settings.update')->latest('id')->first()->metadata);
    }

    public function test_the_two_semesters_cannot_start_in_the_same_month(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson('/api/settings/system', ['first_semester_start_month' => 3, 'second_semester_start_month' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['second_semester_start_month']);

        // Only one sent: checked against the saved other (August).
        $this->putJson('/api/settings/system', ['second_semester_start_month' => 8])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['second_semester_start_month']);

        $this->putJson('/api/settings/system', ['first_semester_start_month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_semester_start_month']);

        $this->assertSame(0, SystemSetting::query()->whereIn('key', ['first_semester_start_month', 'second_semester_start_month'])->count());
    }

    public function test_a_teacher_cannot_change_the_calendar(): void
    {
        Sanctum::actingAs($this->makeTeacher());

        $this->putJson('/api/settings/system', ['first_semester_start_month' => 6, 'second_semester_start_month' => 11])
            ->assertForbidden();
    }

    public function test_a_new_exam_is_filed_by_the_calendar(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher);

        $september = $this->makeExam($teacher, $bank, [], ['schedule_start_at' => Carbon::parse('2026-09-10 08:00', 'Asia/Manila')]);
        $this->assertSame([2026, 1], [(int) $september->school_year, (int) $september->semester]);

        $february = $this->makeExam($teacher, $bank, [], ['schedule_start_at' => Carbon::parse('2027-02-10 08:00', 'Asia/Manila')]);
        $this->assertSame([2026, 2], [(int) $february->school_year, (int) $february->semester]);

        $this->saveMonths(6, 11);

        $november = $this->makeExam($teacher, $bank, [], ['schedule_start_at' => Carbon::parse('2026-11-20 08:00', 'Asia/Manila')]);
        $this->assertSame([2026, 2], [(int) $november->school_year, (int) $november->semester], 'June to October is the 1st semester now');

        $this->assertSame([2026, 1], [(int) $september->fresh()->school_year, (int) $september->fresh()->semester], 'an exam already saved keeps its term');
    }

    public function test_moving_an_exams_start_date_files_it_under_the_new_dates_term(): void
    {
        $teacher = $this->makeTeacher();
        $exam = $this->makeExam($teacher, $this->makeBank($teacher), [], [
            'schedule_start_at' => Carbon::parse('2026-12-01 08:00', 'Asia/Manila'),
            'scheduled_at' => Carbon::parse('2026-12-01 08:00', 'Asia/Manila'),
        ]);
        $this->assertSame([2026, 1], [(int) $exam->school_year, (int) $exam->semester]);
        Sanctum::actingAs($teacher);

        // An edit that does not move the start keeps the term.
        $this->patchJson("/api/exams/{$exam->id}", ['title' => 'Battery Exam'])->assertOk();
        $this->assertSame([2026, 1], [(int) $exam->fresh()->school_year, (int) $exam->fresh()->semester]);

        $this->patchJson("/api/exams/{$exam->id}", [
            'schedule_start_at' => Carbon::parse('2027-01-12 08:00', 'Asia/Manila')->toIso8601String(),
        ])->assertOk();
        $this->assertSame([2026, 2], [(int) $exam->fresh()->school_year, (int) $exam->fresh()->semester]);

        // A term named in the request is kept as named.
        $this->patchJson("/api/exams/{$exam->id}", [
            'schedule_start_at' => Carbon::parse('2027-02-12 08:00', 'Asia/Manila')->toIso8601String(),
            'school_year' => 2026,
            'semester' => 1,
        ])->assertOk();
        $this->assertSame([2026, 1], [(int) $exam->fresh()->school_year, (int) $exam->fresh()->semester]);
    }

    public function test_a_student_who_signs_up_in_january_gets_the_school_years_admission_year(): void
    {
        Notification::fake();

        // January 2027 is in school year 2026: a 1st year started in 2026,
        // a 4th year in 2023 (the calendar year would have said 2027 and 2024).
        $this->travelTo(Carbon::parse('2027-01-15 09:00', 'Asia/Manila'));

        $this->postJson('/api/auth/register', $this->registration('2300001', 'juan@lnu.edu.ph', 1))->assertStatus(201);
        $this->postJson('/api/auth/register', $this->registration('2001291', 'maria@lnu.edu.ph', 4))->assertStatus(201);

        $this->assertSame(2026, User::firstWhere('email', 'juan@lnu.edu.ph')->admission_year);
        $this->assertSame(2023, User::firstWhere('email', 'maria@lnu.edu.ph')->admission_year);
    }

    public function test_a_student_who_signs_up_in_september_gets_this_years_admission_year(): void
    {
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-09-15 09:00', 'Asia/Manila'));

        $this->postJson('/api/auth/register', $this->registration('2300001', 'juan@lnu.edu.ph', 1))->assertStatus(201);

        $this->assertSame(2026, User::firstWhere('email', 'juan@lnu.edu.ph')->admission_year);
    }

    private function saveMonths(int $first, int $second): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->putJson('/api/settings/system', [
            'first_semester_start_month' => $first,
            'second_semester_start_month' => $second,
        ])->assertOk();
    }

    /** @return array<string, mixed> */
    private function registration(string $studentId, string $email, int $yearLevel): array
    {
        return [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'student_id' => $studentId,
            'year_level' => $yearLevel,
            'email' => $email,
            'password' => 'Secret12345!',
            'password_confirmation' => 'Secret12345!',
            'agreed' => true,
        ];
    }
}
