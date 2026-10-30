<?php

namespace Tests\Feature;

use App\Models\QuestionBankQuestion;
use App\Services\DashboardAnalyticsService;
use App\Services\QuestionMappingService;
use App\Support\LleTaxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * `tos:keyword-report` tags questions with the TOS keywords and counts, per
 * subject, how many got a sub-topic, went to Other, or would wait for review.
 * Also: an "Other / Unclassified" question counts toward its subject but is
 * never a topic of its own in the analytics.
 */
class TosKeywordReportTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    public function test_a_set_in_the_database_is_counted_per_outcome(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 0, ['subject' => 'Cataloging']);
        // A clear sub-topic, no keyword at all, and two topics pulling equally.
        $this->makeQuestion($bank, 1, ['question_text' => 'Who devised the Dewey Decimal Classification?']);
        $this->makeQuestion($bank, 2, ['question_text' => 'Zzz qqq xxyy?']);
        $this->makeQuestion($bank, 3, ['question_text' => 'Is a call number a kind of metadata?']);

        $this->artisan('tos:keyword-report', ['--bank' => [$bank->id], '--details' => true])
            ->expectsTable(['Subject', 'Questions', 'Got a sub-topic', 'Went to Other', 'Await review'], [
                ['Cataloging and Classification', 3, 1, 1, 1],
                ['All subjects', 3, 1, 1, 1],
            ])
            ->assertSuccessful();

        // Nothing was saved.
        $this->assertSame(0, QuestionBankQuestion::query()->whereNotNull('topic_id')->count());
    }

    public function test_a_word_file_is_read_as_the_library_import_reads_it(): void
    {
        $this->artisan('tos:keyword-report', ['--docx' => [base_path('docs/testing/Sample-Cataloging-10-items.docx')]])
            ->expectsOutputToContain('Cataloging and Classification')
            ->expectsOutputToContain('154 sub-topics')
            ->assertSuccessful();
    }

    public function test_nothing_to_tag_is_said_and_fails(): void
    {
        $this->artisan('tos:keyword-report')
            ->expectsOutput('No questions to tag. Pass --bank, --all-banks or --docx.')
            ->assertFailed();

        $general = $this->makeBank($this->makeTeacher(), 1, ['subject' => 'General']);
        $this->artisan('tos:keyword-report', ['--all-banks' => true])
            ->expectsOutputToContain('has no board subject')
            ->assertFailed();
        $this->assertNotNull($general);
    }

    public function test_other_counts_toward_the_subject_but_is_never_a_topic(): void
    {
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $mapping = app(QuestionMappingService::class);
        $subject = 'Cataloging and Classification';
        $other = $mapping->resolveTopic($subject, LleTaxonomy::OTHER_TOPIC);
        $classification = $mapping->resolveTopic($subject, '4');

        $bank = $this->makeBank($teacher, 0, ['subject' => $subject]);
        $this->makeQuestion($bank, 1, ['topic_id' => $other->id]);
        $this->makeQuestion($bank, 2, ['topic_id' => $classification->id]);
        $room = $this->makeRoom($teacher, [$student]);
        $exam = $this->makeExam($teacher, $bank, [$room]);
        $this->makeSubmittedAttempt($exam, $room, $student, [true, false]);

        $analytics = app(DashboardAnalyticsService::class);
        $topics = collect($analytics->buildTopicWeaknessPerformance($student));
        $subjects = collect($analytics->buildSubjectWeaknessPerformance($student));

        $this->assertSame(['4. Classification'], $topics->pluck('label')->all());
        $this->assertSame(2, (int) $subjects->firstWhere('label', $subject)['attempted_count']);
    }
}
