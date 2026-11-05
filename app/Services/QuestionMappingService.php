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
use App\Support\LleTaxonomy;
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
     * The topics and sub-topics come from the LLE Table of Specifications
     * (App\Support\LleTaxonomy) and nowhere else. The classifier, the
     * Library editor and the Review Bot editor can only choose from it, so
     * tags stay comparable across every teacher's uploads instead of
     * fragmenting into near-duplicate names.
     *
     * @return array<int, string>
     */
    public function curatedTopicsForSubject(?string $subject): array
    {
        return array_keys(LleTaxonomy::topics($subject));
    }

    /**
     * @return array<int, string>
     */
    public function curatedSubtopicsForTopic(?string $subject, ?string $topic): array
    {
        return array_keys(LleTaxonomy::topic($subject, $topic)['subtopics'] ?? []);
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    public function curatedTopics(): array
    {
        return LleTaxonomy::curated();
    }

    /**
     * Why a topic / sub-topic pair cannot be saved on a question of this
     * subject, or null when it can (or when neither is given).
     */
    public function tagProblem(?string $subject, ?string $topicName, ?string $subtopicName): ?string
    {
        $topicName = trim((string) $topicName);
        $subtopicName = trim((string) $subtopicName);

        if ($topicName === '' && $subtopicName === '') {
            return null;
        }

        if (LleTaxonomy::subjectKey($subject) === null) {
            return 'Set the question set\'s subject to one of the six board subjects before tagging its questions.';
        }

        if ($topicName !== '' && LleTaxonomy::topic($subject, $topicName) === null) {
            return sprintf('"%s" is not a topic of %s in the Table of Specifications.', $topicName, LleTaxonomy::canonicalSubject($subject));
        }

        if ($topicName !== '' && $subtopicName !== '' && LleTaxonomy::subtopic($subject, $topicName, $subtopicName) === null) {
            return sprintf('"%s" is not a sub-topic of "%s" in the Table of Specifications.', $subtopicName, $topicName);
        }

        return null;
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
            $allowedTopics = LleTaxonomy::curated()[$subject] ?? [];
            $hasCuratedTopics = $allowedTopics !== [];

            // Not one of the six board subjects (typically an unset/
            // undetected "General" subject) doesn't mean nothing can be
            // classified -- cognitive level and difficulty are subject-
            // independent. Only topic/subtopic get skipped (left null,
            // which the confidence gate in applyClassification() already
            // forces into pending_review); a whole bank shouldn't hard-fail
            // over one missing piece of metadata.
            if (!$hasCuratedTopics) {
                $errors[] = sprintf(
                    'Subject "%s" is not one of the six board subjects, so those item(s) got difficulty and cognitive level applied automatically but no topic -- set a board subject on the bank to enable topic mapping.',
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

    /**
     * The topics row for a TOS topic of a board subject, created on first
     * use with its code, question count and place in the TOS. A name the TOS
     * does not have, or a subject that is not a board subject, gives null.
     * The topic may be named by its label ("2. Descriptive Cataloging"), its
     * plain name or its code.
     */
    public function resolveTopic(?string $subject, ?string $topicName, ?int $actorId = null): ?Topic
    {
        $subjectName = LleTaxonomy::canonicalSubject($subject);
        $entry = LleTaxonomy::topic($subjectName, $topicName);

        if ($subjectName === null || $entry === null) {
            return null;
        }

        return Topic::query()->firstOrCreate(
            ['subject' => $subjectName, 'name' => $entry['label']],
            [
                'code' => $entry['code'],
                'tos_items' => $entry['tos_items'],
                'sort_order' => $entry['sort_order'],
                'created_by' => $actorId,
            ],
        );
    }

    /**
     * The subtopics row for a TOS sub-topic of $topic, created on first use;
     * null for a name the TOS does not list under that topic.
     */
    public function resolveSubtopic(Topic $topic, ?string $subtopicName, ?int $actorId = null): ?Subtopic
    {
        $entry = LleTaxonomy::subtopic($topic->subject, $topic->name, $subtopicName);

        if ($entry === null) {
            return null;
        }

        return Subtopic::query()->firstOrCreate(
            ['topic_id' => $topic->id, 'name' => $entry['label']],
            [
                'code' => $entry['code'],
                'tos_items' => $entry['tos_items'],
                'sort_order' => $entry['sort_order'],
                'created_by' => $actorId,
            ],
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
                    // The saved choice this row edits, when it is one.
                    'id' => isset($option['id']) ? (int) $option['id'] : null,
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

        // A question no keyword matched is "Other / Unclassified" outright:
        // that is not a weak topic guess, so it does not on its own send the
        // question to review.
        $isLowConfidence = (!$topic->unclassified && $topic->confidence < LocalQuestionClassifier::TOPIC_CONFIDENCE_THRESHOLD)
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
     * Guards against a dictionary-authoring typo or a TOS edit that
     * TopicKeywordDictionary wasn't updated to match -- re-validates the
     * classifier's own guess is an exact member of the allowed topic/subtopic
     * list before it's ever persisted.
     *
     * @param  array<string, array<int, string>>  $allowedTopics topic label => subtopic labels
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

        return new TopicClassification($topic->topic, $subtopic, $topic->confidence, $topic->unclassified);
    }

    /**
     * A board subject's official name for any of its spellings; anything
     * else title-cased, and an empty subject "General".
     */
    protected function normalizeSubjectLabel(mixed $subject): string
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return 'General';
        }

        return LleTaxonomy::canonicalSubject($raw) ?? Str::title(LleTaxonomy::normalize($raw));
    }
}
