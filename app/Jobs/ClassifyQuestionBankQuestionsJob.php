<?php

namespace App\Jobs;

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ClassifyQuestionBankQuestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  array<int, int>  $sourceQuestionIds
     */
    public function __construct(
        public int $bankId,
        public array $sourceQuestionIds = [],
        public string $mode = QuestionBank::MAPPING_MODE_REVIEW,
    ) {}

    public function handle(QuestionMappingService $questionMappingService): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            return;
        }

        $questionMappingService->markBankMappingStatus(
            $bank,
            QuestionBank::MAPPING_STATUS_PROCESSING,
            'Item mapping is running in the background.',
            [
                'mapping_requested_at' => $bank->mapping_requested_at ?? now(),
                'mapping_completed_at' => null,
            ],
        );

        $sourceQuestions = QuestionBankQuestion::query()
            ->with(['options:id,question_bank_question_id,option_label,option_text,is_correct'])
            ->where('question_bank_id', $bank->id)
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->whereNull('topic_id')
            ->when($this->sourceQuestionIds !== [], fn ($query) => $query->whereIn('id', $this->sourceQuestionIds))
            ->orderBy('item_number')
            ->get();

        if ($sourceQuestions->isEmpty()) {
            $questionMappingService->markBankMappingStatus(
                $bank,
                QuestionBank::MAPPING_STATUS_COMPLETED,
                'Every assessment item in this set already has a topic assigned.',
                [
                    'mapping_requested_at' => $bank->mapping_requested_at ?? now(),
                    'mapping_completed_at' => now(),
                ],
            );

            return;
        }

        $result = $questionMappingService->classifyForAssessmentQuestions($sourceQuestions, $this->mode);
        $updated = (int) ($result['updated'] ?? 0);
        $errors = collect($result['errors'] ?? [])
            ->filter(fn ($error): bool => is_string($error) && trim($error) !== '')
            ->values();

        if ($updated === 0 && $errors->isNotEmpty()) {
            $questionMappingService->markBankMappingStatus(
                $bank,
                QuestionBank::MAPPING_STATUS_FAILED,
                Str::limit('Item mapping failed. ' . $errors->implode(' '), 500),
                [
                    'mapping_requested_at' => $bank->mapping_requested_at ?? now(),
                    'mapping_completed_at' => now(),
                ],
            );

            return;
        }

        $message = $this->mode === QuestionBank::MAPPING_MODE_AUTO
            ? sprintf('Mapped %d item(s) — topics, difficulty, and cognitive level applied automatically.', $updated)
            : sprintf('Mapped %d item(s) — suggestions are waiting for your review.', $updated);

        if ($errors->isNotEmpty()) {
            $message .= ' Note: ' . $errors->first();

            if ($errors->count() > 1) {
                $message .= sprintf(' (+%d more)', $errors->count() - 1);
            }
        }

        $questionMappingService->markBankMappingStatus(
            $bank,
            QuestionBank::MAPPING_STATUS_COMPLETED,
            Str::limit($message, 500),
            [
                'mapping_requested_at' => $bank->mapping_requested_at ?? now(),
                'mapping_completed_at' => now(),
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            return;
        }

        app(QuestionMappingService::class)->markBankMappingStatus(
            $bank,
            QuestionBank::MAPPING_STATUS_FAILED,
            Str::limit('Item mapping failed. ' . $exception->getMessage(), 500),
            [
                'mapping_requested_at' => $bank->mapping_requested_at ?? now(),
                'mapping_completed_at' => now(),
            ],
        );
    }
}
