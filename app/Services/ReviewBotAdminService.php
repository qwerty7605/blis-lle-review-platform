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
    public function __construct(
        private readonly QuestionMappingService $questionMappingService,
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
    public function queueGeneration(User $teacher, string $subject, ?string $topicName, ?string $subtopicName, int $questionCount): array
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
            $questionCount,
        )->afterCommit();

        return [
            'status' => 'queued',
            'message' => sprintf('Generating %d review question(s) in the background. They will appear as drafts below shortly.', $questionCount),
            'bank_id' => (int) $bank->id,
        ];
    }

    /**
     * @return array{created: int, errors: array<int, string>}
     */
    public function generate(QuestionBank $bank, int $teacherId, string $subject, ?int $topicId, ?int $subtopicId, int $questionCount): array
    {
        if (!$this->hasApiKey()) {
            return ['created' => 0, 'errors' => [$this->missingConfigurationMessage()]];
        }

        $topic = $topicId ? Topic::query()->find($topicId) : null;
        $subtopic = $subtopicId ? Subtopic::query()->find($subtopicId) : null;

        $sourceQuestions = $this->resolveSourceQuestions($subject, $topicId, $subtopicId, $questionCount);

        if ($sourceQuestions->isEmpty()) {
            return ['created' => 0, 'errors' => ['No teacher-authored assessment questions were found for this subject/topic to ground generation in. Upload library content for this subject first.']];
        }

        $generated = $this->requestGeneration($sourceQuestions, $subject, $topic?->name, $subtopic?->name, $questionCount);

        if ($generated === []) {
            return ['created' => 0, 'errors' => ['AI generation returned no usable questions. Try again, or a smaller question count.']];
        }

        $sourceBankTitles = $sourceQuestions
            ->pluck('questionBank.title')
            ->filter()
            ->unique()
            ->implode(', ');

        $nextItemNumber = (int) $bank->reviewBotQuestions()->max('item_number') + 1;
        $created = 0;

        DB::transaction(function () use ($generated, $bank, $subject, $topic, $subtopic, $sourceBankTitles, &$nextItemNumber, &$created): void {
            foreach ($generated as $item) {
                $question = QuestionBankQuestion::create([
                    'question_bank_id' => $bank->id,
                    'usage_type' => QuestionBankQuestion::USAGE_REVIEW_BOT,
                    'subject' => $subject,
                    'topic_id' => $topic?->id,
                    'subtopic_id' => $subtopic?->id,
                    'difficulty' => $item['difficulty'],
                    'cognitive_level' => $item['cognitive_level'],
                    'tag_status' => QuestionBankQuestion::TAG_STATUS_CONFIRMED,
                    'tag_source' => QuestionBankQuestion::TAG_SOURCE_AI,
                    'explanation' => $item['explanation'],
                    'status' => QuestionBankQuestion::STATUS_DRAFT,
                    'source_reference' => $sourceBankTitles !== '' ? "Generated from: {$sourceBankTitles}" : null,
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

        $bank->update(['total_items' => $bank->questions()->count()]);

        return ['created' => $created, 'errors' => []];
    }

    /**
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function resolveSourceQuestions(string $subject, ?int $topicId, ?int $subtopicId, int $questionCount): Collection
    {
        $baseQuery = fn () => QuestionBankQuestion::query()
            ->with(['questionBank:id,title', 'options:id,question_bank_question_id,option_label,option_text,is_correct'])
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('subject', $subject);

        $poolLimit = min(max($questionCount * 2, $questionCount + 4), 24);

        if ($subtopicId) {
            $scoped = $baseQuery()->where('subtopic_id', $subtopicId)->inRandomOrder()->limit($poolLimit)->get();
            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        if ($topicId) {
            $scoped = $baseQuery()->where('topic_id', $topicId)->inRandomOrder()->limit($poolLimit)->get();
            if ($scoped->isNotEmpty()) {
                return $scoped;
            }
        }

        return $baseQuery()->inRandomOrder()->limit($poolLimit)->get();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<string, mixed>>
     */
    protected function requestGeneration(Collection $sourceQuestions, string $subject, ?string $topic, ?string $subtopic, int $questionCount): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        $systemPrompt = <<<'PROMPT'
You create student practice questions for a Review Bot question bank, grounded in the teacher-authored source material provided.

Rules:
- Return valid JSON only.
- Return exactly the requested number of questions.
- Base every question on the concepts in "sources" -- do not introduce unrelated content, and do not copy the source wording, sentence structure, or answer choices verbatim.
- Avoid generating near-duplicate questions of each other.
- Each question must be either "multiple_choice" or "true_false".
- For "multiple_choice", provide exactly 4 options and exactly one correct option id.
- For "true_false", provide exactly 2 options: True and False, and identify the correct option id.
- Include a short explanation of why the correct answer is correct -- this is shown to the student after they answer.
- Assign a "difficulty" (easy, medium, or hard) and a "cognitive_level" (recall, comprehension, application, or analysis) to every question.
- Never mention source question ids, item numbers, or teacher bank titles in the question text.
PROMPT;

        $userPrompt = [
            'subject' => $subject,
            'topic' => $topic,
            'subtopic' => $subtopic,
            'question_count' => $questionCount,
            'sources' => $sourceQuestions->map(function (QuestionBankQuestion $question): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

                return [
                    'question_text' => trim((string) ($question->question_text ?? '')),
                    'answer_text' => trim((string) ($question->answer_text ?? $correctOption?->option_text ?? '')),
                    'options' => $question->options
                        ->map(fn (QuestionBankOption $option): string => trim((string) $option->option_text))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })->all(),
            'response_schema' => [
                'questions' => [
                    [
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
