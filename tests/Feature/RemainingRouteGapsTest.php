<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\LleResult;
use App\Models\User;
use App\Services\Demo\DemoDataPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The last route branches the other tests leave out: licensure and
 * prediction routes for a disabled account or a student, activating a model
 * version that does not exist, auditing an administrator's edit of a
 * teacher's question, an exam saved with only the older single-set field,
 * an email change to an address someone else took, a student's analytics
 * for an exam with no subject of its own, and the demo purge with nothing
 * to purge.
 */
class RemainingRouteGapsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_licensure_and_prediction_routes_sign_out_a_disabled_account(): void
    {
        $admin = $this->makeAdmin(['is_active' => false]);
        $result = LleResult::create(['user_id' => $this->makeStudent()->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);
        Sanctum::actingAs($admin);

        foreach ([
            ['GET', '/api/lle-results'],
            ['POST', '/api/lle-results'],
            ['GET', '/api/lle-results/batch'],
            ['POST', '/api/lle-results/batch'],
            ['DELETE', "/api/lle-results/{$result->id}"],
            ['POST', '/api/predictions/let'],
            ['GET', '/api/predictions/let/model'],
            ['POST', '/api/predictions/let/model/retrain'],
            ['POST', '/api/predictions/let/model/activate'],
        ] as [$method, $path]) {
            $this->json($method, $path)->assertForbidden()->assertJsonPath('message', 'Account is disabled.');
        }
    }

    public function test_a_student_cannot_delete_a_licensure_result_or_ask_for_a_prediction(): void
    {
        $result = LleResult::create(['user_id' => $this->makeStudent()->id, 'exam_year' => 2025, 'result' => LleResult::RESULT_PASS]);
        Sanctum::actingAs($this->makeStudent());

        $this->deleteJson("/api/lle-results/{$result->id}")->assertForbidden();
        $this->postJson('/api/predictions/let', ['scores' => []])->assertForbidden();
    }

    public function test_activating_a_model_version_that_does_not_exist_is_refused(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson('/api/predictions/let/model/activate', ['version' => 99])->assertStatus(422);
    }

    public function test_an_administrator_editing_a_teacher_s_question_is_audited_as_theirs(): void
    {
        $teacher = $this->makeTeacher(['name' => 'Ms. Reyes']);
        $question = $this->makeBank($teacher, 1)->questions->first();
        Sanctum::actingAs($this->makeAdmin());

        $this->patchJson("/api/library/questions/{$question->id}", ['question_text' => 'Reworded by the admin?'])->assertOk();

        $log = AuditLog::query()->where('target_type', 'question_bank_question')->latest('id')->firstOrFail();
        $this->assertSame($teacher->id, $log->metadata['owner_id']);
        $this->assertStringContainsString('(belongs to Ms. Reyes)', $log->description);
    }

    public function test_an_exam_can_still_be_saved_with_only_the_older_single_set_field(): void
    {
        $teacher = $this->makeTeacher();
        $bank = $this->makeBank($teacher, 3);
        Sanctum::actingAs($teacher);

        $id = $this->postJson('/api/exams', [
            'title' => 'Old Client',
            'question_bank_id' => $bank->id,
            'total_items' => 3,
            'duration_minutes' => 30,
        ])->assertCreated()->json('exam.id');

        $this->assertSame([$bank->id], Exam::findOrFail($id)->resolvedQuestionBankIds());

        $this->patchJson("/api/exams/{$id}", ['question_bank_id' => null, 'total_items' => 3])->assertOk();
        $this->assertSame([], Exam::findOrFail($id)->resolvedQuestionBankIds());
    }

    public function test_an_email_change_to_an_address_someone_else_took_meanwhile_is_turned_away(): void
    {
        $teacher = $this->makeTeacher(['email' => 'old@lnu.edu.ph']);
        $this->makeTeacher(['email' => 'taken@lnu.edu.ph']);
        $url = URL::temporarySignedRoute('account.email.confirm', now()->addHour(), [
            'id' => $teacher->id, 'hash' => sha1('old@lnu.edu.ph'), 'email' => 'taken@lnu.edu.ph',
        ]);

        $this->get($url)->assertRedirect(rtrim((string) config('app.frontend_url'), '/') . '/login?email_changed=invalid');
        $this->assertSame('old@lnu.edu.ph', $teacher->fresh()->email);
    }

    public function test_an_exam_with_no_subject_of_its_own_takes_its_set_s_in_the_student_s_analytics(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $fromSet = $this->makeExam($teacher, $this->makeBank($teacher, 2, ['subject' => 'Cataloging']), [$room], ['subject' => '']);
        $noSubject = $this->makeExam($teacher, $this->makeBank($teacher, 2, ['subject' => '']), [$room], ['subject' => '']);
        $this->makeSubmittedAttempt($fromSet, $room, $student, [true, false]);
        $this->makeSubmittedAttempt($noSubject, $room, $student, [true, true]);
        Sanctum::actingAs($student);

        $subjects = collect($this->getJson('/api/student/analytics')->assertOk()->json('subjects'))->keyBy('label');

        // The exam's own subject is blank, so its set's "Cataloging" is used.
        $this->assertSame(1, $subjects['Cataloging and Classification']['attempts_count']);
    }

    public function test_the_purge_command_says_so_when_there_is_no_demo_data(): void
    {
        $this->assertSame(0, (new DemoDataPurger())->plan()['accounts']);

        $this->artisan('demo:purge')->expectsOutput('No demo data found.')->assertSuccessful();
    }
}
