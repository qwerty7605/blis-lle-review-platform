<?php

namespace App\Jobs;

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\RemedialQuestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class GenerateRemedialQuestionsJob implements ShouldQueue
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
    ) {}

    public function handle(RemedialQuestionService $remedialQuestionService): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            return;
        }

        if (!$remedialQuestionService->hasApiKey()) {
            $remedialQuestionService->markBankGenerationStatus(
                $bank,
                QuestionBank::REMEDIAL_GENERATION_STATUS_SKIPPED,
                $remedialQuestionService->missingConfigurationMessage(),
                [
                    'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                    'remedial_generation_completed_at' => now(),
                ],
            );

            return;
        }

        $remedialQuestionService->markBankGenerationStatus(
            $bank,
            QuestionBank::REMEDIAL_GENERATION_STATUS_PROCESSING,
            'AI remedial generation is running in the background.',
            [
                'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                'remedial_generation_completed_at' => null,
            ],
        );

        $sourceQuestions = QuestionBankQuestion::query()
            ->with([
                'questionBank:id,subject',
                'topic:id,subject,name',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
                'remedialVariants:id,source_question_id,item_style,status',
            ])
            ->where('question_bank_id', $bank->id)
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->when(
                $this->sourceQuestionIds !== [],
                fn ($query) => $query->whereIn('id', $this->sourceQuestionIds)
            )
            ->orderBy('item_number')
            ->get();

        $candidateIds = $remedialQuestionService->collectGenerationCandidateIds($sourceQuestions);

        if ($candidateIds === []) {
            $remedialQuestionService->markBankGenerationStatus(
                $bank,
                QuestionBank::REMEDIAL_GENERATION_STATUS_COMPLETED,
                'All assessment questions in this bank already have stored remedial variants.',
                [
                    'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                    'remedial_generation_completed_at' => now(),
                ],
            );

            return;
        }

        $generation = $remedialQuestionService->generateForAssessmentQuestions($sourceQuestions);
        $created = (int) ($generation['created'] ?? 0);
        $errors = collect($generation['errors'] ?? [])
            ->filter(fn ($error): bool => is_string($error) && trim($error) !== '')
            ->values();

        if ($created === 0 && $errors->isNotEmpty()) {
            $remedialQuestionService->markBankGenerationStatus(
                $bank,
                QuestionBank::REMEDIAL_GENERATION_STATUS_FAILED,
                Str::limit('AI remedial generation failed. ' . $errors->implode(' '), 500),
                [
                    'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                    'remedial_generation_completed_at' => now(),
                ],
            );

            return;
        }

        $message = $created > 0
            ? sprintf('Generated %d remedial question(s) for faculty review.', $created)
            : 'No new remedial questions were generated because all assessment items already have stored variants.';

        if ($errors->isNotEmpty()) {
            $message .= ' Some items were skipped: ' . $errors->first();

            if ($errors->count() > 1) {
                $message .= sprintf(' (+%d more)', $errors->count() - 1);
            }
        }

        $remedialQuestionService->markBankGenerationStatus(
            $bank,
            QuestionBank::REMEDIAL_GENERATION_STATUS_COMPLETED,
            Str::limit($message, 500),
            [
                'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                'remedial_generation_completed_at' => now(),
            ],
        );
    }

    public function failed(Throwable $exception): void
    {
        $bank = QuestionBank::query()->find($this->bankId);

        if (!$bank) {
            return;
        }

        app(RemedialQuestionService::class)->markBankGenerationStatus(
            $bank,
            QuestionBank::REMEDIAL_GENERATION_STATUS_FAILED,
            Str::limit('AI remedial generation failed. ' . $exception->getMessage(), 500),
            [
                'remedial_generation_requested_at' => $bank->remedial_generation_requested_at ?? now(),
                'remedial_generation_completed_at' => now(),
            ],
        );
    }
}
