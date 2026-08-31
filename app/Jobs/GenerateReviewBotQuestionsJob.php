<?php

namespace App\Jobs;

use App\Models\QuestionBank;
use App\Services\ReviewBotAdminService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        public int $questionCount,
    ) {}

    public function handle(ReviewBotAdminService $reviewBotAdminService): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            return;
        }

        $result = $reviewBotAdminService->generate(
            $bank,
            $this->teacherId,
            $this->subject,
            $this->topicId,
            $this->subtopicId,
            $this->questionCount,
        );

        if (($result['errors'] ?? []) !== []) {
            Log::warning('Review Bot question generation finished with errors.', [
                'bank_id' => $this->bankId,
                'created' => $result['created'] ?? 0,
                'errors' => $result['errors'],
            ]);
        }
    }
}
