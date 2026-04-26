<?php

namespace App\Services;

use App\Jobs\GenerateRemedialQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RemedialQuestionService
{
    private const GENERATION_BATCH_SIZE = 6;

    /**
     * @var array<string, string>
     */
    private const STYLE_DIFFICULTY_MAP = [
        QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION => QuestionBankQuestion::DIFFICULTY_MEDIUM,
        QuestionBankQuestion::ITEM_STYLE_DEFINITION_TO_TERM => QuestionBankQuestion::DIFFICULTY_EASY,
        QuestionBankQuestion::ITEM_STYLE_SCENARIO_BASED => QuestionBankQuestion::DIFFICULTY_HARD,
        QuestionBankQuestion::ITEM_STYLE_COMPARISON => QuestionBankQuestion::DIFFICULTY_MEDIUM,
    ];

    /**
     * @var array<string, string>
     */
    private const STYLE_COGNITIVE_LEVEL_MAP = [
        QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION => 'comprehension',
        QuestionBankQuestion::ITEM_STYLE_DEFINITION_TO_TERM => 'recall',
        QuestionBankQuestion::ITEM_STYLE_SCENARIO_BASED => 'application',
        QuestionBankQuestion::ITEM_STYLE_COMPARISON => 'analysis',
    ];

    public function hasApiKey(): bool
    {
        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    public function missingConfigurationMessage(): string
    {
        return 'OPENAI_API_KEY is not configured, so AI remedial generation is unavailable.';
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>|null  $sourceQuestions
     * @return array{status: string, message: string, queued: int}
     */
    public function queueBankGeneration(QuestionBank $bank, ?Collection $sourceQuestions = null): array
    {
        $bank->refresh();

        if (in_array($bank->remedial_generation_status, [
            QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED,
            QuestionBank::REMEDIAL_GENERATION_STATUS_PROCESSING,
        ], true)) {
            $message = $bank->remedial_generation_status === QuestionBank::REMEDIAL_GENERATION_STATUS_PROCESSING
                ? 'AI remedial generation is already running for this bank.'
                : 'AI remedial generation is already queued for this bank.';

            return [
                'status' => (string) $bank->remedial_generation_status,
                'message' => $message,
                'queued' => 0,
            ];
        }

        $questions = $sourceQuestions instanceof Collection
            ? $sourceQuestions
            : $bank->assessmentQuestions()->get();

        $candidateIds = $this->collectGenerationCandidateIds($questions);

        if ($candidateIds === []) {
            $message = 'All assessment questions in this bank already have stored remedial variants.';

            $this->markBankGenerationStatus(
                $bank,
                QuestionBank::REMEDIAL_GENERATION_STATUS_COMPLETED,
                $message,
                [
                    'remedial_generation_requested_at' => now(),
                    'remedial_generation_completed_at' => now(),
                ],
            );

            return [
                'status' => QuestionBank::REMEDIAL_GENERATION_STATUS_COMPLETED,
                'message' => $message,
                'queued' => 0,
            ];
        }

        if (!$this->hasApiKey()) {
            $message = $this->missingConfigurationMessage();

            $this->markBankGenerationStatus(
                $bank,
                QuestionBank::REMEDIAL_GENERATION_STATUS_SKIPPED,
                $message,
                [
                    'remedial_generation_requested_at' => now(),
                    'remedial_generation_completed_at' => now(),
                ],
            );

            return [
                'status' => QuestionBank::REMEDIAL_GENERATION_STATUS_SKIPPED,
                'message' => $message,
                'queued' => 0,
            ];
        }

        $message = sprintf('AI remedial generation queued for %d assessment question(s).', count($candidateIds));

        $this->markBankGenerationStatus(
            $bank,
            QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED,
            $message,
            [
                'remedial_generation_requested_at' => now(),
                'remedial_generation_completed_at' => null,
            ],
        );

        GenerateRemedialQuestionsJob::dispatch((int) $bank->id, $candidateIds)
            ->afterCommit();

        return [
            'status' => QuestionBank::REMEDIAL_GENERATION_STATUS_QUEUED,
            'message' => $message,
            'queued' => count($candidateIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markBankGenerationStatus(
        QuestionBank $bank,
        string $status,
        ?string $message = null,
        array $extra = [],
    ): QuestionBank {
        if (!in_array($status, QuestionBank::REMEDIAL_GENERATION_STATUSES, true)) {
            throw new RuntimeException('Invalid remedial generation status.');
        }

        $bank->forceFill(array_merge([
            'remedial_generation_status' => $status,
            'remedial_generation_message' => $message,
        ], $extra));

        $bank->save();

        return $bank->refresh();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, int>
     */
    public function collectGenerationCandidateIds(Collection $sourceQuestions, bool $skipExisting = true): array
    {
        return collect(array_keys($this->buildRequiredStylesBySource($sourceQuestions, $skipExisting)))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array{created: int, errors: array<int, string>}
     */
    public function generateForAssessmentQuestions(Collection $sourceQuestions, bool $skipExisting = true): array
    {
        $sourceQuestions = $this->normalizeAssessmentSourceQuestions($sourceQuestions);
        $requiredStylesBySource = $this->buildRequiredStylesBySource($sourceQuestions, $skipExisting);

        $sourceQuestions = $sourceQuestions
            ->filter(fn (QuestionBankQuestion $question): bool => isset($requiredStylesBySource[(int) $question->id]))
            ->values();

        if ($sourceQuestions->isEmpty()) {
            return [
                'created' => 0,
                'errors' => [],
            ];
        }

        if (!$this->hasApiKey()) {
            return [
                'created' => 0,
                'errors' => [$this->missingConfigurationMessage()],
            ];
        }

        $created = 0;
        $errors = [];

        foreach ($sourceQuestions->chunk(self::GENERATION_BATCH_SIZE) as $chunk) {
            $chunkStyles = $chunk
                ->mapWithKeys(fn (QuestionBankQuestion $question): array => [
                    (int) $question->id => $requiredStylesBySource[(int) $question->id] ?? [],
                ])
                ->all();

            $generatedBatch = $this->requestGeneratedBatch($chunk->values(), $chunkStyles);

            if ($generatedBatch === []) {
                $errors[] = sprintf(
                    'AI generation returned no usable remedial questions for source items %s.',
                    $chunk->pluck('item_number')->implode(', ')
                );
                continue;
            }

            $generatedBySourceId = collect($generatedBatch)
                ->groupBy(fn (array $question): int => (int) $question['source_question_id'])
                ->map(fn (Collection $items): Collection => $items->keyBy(fn (array $question): string => (string) $question['item_style']));

            foreach ($chunk as $sourceQuestion) {
                $requiredStyles = $chunkStyles[(int) $sourceQuestion->id] ?? [];
                $generatedForSource = $generatedBySourceId->get((int) $sourceQuestion->id, collect());
                $generatedItems = [];
                $missingStyles = [];
                $invalidStyles = [];

                foreach ($requiredStyles as $style) {
                    $generated = $generatedForSource->get($style);

                    if (!is_array($generated)) {
                        $missingStyles[] = $style;
                        continue;
                    }

                    if ($this->looksLikeDirectParaphrase($sourceQuestion, (string) ($generated['question_text'] ?? ''))) {
                        $invalidStyles[] = $style;
                        continue;
                    }

                    $generatedItems[] = $generated;
                }

                if ($missingStyles !== [] || $invalidStyles !== []) {
                    if ($missingStyles !== []) {
                        $errors[] = sprintf(
                            'Source question #%d is missing AI styles: %s.',
                            (int) $sourceQuestion->item_number,
                            implode(', ', $missingStyles)
                        );
                    }

                    if ($invalidStyles !== []) {
                        $errors[] = sprintf(
                            'Source question #%d returned paraphrase-like AI styles: %s.',
                            (int) $sourceQuestion->item_number,
                            implode(', ', $invalidStyles)
                        );
                    }

                    continue;
                }

                foreach ($generatedItems as $generated) {
                    $this->storeGeneratedQuestion($sourceQuestion, $generated);
                    $created++;
                }
            }
        }

        return [
            'created' => $created,
            'errors' => $errors,
        ];
    }

    public function refreshVariant(QuestionBankQuestion $question): QuestionBankQuestion
    {
        if (!$this->hasApiKey()) {
            throw new RuntimeException($this->missingConfigurationMessage());
        }

        $sourceQuestion = $question->usage_type === QuestionBankQuestion::USAGE_ASSESSMENT
            ? $question
            : $question->sourceQuestion;

        if (!$sourceQuestion) {
            throw new RuntimeException('This question does not have a refreshable assessment source.');
        }

        $targetStyle = in_array($question->item_style, QuestionBankQuestion::ITEM_STYLES, true)
            ? (string) $question->item_style
            : QuestionBankQuestion::ITEM_STYLE_REVERSE_ASSOCIATION;

        $generated = $this->requestGeneratedBatch(collect([$sourceQuestion]), [
            (int) $sourceQuestion->id => [$targetStyle],
        ]);

        $replacementPayload = collect($generated)
            ->first(fn (array $item): bool => (int) ($item['source_question_id'] ?? 0) === (int) $sourceQuestion->id
                && (string) ($item['item_style'] ?? '') === $targetStyle);

        if (!is_array($replacementPayload) || $this->looksLikeDirectParaphrase($sourceQuestion, (string) ($replacementPayload['question_text'] ?? ''))) {
            throw new RuntimeException('Unable to generate a replacement remedial question right now.');
        }

        if ($question->usage_type === QuestionBankQuestion::USAGE_REMEDIAL) {
            return $this->replaceGeneratedQuestion($question, $replacementPayload);
        }

        return $this->createGeneratedQuestion($sourceQuestion, $replacementPayload);
    }

    public function updateQuestionStatus(QuestionBankQuestion $question, string $status): QuestionBankQuestion
    {
        if (!in_array($status, QuestionBankQuestion::STATUSES, true)) {
            throw new RuntimeException('Invalid remedial question status.');
        }

        if ($question->usage_type !== QuestionBankQuestion::USAGE_REMEDIAL) {
            throw new RuntimeException('Only remedial questions can be reviewed.');
        }

        $question->status = $status;
        $question->save();

        return $question->refresh();
    }

    public function resolveTopic(?string $subject, ?string $topicName, ?int $actorId = null): ?Topic
    {
        $normalizedSubject = trim((string) ($subject ?? ''));
        $normalizedTopic = trim((string) ($topicName ?? ''));

        if ($normalizedSubject === '' || $normalizedTopic === '') {
            return null;
        }

        return Topic::query()->firstOrCreate(
            [
                'subject' => $normalizedSubject,
                'name' => $normalizedTopic,
            ],
            [
                'created_by' => $actorId,
            ],
        );
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @param  array<int, array<int, string>>  $requiredStylesBySource
     * @return array<int, array<string, mixed>>
     */
    protected function requestGeneratedBatch(Collection $sourceQuestions, array $requiredStylesBySource = []): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        if (!$this->hasApiKey()) {
            return [];
        }

        $systemPrompt = <<<'PROMPT'
You generate approved-for-review remedial practice questions from faculty assessment items.

Rules:
- Return valid JSON only.
- Generate exactly one question for each required style listed in required_styles.
- Use only these item styles: reverse_association, definition_to_term, scenario_based, comparison.
- Do not paraphrase the source question.
- Test the same concept from a different angle.
- Change the question direction or answer focus.
- Some questions should place the original correct answer in the stem and ask for a related concept.
- Some questions should make a related concept the correct answer instead of always making the original answer the correct answer.
- Every generated item must be multiple choice with exactly four options labeled A, B, C, and D.
- Use plausible distractors related to the same subject or topic.
- Avoid trick questions.
- Avoid all of the above and none of the above.
- Avoid negative or NOT/EXCEPT question stems.
- Keep the same subject/topic intent as the source.
- definition_to_term should stay easy to moderate.
- reverse_association should be moderate.
- comparison should be moderate.
- scenario_based should be difficult.
- Every item must include source_question_id, item_style, difficulty, cognitive_level, question_text, options, correct_answer, tested_concept, and difference_from_source.
PROMPT;

        $userPrompt = [
            'sources' => $sourceQuestions->map(function (QuestionBankQuestion $question): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);
                $sourceId = (int) $question->id;

                return [
                    'source_question_id' => $sourceId,
                    'subject' => trim((string) ($question->subject ?? $question->questionBank?->subject ?? 'General')),
                    'topic' => $question->topic?->name,
                    'difficulty' => $question->difficulty ?: QuestionBankQuestion::DIFFICULTY_MEDIUM,
                    'question_type' => (string) ($question->question_type ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE),
                    'question_text' => trim((string) ($question->question_text ?? '')),
                    'answer_text' => trim((string) ($question->answer_text ?? '')),
                    'correct_option_label' => $correctOption?->option_label,
                    'correct_option_text' => trim((string) ($correctOption?->option_text ?? $question->answer_text ?? '')),
                    'required_styles' => $requiredStylesBySource[$sourceId] ?? QuestionBankQuestion::ITEM_STYLES,
                    'options' => $question->options
                        ->map(fn (QuestionBankOption $option): array => [
                            'label' => $option->option_label,
                            'text' => trim((string) $option->option_text),
                            'is_correct' => (bool) $option->is_correct,
                        ])
                        ->values()
                        ->all(),
                ];
            })->all(),
            'response_schema' => [
                'questions' => [
                    [
                        'source_question_id' => 1,
                        'item_style' => 'reverse_association | definition_to_term | scenario_based | comparison',
                        'difficulty' => 'easy | medium | hard',
                        'cognitive_level' => 'recall | comprehension | application | analysis',
                        'question_text' => 'string',
                        'options' => [
                            'A' => 'option text',
                            'B' => 'option text',
                            'C' => 'option text',
                            'D' => 'option text',
                        ],
                        'correct_answer' => 'A',
                        'tested_concept' => 'string',
                        'difference_from_source' => 'string',
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
     * @param  mixed  $rawQuestions
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeGeneratedQuestions(mixed $rawQuestions): array
    {
        if (!is_array($rawQuestions)) {
            return [];
        }

        $normalized = [];

        foreach ($rawQuestions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $sourceQuestionId = (int) ($question['source_question_id'] ?? 0);
            $itemStyle = Str::lower(trim((string) ($question['item_style'] ?? '')));
            $questionText = trim((string) ($question['question_text'] ?? $question['prompt'] ?? ''));
            $difficulty = $this->normalizeDifficultyForStyle(
                $itemStyle,
                Str::lower(trim((string) ($question['difficulty'] ?? '')))
            );
            $cognitiveLevel = trim((string) ($question['cognitive_level'] ?? (self::STYLE_COGNITIVE_LEVEL_MAP[$itemStyle] ?? '')));
            $testedConcept = trim((string) ($question['tested_concept'] ?? ''));
            $differenceFromSource = trim((string) ($question['difference_from_source'] ?? ''));
            $correctAnswer = Str::upper(trim((string) ($question['correct_answer'] ?? $question['correct_option_id'] ?? '')));
            $options = $this->normalizeGeneratedOptions($question['options'] ?? null);

            if (
                $sourceQuestionId < 1
                || !in_array($itemStyle, QuestionBankQuestion::ITEM_STYLES, true)
                || $questionText === ''
                || !in_array($difficulty, QuestionBankQuestion::DIFFICULTIES, true)
                || $cognitiveLevel === ''
                || $testedConcept === ''
                || $differenceFromSource === ''
                || !in_array($correctAnswer, ['A', 'B', 'C', 'D'], true)
                || $options === []
                || !isset($options[$correctAnswer])
                || $this->containsDisallowedQuestionContent($questionText, $options)
            ) {
                continue;
            }

            $normalized[] = [
                'source_question_id' => $sourceQuestionId,
                'item_style' => $itemStyle,
                'question_text' => $questionText,
                'difficulty' => $difficulty,
                'cognitive_level' => $cognitiveLevel,
                'tested_concept' => $testedConcept,
                'difference_from_source' => $differenceFromSource,
                'correct_answer' => $correctAnswer,
                'answer_text' => $options[$correctAnswer],
                'options' => $options,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $generated
     */
    protected function createGeneratedQuestion(QuestionBankQuestion $sourceQuestion, array $generated): QuestionBankQuestion
    {
        $bank = $sourceQuestion->relationLoaded('questionBank')
            ? $sourceQuestion->questionBank
            : $sourceQuestion->questionBank()->first();

        if (!$bank instanceof QuestionBank) {
            throw new RuntimeException('Source question bank could not be resolved.');
        }

        $nextItemNumber = ((int) QuestionBankQuestion::query()
            ->where('question_bank_id', $bank->id)
            ->max('item_number')) + 1;

        return DB::transaction(function () use ($sourceQuestion, $generated, $bank, $nextItemNumber): QuestionBankQuestion {
            $createdQuestion = QuestionBankQuestion::create([
                'question_bank_id' => $bank->id,
                'usage_type' => QuestionBankQuestion::USAGE_REMEDIAL,
                'subject' => $sourceQuestion->subject ?: $bank->subject,
                'topic_id' => $sourceQuestion->topic_id,
                'difficulty' => $generated['difficulty'] ?? $sourceQuestion->difficulty ?? QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'source' => QuestionBankQuestion::SOURCE_AI,
                'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
                'ai_generated' => true,
                'item_style' => (string) ($generated['item_style'] ?? ''),
                'cognitive_level' => trim((string) ($generated['cognitive_level'] ?? '')) ?: null,
                'tested_concept' => trim((string) ($generated['tested_concept'] ?? '')) ?: null,
                'difference_from_source' => trim((string) ($generated['difference_from_source'] ?? '')) ?: null,
                'explanation' => null,
                'source_question_id' => $sourceQuestion->id,
                'item_number' => $nextItemNumber,
                'question_text' => (string) ($generated['question_text'] ?? ''),
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => (string) ($generated['correct_answer'] ?? ''),
                'answer_text' => trim((string) ($generated['answer_text'] ?? '')) ?: null,
            ]);

            foreach (['A', 'B', 'C', 'D'] as $index => $optionLabel) {
                $optionText = trim((string) (($generated['options'][$optionLabel] ?? '')));
                $createdQuestion->options()->create([
                    'sort_order' => $index + 1,
                    'option_label' => $optionLabel,
                    'option_text' => $optionText,
                    'is_correct' => (string) ($generated['correct_answer'] ?? '') === $optionLabel,
                ]);
            }

            return $createdQuestion->load([
                'topic:id,subject,name',
                'sourceQuestion:id,question_text,item_number,answer_label,answer_text',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $generated
     */
    protected function storeGeneratedQuestion(QuestionBankQuestion $sourceQuestion, array $generated): QuestionBankQuestion
    {
        $targetStyle = Str::lower(trim((string) ($generated['item_style'] ?? '')));

        $existingRejected = QuestionBankQuestion::query()
            ->where('source_question_id', $sourceQuestion->id)
            ->where('usage_type', QuestionBankQuestion::USAGE_REMEDIAL)
            ->where('status', QuestionBankQuestion::STATUS_REJECTED)
            ->where('item_style', $targetStyle)
            ->latest('id')
            ->first();

        if ($existingRejected instanceof QuestionBankQuestion) {
            return $this->replaceGeneratedQuestion($existingRejected, $generated);
        }

        return $this->createGeneratedQuestion($sourceQuestion, $generated);
    }

    /**
     * @param  array<string, mixed>  $generated
     */
    protected function replaceGeneratedQuestion(QuestionBankQuestion $question, array $generated): QuestionBankQuestion
    {
        return DB::transaction(function () use ($question, $generated): QuestionBankQuestion {
            $question->forceFill([
                'difficulty' => $generated['difficulty'] ?? $question->difficulty ?? QuestionBankQuestion::DIFFICULTY_MEDIUM,
                'status' => QuestionBankQuestion::STATUS_PENDING_REVIEW,
                'ai_generated' => true,
                'item_style' => (string) ($generated['item_style'] ?? $question->item_style ?? ''),
                'cognitive_level' => trim((string) ($generated['cognitive_level'] ?? '')) ?: null,
                'tested_concept' => trim((string) ($generated['tested_concept'] ?? '')) ?: null,
                'difference_from_source' => trim((string) ($generated['difference_from_source'] ?? '')) ?: null,
                'explanation' => null,
                'question_text' => (string) ($generated['question_text'] ?? ''),
                'question_type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
                'answer_label' => (string) ($generated['correct_answer'] ?? ''),
                'answer_text' => trim((string) ($generated['answer_text'] ?? '')) ?: null,
            ])->save();

            $question->options()->delete();

            foreach (['A', 'B', 'C', 'D'] as $index => $optionLabel) {
                $optionText = trim((string) (($generated['options'][$optionLabel] ?? '')));
                $question->options()->create([
                    'sort_order' => $index + 1,
                    'option_label' => $optionLabel,
                    'option_text' => $optionText,
                    'is_correct' => (string) ($generated['correct_answer'] ?? '') === $optionLabel,
                ]);
            }

            return $question->refresh()->load([
                'topic:id,subject,name',
                'sourceQuestion:id,question_text,item_number,answer_label,answer_text',
                'options:id,question_bank_question_id,option_label,option_text,is_correct',
            ]);
        });
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return Collection<int, QuestionBankQuestion>
     */
    protected function normalizeAssessmentSourceQuestions(Collection $sourceQuestions): Collection
    {
        return $sourceQuestions
            ->filter(fn ($question): bool => $question instanceof QuestionBankQuestion)
            ->filter(function (QuestionBankQuestion $question): bool {
                return ($question->usage_type ?? QuestionBankQuestion::USAGE_ASSESSMENT) === QuestionBankQuestion::USAGE_ASSESSMENT;
            })
            ->values();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<int, string>>
     */
    protected function buildRequiredStylesBySource(Collection $sourceQuestions, bool $skipExisting = true): array
    {
        $sourceQuestions = $this->normalizeAssessmentSourceQuestions($sourceQuestions);

        if ($sourceQuestions->isEmpty()) {
            return [];
        }

        if (!$skipExisting) {
            return $sourceQuestions
                ->mapWithKeys(fn (QuestionBankQuestion $question): array => [
                    (int) $question->id => QuestionBankQuestion::ITEM_STYLES,
                ])
                ->all();
        }

        (new EloquentCollection($sourceQuestions->all()))
            ->load('remedialVariants:id,source_question_id,item_style,status');

        return $sourceQuestions
            ->mapWithKeys(function (QuestionBankQuestion $question): array {
                $existingStyles = $question->remedialVariants
                    ->filter(function (QuestionBankQuestion $variant): bool {
                        return $variant->status !== QuestionBankQuestion::STATUS_REJECTED;
                    })
                    ->map(fn (QuestionBankQuestion $variant): string => Str::lower(trim((string) ($variant->item_style ?? ''))))
                    ->filter(fn (string $style): bool => in_array($style, QuestionBankQuestion::ITEM_STYLES, true))
                    ->unique()
                    ->values()
                    ->all();

                $missingStyles = array_values(array_diff(QuestionBankQuestion::ITEM_STYLES, $existingStyles));

                if ($missingStyles === []) {
                    return [];
                }

                return [
                    (int) $question->id => $missingStyles,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeGeneratedOptions(mixed $rawOptions): array
    {
        $normalized = [];

        if (is_array($rawOptions)) {
            foreach (['A', 'B', 'C', 'D'] as $label) {
                $value = $rawOptions[$label] ?? $rawOptions[Str::lower($label)] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $normalized[$label] = trim($value);
                }
            }

            if (count($normalized) === 4) {
                return $normalized;
            }

            foreach ($rawOptions as $index => $option) {
                if (!is_array($option)) {
                    continue;
                }

                $label = Str::upper(trim((string) ($option['label'] ?? $option['id'] ?? chr(ord('A') + (int) $index))));
                $text = trim((string) ($option['text'] ?? ''));

                if (in_array($label, ['A', 'B', 'C', 'D'], true) && $text !== '') {
                    $normalized[$label] = $text;
                }
            }
        }

        foreach (['A', 'B', 'C', 'D'] as $label) {
            if (!isset($normalized[$label]) || $normalized[$label] === '') {
                return [];
            }
        }

        return $normalized;
    }

    protected function normalizeDifficultyForStyle(string $itemStyle, string $difficulty): string
    {
        if ($itemStyle === QuestionBankQuestion::ITEM_STYLE_DEFINITION_TO_TERM) {
            return in_array($difficulty, [
                QuestionBankQuestion::DIFFICULTY_EASY,
                QuestionBankQuestion::DIFFICULTY_MEDIUM,
            ], true)
                ? $difficulty
                : QuestionBankQuestion::DIFFICULTY_EASY;
        }

        return self::STYLE_DIFFICULTY_MAP[$itemStyle] ?? QuestionBankQuestion::DIFFICULTY_MEDIUM;
    }

    /**
     * @param  array<string, string>  $options
     */
    protected function containsDisallowedQuestionContent(string $questionText, array $options): bool
    {
        if (preg_match('/\b(?:not|except)\b/i', $questionText) === 1) {
            return true;
        }

        foreach ($options as $optionText) {
            if (preg_match('/\b(?:all of the above|none of the above)\b/i', $optionText) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeDirectParaphrase(QuestionBankQuestion $sourceQuestion, string $generatedText): bool
    {
        $sourceTokens = $this->normalizedSignificantTokens((string) ($sourceQuestion->question_text ?? ''));
        $generatedTokens = $this->normalizedSignificantTokens($generatedText);

        if ($sourceTokens === [] || $generatedTokens === []) {
            return false;
        }

        $sourceKey = implode(' ', $sourceTokens);
        $generatedKey = implode(' ', $generatedTokens);

        if ($sourceKey === $generatedKey || str_contains($generatedKey, $sourceKey) || str_contains($sourceKey, $generatedKey)) {
            return true;
        }

        $intersection = array_values(array_intersect($sourceTokens, $generatedTokens));
        $sourceCoverage = count($intersection) / max(count($sourceTokens), 1);
        $generatedCoverage = count($intersection) / max(count($generatedTokens), 1);

        return $sourceCoverage >= 0.78 && $generatedCoverage >= 0.78;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizedSignificantTokens(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', Str::lower($text)) ?: [];
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how',
            'in', 'into', 'is', 'it', 'of', 'on', 'or', 'the', 'to', 'used', 'was',
            'what', 'which', 'who', 'with',
        ];

        return collect($tokens)
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => $token !== '' && strlen($token) > 2 && !in_array($token, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }
}
