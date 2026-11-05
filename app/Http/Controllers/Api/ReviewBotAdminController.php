<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Services\QuestionMappingService;
use App\Services\ReviewBotAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

class ReviewBotAdminController extends Controller
{
    public function __construct(
        private readonly ReviewBotAdminService $reviewBotAdminService,
        private readonly QuestionMappingService $questionMappingService,
    ) {
    }

    public function generate(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            // The Library file to generate from -- the single source of truth for
            // uploaded exam questions (see ReviewBotAdminService class docblock).
            // That's the only input: no topic/subtopic/count picking. The service
            // reads the whole file's own mapping and covers most of it automatically.
            'source_bank_id' => ['required', 'integer'],
        ]);

        $sourceBank = QuestionBank::query()->find($validated['source_bank_id']);

        if (!$sourceBank || !$this->oversees($user, $sourceBank->created_by)) {
            return response()->json(['message' => 'That file was not found in your Library.'], 404);
        }

        $result = $this->reviewBotAdminService->queueGeneration($user, $sourceBank);

        $this->recordAudit(
            $user,
            'review-bot.admin.generate',
            'question_bank',
            $result['bank_id'],
            'Queued Review Bot question generation',
            $validated,
            $request,
        );

        return response()->json($result);
    }

    public function questions(Request $request)
    {
        if (!$this->canTeach($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'topic_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(QuestionBankQuestion::STATUSES)],
            // Scopes to one Library file's generation batch -- see listQuestions().
            'source_bank_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'questions' => $this->reviewBotAdminService->listQuestions($validated),
            // Whether more drafts for this file are still on their way. The page
            // keeps reading this list for as long as it is true.
            'generating' => filled($validated['source_bank_id'] ?? null)
                && $this->reviewBotAdminService->isGenerating((int) $validated['source_bank_id']),
            // Every status, whichever one was asked for: the tabs show all three.
            'counts' => filled($validated['source_bank_id'] ?? null)
                ? $this->reviewBotAdminService->statusCounts((int) $validated['source_bank_id'])
                : null,
            // Which exams use this file and which of them keep practice closed:
            // a student gets these questions only once that exam's answers are
            // shown to them (ReviewBotEligibility), never from Hidden results.
            'reach' => filled($validated['source_bank_id'] ?? null)
                ? $this->reach((int) $validated['source_bank_id'])
                : null,
        ]);
    }

    /** @return array{exams: int, hidden: list<string>} */
    private function reach(int $bankId): array
    {
        $exams = Exam::query()
            ->where(fn ($query) => $query
                ->whereHas('questionBanks', fn ($banks) => $banks->where('question_banks.id', $bankId))
                ->orWhere('question_bank_id', $bankId))
            ->orderBy('title')
            ->get(['id', 'title', 'results_visibility_mode']);

        return [
            'exams' => $exams->count(),
            'hidden' => $exams->where('results_visibility_mode', '!=', 'visible')->pluck('title')->values()->all(),
        ];
    }

    public function updateQuestion(
        Request $request,
            QuestionBankQuestion $question,
    )
    {
        $user = $request->user();

        // Review Bot banks are shared per subject across every master examiner who
        // teaches it (see ReviewBotAdminService::reviewBotBankFor()), not owned by
        // whichever teacher happened to create the bank first -- so this is
        // deliberately just a usage_type check, unlike the Library's per-teacher
        // ownership checks on their own uploaded banks.
        if (!$this->canTeach($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_text' => ['nullable', 'string'],
            'explanation' => ['nullable', 'string'],
            'difficulty' => ['nullable', Rule::in(QuestionBankQuestion::DIFFICULTIES)],
            'cognitive_level' => ['nullable', Rule::in(QuestionBankQuestion::COGNITIVE_LEVELS)],
            // Re-mapping ("map/assign"): corrects or narrows the topic/subtopic
            // this question inherited from its source item, before publishing.
            'topic_name' => ['nullable', 'string', 'max:255'],
            'subtopic_name' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
            'options.*.id' => ['required_with:options', 'integer'],
            'options.*.text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
        ]);

        // Only TOS topics and sub-topics; a sub-topic sent on its own belongs
        // to the topic the question already has.
        $tagProblem = $this->questionMappingService->tagProblem(
            $question->subject,
            filled($validated['topic_name'] ?? null) ? $validated['topic_name'] : $question->topic?->name,
            $validated['subtopic_name'] ?? null,
        );

        if ($tagProblem !== null && (filled($validated['topic_name'] ?? null) || filled($validated['subtopic_name'] ?? null))) {
            return response()->json(['message' => $tagProblem, 'errors' => ['topic_name' => [$tagProblem]]], 422);
        }

        $question = $this->reviewBotAdminService->updateQuestion($question, $validated);

        $this->recordAudit($user, 'review-bot.admin.update-question', 'question_bank_question', $question->id, 'Edited a Review Bot draft question', [], $request);

        return response()->json(['question' => $question]);
    }

    public function publish(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer'],
        ]);

        $published = $this->reviewBotAdminService->publish($validated['question_ids'], $user->id);

        $this->recordAudit($user, 'review-bot.admin.publish', 'question_bank_question', null, sprintf('Published %d Review Bot question(s)', $published), $validated, $request);

        return response()->json(['published' => $published]);
    }

    public function reject(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer'],
        ]);

        // Declining keeps the row. Deleting it would make the source exam item
        // look untouched to the next generation run, which would then produce the
        // same rejected question again.
        $rejected = $this->reviewBotAdminService->reject($validated['question_ids']);

        $this->recordAudit($user, 'review-bot.admin.reject', 'question_bank_question', null, sprintf('Declined %d Review Bot question(s)', $rejected), $validated, $request);

        return response()->json(['rejected' => $rejected]);
    }

    public function coverage(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'source_bank_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'coverage' => $this->reviewBotAdminService->coverageReport(
                $validated['subject'],
                isset($validated['source_bank_id']) ? (int) $validated['source_bank_id'] : null,
            ),
        ]);
    }

    public function unpublish(Request $request)
    {
        $user = $request->user();

        if (!$this->canTeach($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer'],
        ]);

        $unpublished = $this->reviewBotAdminService->unpublish($validated['question_ids']);

        $this->recordAudit($user, 'review-bot.admin.unpublish', 'question_bank_question', null, sprintf('Unpublished %d Review Bot question(s)', $unpublished), $validated, $request);

        return response()->json(['unpublished' => $unpublished]);
    }

    public function destroyQuestion(
        Request $request,
            QuestionBankQuestion $question,
    )
    {
        $user = $request->user();

        // Shared per-subject bank -- see the matching note on the PATCH route above.
        if (!$this->canTeach($user) || $question->usage_type !== QuestionBankQuestion::USAGE_REVIEW_BOT) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $questionId = $question->id;
        $this->reviewBotAdminService->delete($question);

        $this->recordAudit($user, 'review-bot.admin.delete-question', 'question_bank_question', $questionId, 'Deleted a Review Bot question', [], $request);

        return response()->json(['deleted' => true]);
    }
}
