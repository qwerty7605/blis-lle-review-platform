<?php

namespace App\Services;

use App\Jobs\GenerateReviewBotQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Teacher-side Review Bot Question Generator: grounds AI generation in a
 * teacher's own uploaded library content, then persists the result as
 * draft QuestionBankQuestion rows (usage_type = review_bot) for the
 * teacher to review/edit and publish. This is the only place in the
 * Review Bot feature that ever calls OpenAI -- students only ever read
 * published rows (see ReviewBotService).
 */
class ReviewBotAdminService
{
    /**
     * Source items per API call. Four items yields up to sixteen questions in
     * one response -- large enough to be efficient, small enough that a
     * malformed response costs only a handful of items.
     */
    public const GENERATION_BATCH_SIZE = 4;

    /**
     * How many exam items an automatic top-up covers. Deliberately small --
     * this runs unattended, so a single thin session should not trigger a
     * large API bill. It only needs to make the *next* session for this area
     * better, not fully restock the bank in one pass.
     */
    private const TOP_UP_SOURCE_LIMIT = 3;

    /**
     * Once an area triggers a top-up, further sessions coming up short in the
     * same area are skipped for this long. The generation job itself already
     * skips items that already have variants, so this exists purely to stop
     * a burst of students hitting the same thin area from queuing redundant
     * jobs (and redundant API spend) before the first one has even run.
     */
    private const TOP_UP_DEBOUNCE_MINUTES = 360;

    public function __construct(
        private readonly QuestionMappingService $questionMappingService,
        private readonly ReviewBotQuestionScreeningService $screeningService,
    ) {
    }

