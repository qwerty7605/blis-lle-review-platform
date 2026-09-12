<?php

namespace App\Services;

use App\Jobs\ClassifyQuestionBankQuestionsJob;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Services\QuestionClassification\LocalQuestionClassifier;
use App\Services\QuestionClassification\ScalarClassification;
use App\Services\QuestionClassification\TopicClassification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Classifies faculty-authored assessment items into a topic + subtopic,
 * a difficulty tier, and a cognitive (Bloom's-style) level, so exam
 * results can be broken down more deeply than "subject" alone. Runs once
 * per uploaded set, in the background, using a free local keyword/heuristic
 * classifier (see LocalQuestionClassifier) -- no AI API call, no cost.
 */
class QuestionMappingService
{
    public function __construct(
        private readonly LocalQuestionClassifier $classifier = new LocalQuestionClassifier(),
    ) {
    }

    /**
     * Curated topic -> subtopic list per BLIS core subject. The classifier
     * is only ever allowed to choose from this list (never invent a topic or
     * subtopic name), so results stay comparable across every teacher's
     * uploads instead of fragmenting into near-duplicate names.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const CURATED_TOPICS = [
        'Cataloging and Classification' => [
            'RDA and Descriptive Cataloging' => [
                'Core Elements of Description',
                'FRBR/LRM Concepts',
                'Content, Media, and Carrier Types',
            ],
            'Classification Systems and Call Numbers' => [
                'Dewey Decimal Classification (DDC)',
                'Library of Congress Classification (LCC)',
                'Universal Decimal Classification (UDC)',
            ],
            'Subject Headings and Authority Control' => [
                'Library of Congress Subject Headings (LCSH)',
                'Authority Records and Cross-References',
                'Name Authority Control',
            ],
            'Cutter Numbers and Shelf Arrangement' => [
                'Cutter-Sanborn Tables',
                'Shelflisting Practices',
                'Alternate Shelving Schemes',
            ],
            'MARC Format and Bibliographic Records' => [
                'MARC 21 Field Structure',
                'Fixed and Variable Fields',
                'Bibliographic vs Authority Records',
            ],
            'Edition and Physical Description' => [
                'Edition Statements',
                'Physical Description Area',
                'Series Statements',
            ],
        ],
        'Indexing and Abstracting' => [
            'Controlled Vocabulary and Thesauri' => [
                'Preferred and Non-Preferred Terms',
                'Broader/Narrower/Related Terms',
                'Thesaurus Construction',
            ],
            'Abstract Types and Functions' => [
                'Informative Abstracts',
                'Indicative Abstracts',
                'Critical/Evaluative Abstracts',
            ],
            'Precision, Recall, and Retrieval Quality' => [
                'Precision vs Recall Tradeoff',
                'Relevance Ranking',
                'Search Result Evaluation',
            ],
            'Citation Indexing' => [
                'Citation Analysis',
                'Bibliographic Coupling',
                'Co-Citation',
            ],
            'Exhaustivity and Specificity' => [
                'Depth of Indexing',
                'Term Specificity',
                'Indexing Consistency',
            ],
        ],
        'Information Technology' => [
            'OPAC and Discovery Systems' => [
                'OPAC Search Features',
                'Discovery Layer Tools',
                'Federated Search',
            ],
            'Boolean Search and Query Strategy' => [
                'AND/OR/NOT Operators',
                'Truncation and Wildcards',
                'Nested Queries',
            ],
            'Metadata and Interoperability Standards' => [
                'Dublin Core',
                'MARC-to-Dublin Core Mapping',
                'Z39.50/SRU Protocols',
            ],
            'Integrated Library Systems' => [
                'ILS Modules',
                'Circulation and Cataloging Integration',
                'Vendor ILS Platforms',
            ],
            'Digital Security and Data Protection' => [
                'Authentication and Access Control',
                'Phishing and Social Engineering',
                'Data Backup Practices',
            ],
            'RFID, Barcodes, and Automation' => [
                'RFID Tagging',
                'Self-Check Kiosks',
                'Inventory Automation',
            ],
        ],
        'Reference Services' => [
            'Reference Interview and User Needs' => [
                'Open vs Closed Questions',
                'Negotiating the Query',
                'Nonverbal Cues',
            ],
            'Ready Reference and Referral' => [
                'Quick-Fact Sources',
                'Interlibrary Referral',
                'Escalation to Specialists',
            ],
            'Source Evaluation and Information Literacy' => [
                'Authority and Currency Evaluation',
                'Teaching Search Strategies',
                'Citation Guidance',
            ],
            'Current Awareness Services' => [
                'Selective Dissemination of Information (SDI)',
                'New Acquisitions Alerts',
                'RSS/Table-of-Contents Alerts',
            ],
            "Reader's Advisory" => [
                'Genre and Appeal Factors',
                'Read-Alike Recommendations',
                'Book Talks',
            ],
        ],
        'Library Management' => [
            'Planning and Policy Development' => [
                'Strategic Planning',
                'Collection Development Policy',
                'Mission and Vision Statements',
            ],
            'Budgeting and Resource Allocation' => [
                'Line-Item Budgeting',
                'Cost-Benefit Analysis',
                'Grant and Fund Sourcing',
            ],
            'Staff Development and Supervision' => [
                'Training Programs',
                'Performance Appraisal',
                'Delegation and Motivation',
            ],
            'Assessment and Performance Indicators' => [
                'Library Performance Metrics',
                'SWOT Analysis',
                'User Satisfaction Surveys',
            ],
            'Disaster and Risk Management' => [
                'Disaster Preparedness Plans',
                'Collection Salvage Procedures',
                'Business Continuity Planning',
            ],
        ],
        'Selection and Acquisition' => [
            'Selection Criteria and Collection Policy' => [
                'Community/User Needs Assessment',
                'Selection Aids and Reviews',
                'Weeding Criteria',
            ],
            'Acquisition Methods and Ordering Workflow' => [
                'Purchase, Gift, and Exchange',
                'Standing and Blanket Orders',
                'Approval Plans',
            ],
            'Vendor Evaluation and Bibliographic Verification' => [
                'Vendor Reliability Assessment',
                'Pre-Order Bibliographic Verification',
                'Price Comparison',
            ],
            'Gifts and Donations Management' => [
                'Gift Evaluation Criteria',
                'Deed of Gift Agreements',
                'Disposal of Unwanted Gifts',
            ],
            'Budgeting for Acquisitions' => [
                'Encumbrance Tracking',
                'Fund Allocation by Subject',
                'Approval and Purchase Orders',
            ],
        ],
    ];

    /**
     * @return array<int, string>
     */
    public function curatedTopicsForSubject(?string $subject): array
    {
        return array_keys(self::CURATED_TOPICS[$this->normalizeSubjectLabel($subject)] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function curatedSubtopicsForTopic(?string $subject, ?string $topic): array
    {
        $normalizedTopic = trim((string) $topic);

        return self::CURATED_TOPICS[$this->normalizeSubjectLabel($subject)][$normalizedTopic] ?? [];
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public function curatedTopics(): array
    {
        return self::CURATED_TOPICS;
    }

    /**
     * Guesses a bank's subject from a freshly DOCX-parsed (not yet
     * persisted) question set, so the Library upload form can pre-fill
     * "Subject Category" instead of a teacher picking it every time. Never
     * guesses wildly: below the same confidence bar topic-matching itself
     * uses, it returns a null subject and lets the teacher fill it in.
     *
     * @param  array<int, array{text?: string, answer_text?: string, options?: array<int, array{text?: string}>}>  $parsedQuestions
     * @return array{subject: ?string, confidence: float}
     */
    public function suggestSubjectForParsedQuestions(array $parsedQuestions): array
    {
        $searchTexts = collect($parsedQuestions)
            ->map(function (array $question): string {
                $parts = [
                    (string) ($question['text'] ?? ''),
                    (string) ($question['answer_text'] ?? ''),
                ];

                foreach ($question['options'] ?? [] as $option) {
                    $parts[] = (string) ($option['text'] ?? '');
                }

                return $this->classifier->buildSearchTextFromParts($parts);
            })
            ->all();

        $result = $this->classifier->classifySubjectForTexts($searchTexts);

        if ($result->value === '' || $result->confidence < LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD) {
            return ['subject' => null, 'confidence' => $result->confidence];
        }

        return ['subject' => $this->normalizeSubjectLabel($result->value), 'confidence' => $result->confidence];
    }

    /**
     * Queue background classification for a bank's assessment questions
     * that don't have a topic yet (i.e. weren't manually tagged by the
     * teacher at upload). $mode controls whether the classifier's
     * suggestions apply immediately ("auto") or land as pending_review for
     * a teacher to confirm ("review") -- though a low-confidence guess is
     * always forced into pending_review regardless of $mode, see
     * applyClassification().
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
                ? 'Item mapping is already running for this set.'
                : 'Item mapping is already queued for this set.';

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

        $message = sprintf('Item mapping queued for %d question(s).', count($candidateIds));

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

        $updated = 0;
        $errors = [];

        foreach ($sourceQuestions->groupBy(fn (QuestionBankQuestion $question) => $this->normalizeSubjectLabel($question->subject)) as $subject => $subjectQuestions) {
            $allowedTopics = self::CURATED_TOPICS[$subject] ?? [];
            $hasCuratedTopics = $allowedTopics !== [];

            // No curated topic list for this subject (typically an unset/
            // undetected "General" subject) doesn't mean nothing can be
            // classified -- cognitive level and difficulty are subject-
            // independent. Only topic/subtopic get skipped (left null,
            // which the confidence gate in applyClassification() already
            // forces into pending_review); a whole bank shouldn't hard-fail
            // over one missing piece of metadata.
            if (!$hasCuratedTopics) {
                $errors[] = sprintf(
                    'Subject "%s" has no curated topic list yet, so those item(s) got difficulty and cognitive level applied automatically but no topic -- set a subject on the bank to enable topic mapping.',
                    $subject,
                );
            }

            foreach ($subjectQuestions as $question) {
                $cognitiveLevel = $this->classifier->classifyCognitiveLevel($question);
                $difficulty = $this->classifier->classifyDifficulty($question);

                $sanitizedTopic = $hasCuratedTopics
                    ? $this->sanitizeLocalTopic($this->classifier->classifyTopic($question, $subject), $allowedTopics)
                    : new TopicClassification(null, null, 0.0);

                $this->applyClassification($question, $sanitizedTopic, $cognitiveLevel, $difficulty, $mode);
                $updated++;
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
     * Manually set/confirm a question's topic, subtopic, difficulty, and
     * cognitive level (used by the teacher review UI to edit a classifier
     * suggestion or tag an item by hand).
     */
    public function applyManualTags(
        QuestionBankQuestion $question,
        ?string $topicName,
        ?string $difficulty,
        ?string $cognitiveLevel,
        ?int $actorId = null,
        ?string $subtopicName = null,
    ): QuestionBankQuestion {
        if (filled($topicName)) {
            $topic = $this->resolveTopic($question->subject, $topicName, $actorId);
            $question->topic_id = $topic?->id;

            $question->subtopic_id = filled($subtopicName) && $topic
                ? $this->resolveSubtopic($topic, $subtopicName, $actorId)?->id
                : null;
        } elseif (filled($subtopicName) && $question->topic_id) {
            $topic = Topic::query()->find($question->topic_id);
            $question->subtopic_id = $topic
                ? $this->resolveSubtopic($topic, $subtopicName, $actorId)?->id
                : null;
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

    public function resolveSubtopic(Topic $topic, ?string $subtopicName, ?int $actorId = null): ?Subtopic
    {
        $normalizedSubtopic = trim((string) ($subtopicName ?? ''));

        if ($normalizedSubtopic === '') {
            return null;
        }

        return Subtopic::query()->firstOrCreate(
            ['topic_id' => $topic->id, 'name' => $normalizedSubtopic],
            ['created_by' => $actorId],
        );
    }

    /**
     * Normalizes a raw options payload (label/text/is_correct per row) into
     * persistable option rows plus the derived answer_label/answer_text/
     * question_type. "Which option counts as correct" is resolved once
     * here and reused by both bank creation (POST /library/banks) and
     * per-question content editing (PATCH /library/questions/{question}),
     * so the two paths can never disagree on how an answer key is derived.
     *
     * @param  array<int, array{label?: string, text?: string, is_correct?: bool}>  $rawOptions
     * @return array{options: array<int, array{sort_order: int, label: string, text: string, is_correct: bool}>, answer_label: ?string, answer_text: ?string, question_type: string}
     */
    public function resolveQuestionContent(
        array $rawOptions,
        ?string $requestedAnswerLabel,
        ?string $requestedType,
        ?string $fallbackAnswerText = null,
    ): array {
        $optionRows = collect($rawOptions)
            ->map(function (array $option, int $optionIndex): array {
                $defaultLabel = chr(ord('A') + min($optionIndex, 25));

                return [
                    'sort_order' => $optionIndex + 1,
                    'label' => Str::upper(trim((string) ($option['label'] ?? $defaultLabel))),
                    'text' => trim((string) ($option['text'] ?? '')),
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                ];
            })
            ->filter(fn (array $option): bool => $option['text'] !== '')
            ->values();

        if ($optionRows->isEmpty()) {
            return [
                'options' => [],
                'answer_label' => null,
                'answer_text' => $fallbackAnswerText,
                'question_type' => QuestionBankQuestion::TYPE_OPEN_ENDED,
            ];
        }

        $answerLabel = Str::upper(trim((string) $requestedAnswerLabel));
        if ($answerLabel === '') {
            $firstCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
            $answerLabel = is_array($firstCorrectOption)
                ? Str::upper(trim((string) ($firstCorrectOption['label'] ?? '')))
                : '';
        }

        $optionRows = $optionRows->map(function (array $option) use ($answerLabel): array {
            $option['is_correct'] = $answerLabel !== '' && $option['label'] === $answerLabel;

            return $option;
        })->values();

        $resolvedCorrectOption = $optionRows->first(fn (array $option): bool => $option['is_correct']);
        $answerText = is_array($resolvedCorrectOption) ? ($resolvedCorrectOption['text'] ?? null) : null;

        $type = in_array($requestedType, QuestionBankQuestion::TYPES, true)
            ? $requestedType
            : QuestionBankQuestion::TYPE_MULTIPLE_CHOICE;

        return [
            'options' => $optionRows->all(),
            'answer_label' => $answerLabel !== '' ? $answerLabel : null,
            'answer_text' => $answerText,
            'question_type' => $type,
        ];
    }

    /**
     * Writes a local classifier's guess onto the question. A low-confidence
     * guess on ANY of the three dimensions forces tag_status into
     * pending_review regardless of $mode, so a teacher catches weak guesses
     * -- but a topic/subtopic/difficulty/cognitive_level is always written,
     * never left blank, mirroring "the classifier always produces something".
     */
    protected function applyClassification(
        QuestionBankQuestion $question,
        TopicClassification $topic,
        ScalarClassification $cognitiveLevel,
        ScalarClassification $difficulty,
        string $mode,
    ): void {
        $topicModel = $topic->topic !== null ? $this->resolveTopic($question->subject, $topic->topic) : null;
        $subtopicModel = $topicModel && $topic->subtopic !== null
            ? $this->resolveSubtopic($topicModel, $topic->subtopic)
            : null;

        $isLowConfidence = $topic->confidence < LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD
            || $cognitiveLevel->confidence < LocalQuestionClassifier::COGNITIVE_CONFIDENCE_THRESHOLD
            || $difficulty->confidence < LocalQuestionClassifier::DIFFICULTY_CONFIDENCE_THRESHOLD;

        $question->forceFill([
            'topic_id' => $topicModel?->id,
            'subtopic_id' => $subtopicModel?->id,
            'difficulty' => $difficulty->value,
            'cognitive_level' => $cognitiveLevel->value,
            'tag_status' => $isLowConfidence
                ? QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW
                : ($mode === QuestionBank::MAPPING_MODE_AUTO
                    ? QuestionBankQuestion::TAG_STATUS_CONFIRMED
                    : QuestionBankQuestion::TAG_STATUS_PENDING_REVIEW),
            'tag_source' => QuestionBankQuestion::TAG_SOURCE_RULE_BASED,
        ])->save();
    }

    /**
     * Guards against a dictionary-authoring typo or a future edit to
     * CURATED_TOPICS that TopicKeywordDictionary wasn't updated to match --
     * re-validates the classifier's own guess is an exact member of the
     * allowed topic/subtopic list before it's ever persisted.
     *
     * @param  array<string, array<int, string>>  $allowedTopics topic name => subtopic names
     */
    protected function sanitizeLocalTopic(TopicClassification $topic, array $allowedTopics): TopicClassification
    {
        if ($topic->topic === null || !array_key_exists($topic->topic, $allowedTopics)) {
            return new TopicClassification(null, null, 0.0);
        }

        $allowedSubtopics = $allowedTopics[$topic->topic] ?? [];
        $subtopic = $topic->subtopic !== null && in_array($topic->subtopic, $allowedSubtopics, true)
            ? $topic->subtopic
            : null;

        return new TopicClassification($topic->topic, $subtopic, $topic->confidence);
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
