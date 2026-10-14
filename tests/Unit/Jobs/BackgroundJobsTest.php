<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ClassifyQuestionBankQuestionsJob;
use App\Jobs\GenerateReviewBotQuestionsJob;
use App\Models\QuestionBank;
use App\Services\QuestionMappingService;
use App\Services\ReviewBotAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\BuildsBlisData;
use Tests\TestCase;

/**
 * The two queued jobs: topic tagging for a Library set and Review Bot
 * generation. Each is run directly, for the outcomes the feature tests do
 * not reach.
 */
class BackgroundJobsTest extends TestCase
{
    use BuildsBlisData;
    use RefreshDatabase;

    private function mappingWith(array $result): QuestionMappingService
    {
        return $this->partialMock(QuestionMappingService::class, function ($mock) use ($result): void {
            $mock->shouldReceive('classifyForAssessmentQuestions')->andReturn($result);
        });
    }

    public function test_tagging_a_set_that_no_longer_exists_does_nothing(): void
    {
        (new ClassifyQuestionBankQuestionsJob(999999))->handle(app(QuestionMappingService::class));

        $this->assertSame(0, QuestionBank::count());
    }

    public function test_tagging_a_set_whose_items_all_have_topics_completes_at_once(): void
    {
        $teacher = $this->makeTeacher();
        $topic = $this->makeTopic();
        $bank = $this->makeBank($teacher, 2, [], ['topic_id' => $topic->id]);

        (new ClassifyQuestionBankQuestionsJob($bank->id))->handle(app(QuestionMappingService::class));

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_COMPLETED, $bank->mapping_status);
        $this->assertSame('Every assessment item in this set already has a topic assigned.', $bank->mapping_message);
    }

    public function test_tagging_that_tags_nothing_and_reports_errors_fails_the_set(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 2);

        (new ClassifyQuestionBankQuestionsJob($bank->id))->handle($this->mappingWith(['updated' => 0, 'errors' => ['No topic matched.']]));

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_FAILED, $bank->mapping_status);
        $this->assertStringContainsString('Item mapping failed. No topic matched.', $bank->mapping_message);
    }

    public function test_a_partly_tagged_set_completes_with_a_note_on_the_errors(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 2);

        (new ClassifyQuestionBankQuestionsJob($bank->id, [], QuestionBank::MAPPING_MODE_AUTO))
            ->handle($this->mappingWith(['updated' => 1, 'errors' => ['First problem.', 'Second problem.', ' ']]));

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_COMPLETED, $bank->mapping_status);
        $this->assertStringContainsString('applied automatically', $bank->mapping_message);
        $this->assertStringContainsString('Note: First problem. (+1 more)', $bank->mapping_message);
    }

    public function test_a_tagging_job_that_crashed_marks_the_set_failed(): void
    {
        $bank = $this->makeBank($this->makeTeacher(), 1);

        (new ClassifyQuestionBankQuestionsJob($bank->id))->failed(new \RuntimeException('Worker died'));
        (new ClassifyQuestionBankQuestionsJob(999999))->failed(new \RuntimeException('Gone'));

        $bank->refresh();
        $this->assertSame(QuestionBank::MAPPING_STATUS_FAILED, $bank->mapping_status);
        $this->assertSame('Item mapping failed. Worker died', $bank->mapping_message);
    }

    public function test_generation_for_a_missing_bank_releases_its_lock(): void
    {
        Cache::put('review-bot-lock-test', true, 600);

        (new GenerateReviewBotQuestionsJob(999999, 1, 'Reference Services', null, null, 5, null, 'review-bot-lock-test'))
            ->handle(app(ReviewBotAdminService::class));

        $this->assertFalse(Cache::has('review-bot-lock-test'));
    }

    public function test_a_crashed_generation_releases_its_lock(): void
    {
        Cache::put('review-bot-lock-crash', true, 600);

        (new GenerateReviewBotQuestionsJob(1, 1, 'Reference Services', null, null, 5, null, 'review-bot-lock-crash'))
            ->failed(new \RuntimeException('Timed out'));

        $this->assertFalse(Cache::has('review-bot-lock-crash'));
    }

    public function test_discarded_duplicates_are_logged(): void
    {
        $bank = QuestionBank::create(['title' => 'RB', 'subject' => 'Reference Services', 'total_items' => 0, 'created_by' => $this->makeTeacher()->id]);
        $service = $this->partialMock(ReviewBotAdminService::class, function ($mock): void {
            $mock->shouldReceive('generate')->andReturn(['created' => 1, 'rejected' => [['reason' => 'near_duplicate']], 'errors' => []]);
        });
        Log::spy();

        (new GenerateReviewBotQuestionsJob($bank->id, 1, 'Reference Services', null, null, 5))->handle($service);

        Log::shouldHaveReceived('info')->withArgs(fn ($message) => str_contains($message, 'near-duplicate'))->once();
    }
}
