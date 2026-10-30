<?php

namespace Tests\Unit\Models;

use App\Exceptions\StudentHasAcademicHistoryException;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\LetModel;
use App\Models\LleResult;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\ReviewBotAttempt;
use App\Models\ReviewBotAttemptAnswer;
use App\Models\ReviewBotAttemptQuestion;
use App\Models\ReviewBotItemState;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * Every model's relationships, read back through the model, plus the model
 * rules that are not relationships: the archived-students scope, refusing to
 * delete a student with a licensure result, an exam's active and archived
 * rooms, and naming the subject of several question sets.
 */
class ModelRelationshipsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_a_user_reaches_what_they_created_and_did(): void
    {
        $teacher = $this->makeTeacher();
        $room = $this->makeRoom($teacher);
        $bank = $this->makeBank($teacher, 1);
        $exam = $this->makeExam($teacher, $bank);
        $log = AuditLog::create(['actor_id' => $teacher->id, 'action' => 'test', 'description' => 'x']);

        $this->assertTrue($teacher->createdRooms->contains($room));
        $this->assertTrue($teacher->createdExams->contains($exam));
        $this->assertTrue($teacher->createdQuestionBanks->contains($bank));
        $this->assertTrue($teacher->auditLogs->contains($log));
    }

    public function test_the_archived_students_scope_lists_only_archived_students(): void
    {
        $archived = $this->makeStudent(['archived_at' => now()]);
        $this->makeStudent();
        $this->makeTeacher(['archived_at' => now()]);

        $this->assertSame([$archived->id], User::query()->archivedStudents()->pluck('id')->all());
    }

    public function test_a_student_with_a_licensure_result_cannot_be_deleted(): void
    {
        $student = $this->makeStudent();
        LleResult::create([
            'user_id' => $student->id,
            'exam_year' => 2025,
            'result' => LleResult::RESULT_PASS,
            'recorded_by' => $this->makeAdmin()->id,
        ]);

        $this->expectException(StudentHasAcademicHistoryException::class);
        $this->expectExceptionMessage('they have a recorded licensure result');

        $student->delete();
    }

    public function test_an_exam_splits_its_rooms_into_active_and_archived(): void
    {
        $teacher = $this->makeTeacher();
        [$active, $archived] = [$this->makeRoom($teacher), $this->makeRoom($teacher)];
        $exam = $this->makeExam($teacher, null, [$active, $archived]);
        $exam->rooms()->updateExistingPivot($archived->id, ['archived_at' => now()]);

        $this->assertSame([$active->id], $exam->activeRooms()->pluck('rooms.id')->all());
        $this->assertSame([$archived->id], $exam->archivedRooms()->pluck('rooms.id')->all());
    }

    public function test_question_sets_of_more_than_one_subject_are_named_multiple_subjects(): void
    {
        $teacher = $this->makeTeacher();
        $one = $this->makeBank($teacher, 1, ['subject' => 'Cataloging']);
        $two = $this->makeBank($teacher, 1, ['subject' => 'Indexing']);
        $blank = $this->makeBank($teacher, 1, ['subject' => '']);

        $this->assertSame('Multiple Subjects', Exam::summarizeQuestionBankSubject([$one, $two]));
        // A board subject under its official name, whatever spelling the set has.
        $this->assertSame('Cataloging and Classification', Exam::summarizeQuestionBankSubject([$one, $one]));
        $official = $this->makeBank($teacher, 1, ['subject' => 'Cataloging and Classification']);
        $this->assertSame('Cataloging and Classification', Exam::summarizeQuestionBankSubject([$one, $official]));
        $this->assertSame('Archival Studies', Exam::summarizeQuestionBankSubject([$this->makeBank($teacher, 1, ['subject' => 'Archival Studies'])]));
        $this->assertNull(Exam::summarizeQuestionBankSubject([$blank]));
    }

    public function test_question_sets_questions_and_choices_reach_each_other_and_their_answers(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $room = $this->makeRoom($teacher, [$student]);
        $bank = $this->makeBank($teacher, 1);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $attempt = $this->makeSubmittedAttempt($exam, $room, $student, [true]);
        $question = $bank->questions->first();
        $option = $question->options->first();

        $this->assertTrue($bank->exams->contains($exam));
        $this->assertTrue($bank->primaryExams->contains($exam));
        $this->assertTrue($option->question->is($question));
        $this->assertCount(1, $option->attemptAnswers);
        $this->assertCount(1, $question->attemptQuestions);
        $this->assertCount(1, $question->attemptAnswers);

        $answer = ExamAttemptAnswer::firstOrFail();
        $this->assertTrue($answer->attempt->is($attempt));
        $this->assertTrue($answer->question->is($question));
        $this->assertTrue(ExamAttemptQuestion::firstOrFail()->attempt->is($attempt));
    }

    public function test_topics_and_subtopics_reach_their_parent_creator_and_questions(): void
    {
        $teacher = $this->makeTeacher();
        $topic = $this->makeTopic('Cataloging', 'MARC', $teacher);
        $subtopic = $this->makeSubtopic($topic, 'Fixed fields', $teacher);
        $bank = $this->makeBank($teacher, 1, ['subject' => 'Cataloging'], ['topic_id' => $topic->id, 'subtopic_id' => $subtopic->id]);

        $this->assertTrue($topic->creator->is($teacher));
        $this->assertTrue($topic->subtopics->contains($subtopic));
        $this->assertTrue($topic->questions->contains($bank->questions->first()));
        $this->assertTrue($subtopic->topic->is($topic));
        $this->assertTrue($subtopic->creator->is($teacher));
        $this->assertTrue($subtopic->questions->contains($bank->questions->first()));
    }

    public function test_review_bot_records_reach_each_other(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $question = $this->makeBank($teacher, 1)->questions->first();
        $option = $question->options->first();

        $attempt = ReviewBotAttempt::create([
            'user_id' => $student->id, 'subject' => 'Reference, Bibliography and User Services', 'source' => ReviewBotAttempt::SOURCE_MANUAL,
            'status' => ReviewBotAttempt::STATUS_SUBMITTED, 'total_items' => 1, 'answered_count' => 1, 'correct_answers' => 1,
            'started_at' => now(),
        ]);
        $attemptQuestion = ReviewBotAttemptQuestion::create(['review_bot_attempt_id' => $attempt->id, 'question_bank_question_id' => $question->id, 'item_number' => 1]);
        $answer = ReviewBotAttemptAnswer::create([
            'review_bot_attempt_id' => $attempt->id, 'question_bank_question_id' => $question->id,
            'question_bank_option_id' => $option->id, 'is_correct' => true, 'answered_at' => now(),
        ]);
        $state = ReviewBotItemState::create(['user_id' => $student->id, 'question_bank_question_id' => $question->id]);

        $this->assertTrue($attempt->answers->contains($answer));
        $this->assertTrue($attemptQuestion->attempt->is($attempt));
        $this->assertTrue($answer->attempt->is($attempt));
        $this->assertTrue($answer->question->is($question));
        $this->assertTrue($answer->selectedOption->is($option));
        $this->assertTrue($state->user->is($student));
        $this->assertTrue($state->question->is($question));
    }

    public function test_settings_and_models_name_who_changed_them(): void
    {
        $admin = $this->makeAdmin();
        $setting = SystemSetting::create(['key' => 'announcement_banner', 'value' => 'Exam week', 'updated_by' => $admin->id]);
        $model = LetModel::query()->where('version', 1)->sole()->replicate();
        $model->forceFill(['version' => 2, 'status' => 'retired', 'trained_by' => $admin->id, 'trigger' => 'manual'])->save();

        $this->assertTrue($setting->updater->is($admin));
        $this->assertTrue($model->trainer->is($admin));
    }
}
