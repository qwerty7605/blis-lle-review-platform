<?php

namespace App\Jobs;

use App\Models\QuestionBank;
use App\Services\ReviewBotAdminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateReviewBotQuestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public int $bankId,
        public int $teacherId,
        public string $subject,
        public ?int $topicId,
        public ?int $subtopicId,
        /** How many exam items to cover, not how many questions to produce. */
        public int $sourceLimit,
        /** The Library file to generate from, when the teacher picked one directly (see ReviewBotAdminService::queueGeneration()). Null for a system-triggered top-up, which draws from every file matching subject/topic/subtopic. */
        public ?int $sourceBankId = null,
        /** Cache key held while this run is in flight, released in handle()/failed() so a teacher is not locked out for the full TTL after a run finishes. */
        public ?string $lockKey = null,
        /** @var list<int>|null For a student's top-up, the exam items that student may practise (see ReviewBotEligibility); null for no limit. */
        public ?array $sourceQuestionIds = null,
    ) {}

    /**
     * Release the in-flight lock however this ends -- including after the last
     * retry fails. Without this a crashed run would block regeneration for the
     * whole lock TTL.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->releaseLock();
    }

    private function releaseLock(): void
    {
        if ($this->lockKey !== null) {
            Cache::forget($this->lockKey);
        }
    }

    public function handle(ReviewBotAdminService $reviewBotAdminService): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            $this->releaseLock();

            return;
        }

        try {
            $result = $reviewBotAdminService->generate(
                $bank,
                $this->teacherId,
                $this->subject,
                $this->topicId,
                $this->subtopicId,
                $this->sourceLimit,
                $this->sourceBankId,
                $this->sourceQuestionIds,
            );
        } finally {
            // Released on the way out whether generation succeeded or threw --
            // a retry re-acquires nothing, it just runs.
            $this->releaseLock();
        }

        if (($result['rejected'] ?? []) !== []) {
            Log::info('Review Bot generation discarded near-duplicate questions.', [
                'bank_id' => $this->bankId,
                'rejected' => $result['rejected'],
            ]);
        }

        if (($result['errors'] ?? []) !== []) {
            Log::warning('Review Bot question generation finished with errors.', [
                'bank_id' => $this->bankId,
                'created' => $result['created'] ?? 0,
                'errors' => $result['errors'],
            ]);
        }
    }
}
