<?php

namespace App\Services;

use App\Jobs\ClassifyQuestionBankQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Classifies faculty-authored assessment items into a topic/subtopic,
 * a difficulty tier, and a cognitive (Bloom's-style) level, so exam
 * results can be broken down more deeply than "subject" alone. Runs once
 * per uploaded set, in the background, and never touches AI again for
 * that content afterward.
 */
class QuestionMappingService
{
    private const CLASSIFICATION_BATCH_SIZE = 15;

    /**
     * Curated subtopic list per BLIS core subject. The AI is only ever
     * allowed to choose from this list (never invent a topic name), so
     * results stay comparable across every teacher's uploads instead of
     * fragmenting into near-duplicate topic names.
     *
     * @var array<string, array<int, string>>
     */
    private const CURATED_TOPICS = [
        'Cataloging and Classification' => [
            'RDA and Descriptive Cataloging',
            'Classification Systems and Call Numbers',
            'Subject Headings and Authority Control',
            'Cutter Numbers and Shelf Arrangement',
            'MARC Format and Bibliographic Records',
            'Edition and Physical Description',
        ],
        'Indexing and Abstracting' => [
            'Controlled Vocabulary and Thesauri',
            'Abstract Types and Functions',
            'Precision, Recall, and Retrieval Quality',
            'Citation Indexing',
            'Exhaustivity and Specificity',
        ],
        'Information Technology' => [
            'OPAC and Discovery Systems',
            'Boolean Search and Query Strategy',
            'Metadata and Interoperability Standards',
            'Integrated Library Systems',
            'Digital Security and Data Protection',
            'RFID, Barcodes, and Automation',
        ],
        'Reference Services' => [
            'Reference Interview and User Needs',
            'Ready Reference and Referral',
            'Source Evaluation and Information Literacy',
            'Current Awareness Services',
            "Reader's Advisory",
        ],
        'Library Management' => [
            'Planning and Policy Development',
            'Budgeting and Resource Allocation',
            'Staff Development and Supervision',
            'Assessment and Performance Indicators',
            'Disaster and Risk Management',
        ],
        'Selection and Acquisition' => [
            'Selection Criteria and Collection Policy',
            'Acquisition Methods and Ordering Workflow',
            'Vendor Evaluation and Bibliographic Verification',
            'Gifts and Donations Management',
            'Budgeting for Acquisitions',
        ],
    ];

    public function hasApiKey(): bool
    {
        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    public function missingConfigurationMessage(): string
    {
        return 'OPENAI_API_KEY is not configured, so AI item mapping is unavailable.';
    }

    /**
     * @return array<int, string>
     */
    public function curatedTopicsForSubject(?string $subject): array
    {
        return self::CURATED_TOPICS[$this->normalizeSubjectLabel($subject)] ?? [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function curatedTopics(): array
    {
        return self::CURATED_TOPICS;
    }

    /**
     * Queue background classification for a bank's assessment questions
     * that don't have a topic yet (i.e. weren't manually tagged by the
     * teacher at upload). $mode controls whether AI suggestions apply
     * immediately ("auto") or land as pending_review for a teacher to
     * confirm ("review").
     *
     * @param  Collection<int, QuestionBankQuestion>|null  $sourceQuestions
     * @return array{status: string, message: string, queued: int}
     */
    public function queueBankMapping(QuestionBank $bank, string $mode, ?Collection $sourceQuestions = null): array
    {
        $bank->refresh();
        $mode = in_array($mode, QuestionBank::MAPPING_MODES, true) ? $mode : QuestionBank::MAPPING_MODE_REVIEW;

        if (in_array($bank->mapping_status, [QuestionBank::MAPPING_STATUS_QUEUED, QuestionBank::MAPPING_STATUS_PROCESSING], true)) {
            $message = $bank->mapping_status === QuestionBank::MAPPING_STATUS_PROCESSING
                ? 'AI item mapping is already running for this set.'
                : 'AI item mapping is already queued for this set.';

            return ['status' => (string) $bank->mapping_status, 'message' => $message, 'queued' => 0];
        }

        $questions = $sourceQuestions instanceof Collection
            ? $sourceQuestions
            : $bank->assessmentQuestions()->whereNull('topic_id')->get();

        $candidateIds = $questions
            ->filter(fn (QuestionBankQuestion $question): bool => is_null($question->topic_id))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        if ($candidateIds === []) {
            $message = 'Every assessment item in this set already has a topic assigned.';

            $this->markBankMappingStatus($bank, QuestionBank::MAPPING_STATUS_COMPLETED, $message, [
                'mapping_mode' => $mode,
                'mapping_requested_at' => now(),
                'mapping_completed_at' => now(),
            ]);

            return ['status' => QuestionBank::MAPPING_STATUS_COMPLETED, 'message' => $message, 'queued' => 0];
        }

        if (!$this->hasApiKey()) {
            $message = $this->missingConfigurationMessage();

            $this->markBankMappingStatus($bank, QuestionBank::MAPPING_STATUS_SKIPPED, $message, [
                'mapping_mode' => $mode,
                'mapping_requested_at' => now(),
                'mapping_completed_at' => now(),
            ]);

            return ['status' => QuestionBank::MAPPING_STATUS_SKIPPED, 'message' => $message, 'queued' => 0];
        }

        $message = sprintf('AI item mapping queued for %d question(s).', count($candidateIds));

        $this->markBankMappingStatus($bank, QuestionBank::MAPPING_STATUS_QUEUED, $message, [
            'mapping_mode' => $mode,
            'mapping_requested_at' => now(),
            'mapping_completed_at' => null,
        ]);

        ClassifyQuestionBankQuestionsJob::dispatch((int) $bank->id, $candidateIds, $mode)->afterCommit();

        return ['status' => QuestionBank::MAPPING_STATUS_QUEUED, 'message' => $message, 'queued' => count($candidateIds)];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markBankMappingStatus(QuestionBank $bank, string $status, ?string $message = null, array $extra = []): QuestionBank
    {
        if (!in_array($status, QuestionBank::MAPPING_STATUSES, true)) {
            throw new RuntimeException('Invalid mapping status.');
        }

        $bank->forceFill(array_merge([
            'mapping_status' => $status,
            'mapping_message' => $message,
        ], $extra));

        $bank->save();

        return $bank->refresh();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array{updated: int, errors: array<int, string>}
     */
    public function classifyForAssessmentQuestions(Collection $sourceQuestions, string $mode): array
    {
        $sourceQuestions = $sourceQuestions
            ->filter(fn ($question): bool => $question instanceof QuestionBankQuestion
                && $question->usage_type === QuestionBankQuestion::USAGE_ASSESSMENT
                && is_null($question->topic_id))
            ->values();

        if ($sourceQuestions->isEmpty()) {
            return ['updated' => 0, 'errors' => []];
        }

        if (!$this->hasApiKey()) {
            return ['updated' => 0, 'errors' => [$this->missingConfigurationMessage()]];
        }

        $updated = 0;
        $errors = [];

        foreach ($sourceQuestions->groupBy(fn (QuestionBankQuestion $question) => $this->normalizeSubjectLabel($question->subject)) as $subject => $subjectQuestions) {
            $allowedTopics = $this->curatedTopicsForSubject($subject);

            if ($allowedTopics === []) {
                $errors[] = sprintf('No curated topic list is defined for subject "%s", so those items were skipped.', $subject);
                continue;
            }

            foreach ($subjectQuestions->chunk(self::CLASSIFICATION_BATCH_SIZE) as $chunk) {
                $classified = $this->requestClassificationBatch($chunk->values(), $subject, $allowedTopics);

                if ($classified === []) {
                    $errors[] = sprintf('AI mapping returned no usable results for a batch in "%s".', $subject);
                    continue;
                }

                $classifiedById = collect($classified)->keyBy('source_question_id');

                foreach ($chunk as $question) {
                    $result = $classifiedById->get((int) $question->id);

                    if (!is_array($result)) {
                        $errors[] = sprintf('Item #%d was not classified by the AI response.', (int) $question->item_number);
                        continue;
                    }

                    $this->applyClassification($question, $result, $mode);
                    $updated++;
                }
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    public function updateTagStatus(QuestionBankQuestion $question, string $status): QuestionBankQuestion
    {
        if (!in_array($status, QuestionBankQuestion::TAG_STATUSES, true)) {
            throw new RuntimeException('Invalid tag status.');
        }

        $question->tag_status = $status;
        $question->save();

        return $question->refresh();
    }

    /**
     * Manually set/confirm a question's topic, difficulty, and cognitive
     * level (used by the teacher review UI to edit an AI suggestion or
     * tag an item by hand).
     */
    public function applyManualTags(
        QuestionBankQuestion $question,
        ?string $topicName,
        ?string $difficulty,
        ?string $cognitiveLevel,
        ?int $actorId = null,
    ): QuestionBankQuestion {
        if (filled($topicName)) {
            $topic = $this->resolveTopic($question->subject, $topicName, $actorId);
            $question->topic_id = $topic?->id;
        }

        if (filled($difficulty) && in_array($difficulty, QuestionBankQuestion::DIFFICULTIES, true)) {
            $question->difficulty = $difficulty;
        }

        if (filled($cognitiveLevel) && in_array($cognitiveLevel, QuestionBankQuestion::COGNITIVE_LEVELS, true)) {
            $question->cognitive_level = $cognitiveLevel;
        }

        $question->tag_status = QuestionBankQuestion::TAG_STATUS_CONFIRMED;
        $question->tag_source = QuestionBankQuestion::TAG_SOURCE_FACULTY;
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
            ['subject' => $normalizedSubject, 'name' => $normalizedTopic],
            ['created_by' => $actorId],
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function applyClassification(QuestionBankQuestion $question, array $result, string $mode): void
    {
        $topicName = trim((string) ($result['topic'] ?? ''));
        $difficulty = trim((string) ($result['difficulty'] ?? ''));
        $cognitiveLevel = trim((string) ($result['cognitive_level'] ?? ''));

        $topic = $topicName !== '' ? $this->resolveTopic($question->subject, $topicName) : null;

        $question->forceFill([
            'topic_id' => $topic?->id,
            'difficulty' => in_array($difficulty, QuestionBankQuestion::DIFFICULTIES, true) ? $difficulty : $question->difficulty,
            'cognitive_level' => in_array($cognitiveLevel, QuestionBankQuestion::COGNITIVE_LEVELS, true) ? $cognitiveLevel : null,
            'tag_status' => $mode === QuestionBank::MAPPING_MODE_AUTO
                ? QuestionBankQuestion::TAG_STATUS_CONFIRMED
                : QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW,
            'tag_source' => QuestionBankQuestion::TAG_SOURCE_AI,
        ])->save();
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $questions
     * @param  array<int, string>  $allowedTopics
     * @return array<int, array<string, mixed>>
     */
    protected function requestClassificationBatch(Collection $questions, string $subject, array $allowedTopics): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.model', 'gpt-4o-mini');

        if (!$this->hasApiKey()) {
            return [];
        }

        $systemPrompt = <<<'PROMPT'
You classify faculty-authored library science exam questions for a review dashboard. You do not rewrite or evaluate the questions.

Rules:
- Return valid JSON only.
- Classify every item listed in "questions".
- "topic" MUST be copied exactly (character for character) from the "allowed_topics" list. Never invent a new topic name.
- "difficulty" must be exactly one of: easy, medium, hard.
- "cognitive_level" must be exactly one of: recall, comprehension, application, analysis — using Bloom's-style meaning:
  recall = restates a fact or definition; comprehension = explains why/what something means; application = applies a rule to a new situation; analysis = compares, evaluates, or judges between options.
- Base the classification only on the question text, options, and answer provided.
PROMPT;

        $userPrompt = [
            'subject' => $subject,
            'allowed_topics' => $allowedTopics,
            'questions' => $questions->map(function (QuestionBankQuestion $question): array {
                $correctOption = $question->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);

                return [
                    'source_question_id' => (int) $question->id,
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
                        'source_question_id' => 1,
                        'topic' => 'one of allowed_topics, copied exactly',
                        'difficulty' => 'easy | medium | hard',
                        'cognitive_level' => 'recall | comprehension | application | analysis',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(60)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
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

        return $this->normalizeClassificationResults($decoded['questions'] ?? [], $allowedTopics);
    }

    /**
     * @param  mixed  $rawResults
     * @param  array<int, string>  $allowedTopics
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeClassificationResults(mixed $rawResults, array $allowedTopics): array
    {
        if (!is_array($rawResults)) {
            return [];
        }

        $allowedTopicLookup = collect($allowedTopics)
            ->mapWithKeys(fn (string $topic): array => [Str::lower(trim($topic)) => $topic])
            ->all();

        $normalized = [];

        foreach ($rawResults as $result) {
            if (!is_array($result)) {
                continue;
            }

            $sourceQuestionId = (int) ($result['source_question_id'] ?? 0);
            $topicKey = Str::lower(trim((string) ($result['topic'] ?? '')));
            $topic = $allowedTopicLookup[$topicKey] ?? null;
            $difficulty = Str::lower(trim((string) ($result['difficulty'] ?? '')));
            $cognitiveLevel = Str::lower(trim((string) ($result['cognitive_level'] ?? '')));

            if (
                $sourceQuestionId < 1
                || $topic === null
                || !in_array($difficulty, QuestionBankQuestion::DIFFICULTIES, true)
                || !in_array($cognitiveLevel, QuestionBankQuestion::COGNITIVE_LEVELS, true)
            ) {
                continue;
            }

            $normalized[] = [
                'source_question_id' => $sourceQuestionId,
                'topic' => $topic,
                'difficulty' => $difficulty,
                'cognitive_level' => $cognitiveLevel,
            ];
        }

        return $normalized;
    }

    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return 'General';
        }

        $normalized = Str::of($raw)
            ->lower()
            ->replace('&', 'and')
            ->replace('/', ' ')
            ->replace('-', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        $map = [
            'cataloging and classification' => 'Cataloging and Classification',
            'cataloguing and classification' => 'Cataloging and Classification',
            'cataloging classification' => 'Cataloging and Classification',
            'indexing and abstracting' => 'Indexing and Abstracting',
            'indexing abstracting' => 'Indexing and Abstracting',
            'information technology' => 'Information Technology',
            'it' => 'Information Technology',
            'reference services' => 'Reference Services',
            'reference service' => 'Reference Services',
            'reference' => 'Reference Services',
            'library management' => 'Library Management',
            'selection and acquisition' => 'Selection and Acquisition',
            'selection acquisition' => 'Selection and Acquisition',
            'acquisition and selection' => 'Selection and Acquisition',
            'acquisition selection' => 'Selection and Acquisition',
        ];

        if (array_key_exists($normalized, $map)) {
            return $map[$normalized];
        }

        foreach ($map as $key => $canonical) {
            if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                return $canonical;
            }
        }

        return Str::title($normalized);
    }
}