    public function hasApiKey(): bool
    {
        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    public function missingConfigurationMessage(): string
    {
        return 'OPENAI_API_KEY is not configured, so Review Bot question generation is unavailable.';
    }

    /**
     * Finds or creates the per-subject bank that holds Review Bot content,
     * separate from teachers' assessment banks but living in the same
     * question_banks table.
     */
    public function reviewBotBankFor(string $subject, int $teacherId): QuestionBank
    {
        return QuestionBank::query()->firstOrCreate(
            ['title' => "Review Bot Bank — {$subject}", 'subject' => $subject],
            ['created_by' => $teacherId, 'total_items' => 0],
        );
    }

    /**
     * Resolves the teacher's chosen topic/subtopic name (from the same
     * curated list the item-mapping feature uses) into stable Topic/
     * Subtopic rows before queuing the background job, so generation
     * always attaches to a real topic_id/subtopic_id.
     *
     * @return array{status: string, message: string, bank_id: int}
     */
    public function queueGeneration(User $teacher, string $subject, ?string $topicName, ?string $subtopicName, int $sourceLimit): array
    {
        $bank = $this->reviewBotBankFor($subject, (int) $teacher->id);

        if (!$this->hasApiKey()) {
            return ['status' => 'skipped', 'message' => $this->missingConfigurationMessage(), 'bank_id' => (int) $bank->id];
        }

        $topic = filled($topicName) ? $this->questionMappingService->resolveTopic($subject, $topicName, $teacher->id) : null;
        $subtopic = $topic && filled($subtopicName)
            ? $this->questionMappingService->resolveSubtopic($topic, $subtopicName, $teacher->id)
            : null;

        GenerateReviewBotQuestionsJob::dispatch(
            (int) $bank->id,
            (int) $teacher->id,
            $subject,
            $topic?->id,
            $subtopic?->id,
            $sourceLimit,
        )->afterCommit();

        return [
            'status' => 'queued',
            'message' => sprintf(
                'Covering up to %d exam item(s) in the background, one review question per cognitive level each. They will appear as drafts below shortly.',
                $sourceLimit
            ),
            'bank_id' => (int) $bank->id,
        ];
    }

    /**
     * System-triggered counterpart to queueGeneration(): called when a
     * student's Review Bot session comes up short for a subject/topic/
     * subtopic, so the bank has more to offer next time without making that
     * student wait on a live call. Generated questions still land as drafts
     * for a teacher to review and publish -- this only ever queues the same
     * background job a teacher's own "Generate" click would.
     *
     * Deliberately quiet on every "can't do it right now" path (no bank set
     * up yet, no API key, already queued recently) -- there is no student-
     * facing error to surface, this is a best-effort background nicety.
     */
    public function queueTopUp(string $subject, ?int $topicId, ?int $subtopicId): void
    {
        if (!$this->hasApiKey()) {
            return;
        }

        $bank = QuestionBank::query()
            ->where('title', "Review Bot Bank — {$subject}")
            ->where('subject', $subject)
            ->first();

        // No teacher has ever generated for this subject -- nothing to attach
        // an automatic top-up to yet. A teacher's first manual generation
        // creates the bank; auto top-ups only ever add to one that exists.
        if (!$bank) {
            return;
        }

        $lockKey = sprintf(
            'review-bot-topup:%s:%s:%s',
            Str::slug($subject),
            $topicId ?? 'none',
            $subtopicId ?? 'none'
        );

        if (!Cache::add($lockKey, true, now()->addMinutes(self::TOP_UP_DEBOUNCE_MINUTES))) {
            return;
        }

        GenerateReviewBotQuestionsJob::dispatch(
            (int) $bank->id,
            (int) $bank->created_by,
            $subject,
            $topicId,
            $subtopicId,
            self::TOP_UP_SOURCE_LIMIT,
        )->afterCommit();
    }

    /**
     * Generates one Review Bot question per cognitive level for each exam item
     * in scope, linked back to that item through source_question_id.
     *
     * $sourceLimit caps how many *source items* a single run processes, not how
     * many questions come out -- each item yields up to four.
     *
     * @return array{created: int, sources_processed: int, rejected: array<int, array<string, string>>, errors: array<int, string>}
     */
    public function generate(QuestionBank $bank, int $teacherId, string $subject, ?int $topicId, ?int $subtopicId, int $sourceLimit): array
    {
        if (!$this->hasApiKey()) {
            return ['created' => 0, 'sources_processed' => 0, 'rejected' => [], 'errors' => [$this->missingConfigurationMessage()]];
        }

        $sourceQuestions = $this->resolveSourceQuestions($subject, $topicId, $subtopicId, $sourceLimit);

        if ($sourceQuestions->isEmpty()) {
            return [
                'created' => 0,
                'sources_processed' => 0,
                'rejected' => [],
                'errors' => ['Every exam item in this scope already has Review Bot questions, or no teacher-authored items exist for it yet.'],
            ];
        }

        $created = 0;
        $rejected = [];
        $errors = [];

        // Batched so one malformed response costs a handful of items rather than
        // the whole run, and so a large upload does not become one giant request.
        foreach ($sourceQuestions->chunk(self::GENERATION_BATCH_SIZE) as $chunk) {
            $chunk = $chunk->values();
            $generated = $this->requestGeneration($chunk, $subject);

            if ($generated === []) {
                $errors[] = sprintf('A batch of %d item(s) returned no usable questions.', $chunk->count());

                continue;
            }

            $screened = $this->screeningService->screen($generated, $subject, $topicId);
            $rejected = array_merge($rejected, $screened['rejected']);

            $created += $this->persistGenerated($screened['accepted'], $chunk, $bank, $subject);
        }

        $bank->update(['total_items' => $bank->questions()->count()]);

        return [
            'created' => $created,
            'sources_processed' => $sourceQuestions->count(),
            'rejected' => $rejected,
            'errors' => $errors,
        ];
    }

    /**
     * Writes one batch of generated questions, inheriting each question's
     * subject tagging from the exam item it came from rather than from the
     * generation request -- otherwise variants of a correctly tagged item would
     * land under whatever scope the teacher happened to pick.
     *
     * @param  array<int, array<string, mixed>>  $generated
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     */
    protected function persistGenerated(array $generated, Collection $sourceQuestions, QuestionBank $bank, string $subject): int
    {
        if ($generated === []) {
            return 0;
        }

        $nextItemNumber = (int) $bank->reviewBotQuestions()->max('item_number') + 1;
        $created = 0;

        DB::transaction(function () use ($generated, $sourceQuestions, $bank, $subject, &$nextItemNumber, &$created): void {
            foreach ($generated as $item) {
                $source = $sourceQuestions->get((int) ($item['source_index'] ?? -1));

                if (!$source) {
                    continue;
                }

                $question = QuestionBankQuestion::create([
                    'question_bank_id' => $bank->id,
                    'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                    'source_question_id' => $source->id,
                    'subject' => $subject,
                    'topic_id' => $source->topic_id,
                    'subtopic_id' => $source->subtopic_id,
                    'difficulty' => $item['difficulty'],
                    'cognitive_level' => $item['cognitive_level'],
                    'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
                    'tag_source' => QuestionBankQuestion::TAG_SOURCE_AI,
                    'explanation' => $item['explanation'],
                    'status' => QuestionBankQuestion::STATUS_DRAFT,
                    'verification_status' => $item['verification_status'] ?? QuestionBankQuestion::VERIFICATION_UNCHECKED,
                    'verifier_answer_label' => $item['verifier_answer_label'] ?? null,
                    'validation_flags' => ($item['validation_flags'] ?? []) !== [] ? $item['validation_flags'] : null,
                    'embedding' => $item['embedding'] ?? null,
                    'source_reference' => $source->questionBank?->title !== null
                        ? "Generated from: {$source->questionBank->title} (item {$source->item_number})"
                        : null,
                    'item_number' => $nextItemNumber,
                    'question_text' => $item['prompt'],
                    'question_type' => $item['type'],
                    'answer_label' => $item['correct_option_label'],
                    'answer_text' => $item['correct_option_text'],
                ]);

                foreach ($item['options'] as $sortOrder => $option) {
                    QuestionBankOption::create([
                        'question_bank_question_id' => $question->id,
                        'sort_order' => $sortOrder + 1,
                        'option_label' => $option['label'],
                        'option_text' => $option['text'],
                        'is_correct' => $option['is_correct'],
                    ]);
                }

                $nextItemNumber++;
                $created++;
            }
        });

        return $created;
    }

    /**
     * Exam items in scope that do not already have Review Bot questions.
     *
     * The whereDoesntHave clause is the duplicate guard that matters for this
     * design: embedding similarity cannot tell four intended cognitive variants
     * apart from a second round of four on the same item, but lineage can.
     *
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function resolveSourceQuestions(string $subject, ?int $topicId, ?int $subtopicId, int $limit): Collection
    {
        return QuestionBankQuestion::query()
            ->with([
                'questionBank:id,title',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ])
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('subject', $subject)
            ->when($subtopicId, fn ($query) => $query->where('subtopic_id', $subtopicId))
            ->when(!$subtopicId && $topicId, fn ($query) => $query->where('topic_id', $topicId))
            ->whereDoesntHave('generatedVariants')
            ->orderBy('item_number')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<string, mixed>>
     */
    protected function requestGeneration(Collection $sourceQuestions, string $subject): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        $systemPrompt = <<<'PROMPT'
You rewrite a teacher's exam items into review questions. A student who got the original item wrong will practise on your questions, so each one must test the SAME underlying concept as the item it came from -- never a different fact.

For EACH source item, produce one question at each cognitive level: recall, comprehension, application, analysis.

What the levels mean here:
- recall: state the fact directly, in different words from the source.
- comprehension: rephrase or reverse the relationship (give the definition, ask for the term).
- application: a short, realistic library scenario that requires the concept to resolve.
- analysis: compare, contrast, or distinguish the concept from a neighbouring one.

Rules:
- Return valid JSON only.
- Every question must carry "source_index": the index of the source item it came from, copied exactly.
- Every question must carry "cognitive_level": one of recall, comprehension, application, analysis.
- Do not copy the source wording, sentence structure, or answer choices verbatim.
- Each question must be either "multiple_choice" or "true_false".
- For "multiple_choice", provide exactly 4 options and exactly one correct option id.
- For "true_false", provide exactly 2 options: True and False, and identify the correct option id.
- Every distractor must be plausible to a student who half-knows the concept. Never pad with an obviously absurd option.
- Do not make the correct option noticeably longer than the distractors.
- Include a short explanation of why the correct answer is correct -- this is shown to the student after they answer.
- Assign a "difficulty" (easy, medium, or hard).
- If a source item genuinely cannot support one of the levels, omit that level for that item rather than inventing content to fill it.
- Never mention source item ids, item numbers, or teacher bank titles in the question text.
PROMPT;

        $userPrompt = [
            'subject' => $subject,
            'cognitive_levels' => QuestionBankQuestion::COGNITIVE_LEVELS,
            'sources' => $sourceQuestions->map(function (QuestionBankQuestion $question, int $index): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

                return [
                    'index' => $index,
                    'question_text' => trim((string) ($question->question_text ?? '')),
                    'answer_text' => trim((string) ($question->answer_text ?? $correctOption?->option_text ?? '')),
                    'options' => $question->options
                        ->map(fn (QuestionBankOption $option): string => trim((string) $option->option_text))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
            'response_schema' => [
                'questions' => [
                    [
                        'source_index' => 0,
                        'prompt' => 'string',
                        'type' => 'multiple_choice | true_false',
                        'options' => [['id' => 'a', 'text' => 'option text']],
                        'correct_option_id' => 'a',
                        'explanation' => 'string',
                        'difficulty' => 'easy | medium | hard',
                        'cognitive_level' => 'recall | comprehension | application | analysis',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(90)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.7,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => json_encode($userPrompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
                    ],
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return [];
        }

        $content = data_get($response, 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return $this->normalizeGeneratedQuestions($decoded['questions'] ?? []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeGeneratedQuestions(mixed $rawQuestions): array
    {
        if (!is_array($rawQuestions)) {
            return [];
        }

        $letterLabels = ['A', 'B', 'C', 'D'];
        $normalized = [];

        foreach ($rawQuestions as $question) {
            if (!is_array($question)) {
                continue;
            }

            // Without a usable source_index the question cannot be linked back
            // to an exam item, which is the whole point of the lineage.
            if (!isset($question['source_index']) || !is_numeric($question['source_index'])) {
                continue;
            }

            $sourceIndex = (int) $question['source_index'];
            $prompt = trim((string) ($question['prompt'] ?? ''));
            $type = trim((string) ($question['type'] ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE));
            $correctOptionId = trim((string) ($question['correct_option_id'] ?? ''));
            $explanation = trim((string) ($question['explanation'] ?? ''));
            $difficulty = Str::lower(trim((string) ($question['difficulty'] ?? 'medium')));
            $cognitiveLevel = Str::lower(trim((string) ($question['cognitive_level'] ?? 'comprehension')));
            $rawOptions = is_array($question['options'] ?? null) ? $question['options'] : [];

            if ($prompt === '' || !in_array($type, [QuestionBankQuestion::TYPE_MULTIPLE_CHOICE, QuestionBankQuestion::TYPE_TRUE_FALSE], true)) {
                continue;
            }

            $options = collect($rawOptions)
                ->map(function ($option) {
                    if (!is_array($option)) {
                        return null;
                    }

                    $id = trim((string) ($option['id'] ?? ''));
                    $text = trim((string) ($option['text'] ?? ''));

                    if ($id === '' || $text === '') {
                        return null;
                    }

                    return ['id' => $id, 'text' => $text];
                })
                ->filter()
                ->values();

            if ($options->count() < 2 || !$options->contains(fn (array $option): bool => $option['id'] === $correctOptionId)) {
                continue;
            }

            $formattedOptions = $options->map(fn (array $option, int $index): array => [
                'label' => $letterLabels[$index] ?? (string) ($index + 1),
                'text' => $option['text'],
                'is_correct' => $option['id'] === $correctOptionId,
            ])->values();

            $correctFormatted = $formattedOptions->first(fn (array $option): bool => $option['is_correct']);

            $normalized[] = [
                'source_index' => $sourceIndex,
                'prompt' => $prompt,
                'type' => $type,
                'options' => $formattedOptions->all(),
                'correct_option_label' => $correctFormatted['label'] ?? null,
                'correct_option_text' => $correctFormatted['text'] ?? null,
                'explanation' => $explanation !== '' ? $explanation : null,
                'difficulty' => in_array($difficulty, QuestionBankQuestion::DIFFICULTIES, true) ? $difficulty : QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'cognitive_level' => in_array($cognitiveLevel, QuestionBankQuestion::COGNITIVE_LEVELS, true) ? $cognitiveLevel : 'comprehension',
            ];
        }

        return $normalized;
    }

    /**
     * @param  array{subject?: string, topic_id?: int, status?: string}  $filters
     * @return Collection<int, QuestionBankQuestion>
     */
    public function listQuestions(array $filters = []): Collection
    {
        return QuestionBankQuestion::query()
            ->with(['topic:id,name', 'subtopic:id,name', 'options', 'questionBank:id,title,subject'])
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->when(filled($filters['subject'] ?? null), fn ($query) => $query->where('subject', $filters['subject']))
            ->when(filled($filters['topic_id'] ?? null), fn ($query) => $query->where('topic_id', (int) $filters['topic_id']))
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateQuestion(QuestionBankQuestion $question, array $data): QuestionBankQuestion
    {
        $question->fill(array_filter([
            'question_text' => $data['question_text'] ?? null,
            'explanation' => array_key_exists('explanation', $data) ? $data['explanation'] : null,
            'difficulty' => $data['difficulty'] ?? null,
            'cognitive_level' => $data['cognitive_level'] ?? null,
        ], fn ($value) => $value !== null));

        $question->save();

        if (isset($data['options']) && is_array($data['options'])) {
            foreach ($data['options'] as $optionData) {
                $optionId = (int) ($optionData['id'] ?? 0);
                if ($optionId < 1) {
                    continue;
                }

                QuestionBankOption::query()
                    ->where('id', $optionId)
                    ->where('question_bank_question_id', $question->id)
                    ->update([
                        'option_text' => (string) ($optionData['text'] ?? ''),
                        'is_correct' => (bool) ($optionData['is_correct'] ?? false),
                    ]);
            }

            $question->load('options');
            $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);
            $question->forceFill([
                'answer_label' => $correctOption?->option_label,
                'answer_text' => $correctOption?->option_text,
            ])->save();
        }

        return $question->refresh();
    }

    public function publish(array $questionIds, int $teacherId): int
    {
        return QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->whereIn('id', $questionIds)
            ->update(['status' => QuestionBankQuestion::STATUS_PUBLISHED]);
    }

    public function unpublish(array $questionIds): int
    {
        return QuestionBankQuestion::query()
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->whereIn('id', $questionIds)
            ->update(['status' => QuestionBankQuestion::STATUS_DRAFT]);
    }

    public function delete(QuestionBankQuestion $question): void
    {
        $question->delete();
    }
}
