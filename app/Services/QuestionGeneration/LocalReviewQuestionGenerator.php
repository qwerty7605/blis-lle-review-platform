<?php

namespace App\Services\QuestionGeneration;

use App\Models\QuestionBankOption;
use App\Models\QuestionBankQuestion;
use App\Models\Subtopic;
use App\Services\QuestionClassification\LocalQuestionClassifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Free, local, deterministic stand-in for the old OpenAI-based Review Bot
 * question generator (see ReviewBotAdminService::requestGeneration(), its
 * only caller). No network calls -- every variant is a mechanical
 * transformation or recombination of a teacher's own already-correct exam
 * item, never an invented fact. Distractors come from DistractorPool (real
 * text pooled from other items), never fabricated.
 *
 * Four templates, one per Bloom's-style cognitive level, mirroring what the
 * old AI prompt targeted:
 *  - recall ("distractor-resampled recall"): same stem/answer, fresh pooled
 *    distractors.
 *  - comprehension ("definition-reversal"): stem and answer swap roles.
 *  - application ("scenario wrapper"): the concept framed inside a generic
 *    per-subject carrier sentence (see ScenarioTemplateDictionary).
 *  - analysis ("paired-attribute discrimination"): a same-topic sibling
 *    item's (term, fact) pair combined with this item's own into 4
 *    forced-mismatched pairings -- exactly one is correct by construction, so
 *    nothing false is ever invented.
 *
 * A level is silently omitted (not forced) when its template's data
 * requirements aren't met for a given source item -- mirrors the old prompt's
 * "if a source item genuinely cannot support one of the levels, omit that
 * level for that item rather than inventing content to fill it."
 *
 * Output matches ReviewBotAdminService::normalizeGeneratedQuestions()'s input
 * contract exactly (source_index, prompt, type, options[{id,text}],
 * correct_option_id, explanation, difficulty, cognitive_level), so the
 * existing validation/screening/persistence pipeline needs no changes.
 */
class LocalReviewQuestionGenerator
{
    private const OPTION_IDS = ['a', 'b', 'c', 'd'];

    public function __construct(
        private readonly LocalQuestionClassifier $classifier = new LocalQuestionClassifier(),
        private readonly DistractorPool $distractorPool = new DistractorPool(),
    ) {
    }

    /**
     * @param  Collection<int, QuestionBankQuestion>  $sourceQuestions
     * @return array<int, array<string, mixed>>
     */
    public function generate(Collection $sourceQuestions, string $subject): array
    {
        $generated = [];

        foreach ($sourceQuestions->values() as $index => $source) {
            foreach ($this->buildVariantsForSource($source, $subject, $index) as $variant) {
                $generated[] = $variant;
            }
        }

        return $generated;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildVariantsForSource(QuestionBankQuestion $source, string $subject, int $sourceIndex): array
    {
        $stem = trim((string) $source->question_text);
        $correctOption = $source->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);
        $answer = trim((string) ($source->answer_text ?? $correctOption?->option_text ?? ''));

        if ($stem === '' || $answer === '') {
            return [];
        }

        $variants = array_filter([
            $this->buildRecallVariant($source, $subject, $sourceIndex, $stem, $answer),
            $this->buildComprehensionVariant($source, $subject, $sourceIndex, $stem, $answer),
            $this->buildApplicationVariant($source, $subject, $sourceIndex, $answer),
            $this->buildAnalysisVariant($source, $subject, $sourceIndex),
        ]);

        return array_values($variants);
    }

    // ---- recall ---------------------------------------------------------

    private function buildRecallVariant(QuestionBankQuestion $source, string $subject, int $sourceIndex, string $stem, string $answer): ?array
    {
        $seed = (int) $source->id * 11 + 1;

        $distractors = $this->distractorPool->pick(
            $this->distractorPool->candidatesForTopic($subject, $source->topic_id, (int) $source->id, $answer),
            3,
            $seed,
        );

        if (count($distractors) < 3) {
            return null;
        }

        return $this->buildMultipleChoiceItem(
            sourceIndex: $sourceIndex,
            seed: $seed,
            prompt: $stem,
            correctText: $answer,
            distractorTexts: $distractors,
            explanation: $this->sourceExplanation($source, $answer),
            cognitiveLevel: QuestionBankQuestion::COGNITIVE_LEVELS[0], // recall
        );
    }

    // ---- comprehension ----------------------------------------------------

    private function buildComprehensionVariant(QuestionBankQuestion $source, string $subject, int $sourceIndex, string $stem, string $answer): ?array
    {
        if ($source->topic_id === null) {
            return null;
        }

        $correctPhrase = $this->stripInterrogativeLead($stem) ?? $source->subtopic?->name ?? $source->topic?->name;

        if (!$correctPhrase) {
            return null;
        }

        $seed = (int) $source->id * 11 + 2;

        $siblingSubtopics = Subtopic::query()
            ->where('topic_id', $source->topic_id)
            ->when($source->subtopic_id, fn ($query) => $query->where('id', '!=', $source->subtopic_id))
            ->pluck('name')
            ->filter(fn (string $name): bool => Str::lower($name) !== Str::lower($correctPhrase))
            ->values();

        $distractors = $siblingSubtopics->count() >= 3
            ? $this->distractorPool->pick($siblingSubtopics, 3, $seed)
            : [];

        // Not enough sibling subtopic names to fill 4 options -- widen to the
        // topic's own answer-text pool instead of leaving this level thin.
        if (count($distractors) < 3) {
            $topicPool = $this->distractorPool
                ->candidatesForTopic($subject, $source->topic_id, (int) $source->id, $answer)
                ->reject(fn (string $text): bool => Str::lower($text) === Str::lower($correctPhrase));

            $distractors = $this->distractorPool->pick($topicPool, 3, $seed);
        }

        if (count($distractors) < 3) {
            return null;
        }

        return $this->buildMultipleChoiceItem(
            sourceIndex: $sourceIndex,
            seed: $seed,
            prompt: sprintf('Which of the following is best identified by this description: "%s"?', $answer),
            correctText: $correctPhrase,
            distractorTexts: $distractors,
            explanation: sprintf('"%s" describes %s.', $answer, $correctPhrase),
            cognitiveLevel: QuestionBankQuestion::COGNITIVE_LEVELS[1], // comprehension
        );
    }

    // ---- application ----------------------------------------------------

    private function buildApplicationVariant(QuestionBankQuestion $source, string $subject, int $sourceIndex, string $answer): ?array
    {
        $concept = $source->subtopic?->name ?? $source->topic?->name;
        $templates = ScenarioTemplateDictionary::TEMPLATES[$subject] ?? [];

        if (!$concept || $templates === []) {
            return null;
        }

        $seed = (int) $source->id * 11 + 3;
        $template = $templates[(int) $source->id % count($templates)];
        $prompt = str_replace('{concept}', $concept, $template);

        $distractors = $this->distractorPool->pick(
            $this->distractorPool->candidatesForTopic($subject, $source->topic_id, (int) $source->id, $answer),
            3,
            $seed,
        );

        if (count($distractors) < 3) {
            return null;
        }

        return $this->buildMultipleChoiceItem(
            sourceIndex: $sourceIndex,
            seed: $seed,
            prompt: $prompt,
            correctText: $answer,
            distractorTexts: $distractors,
            explanation: $this->sourceExplanation($source, $answer),
            cognitiveLevel: QuestionBankQuestion::COGNITIVE_LEVELS[2], // application
        );
    }

    // ---- analysis ---------------------------------------------------------

    /**
     * Finds one same-topic, different-subtopic sibling and builds all 4
     * (term, fact) pairings from the two items' own (already-correct) terms
     * and facts. Exactly one pairing -- the two items' own -- is correct;
     * the other 3 are definitionally wrong cross-pairings, so nothing false
     * is ever asserted that wasn't already implied by the two real items.
     */
    private function buildAnalysisVariant(QuestionBankQuestion $source, string $subject, int $sourceIndex): ?array
    {
        if ($source->topic_id === null) {
            return null;
        }

        $termA = $source->subtopic?->name ?? $source->topic?->name;
        $correctOption = $source->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);
        $factA = trim((string) ($source->answer_text ?? $correctOption?->option_text ?? ''));

        if (!$termA || $factA === '') {
            return null;
        }

        $seed = (int) $source->id * 11 + 4;

        $sibling = QuestionBankQuestion::query()
            ->with('options:id,question_bank_question_id,option_text,is_correct')
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('subject', $subject)
            ->where('topic_id', $source->topic_id)
            ->where('id', '!=', $source->id)
            ->when($source->subtopic_id, fn ($query) => $query->where(function ($inner) use ($source) {
                $inner->whereNull('subtopic_id')->orWhere('subtopic_id', '!=', $source->subtopic_id);
            }))
            ->limit(20)
            ->get()
            ->sortBy(fn (QuestionBankQuestion $candidate): string => md5($seed . '|' . $candidate->id))
            ->first();

        if (!$sibling) {
            return null;
        }

        $termB = $sibling->subtopic?->name ?? $sibling->topic?->name;
        $siblingCorrect = $sibling->options->first(fn (QuestionBankOption $option): bool => (bool) $option->is_correct);
        $factB = trim((string) ($sibling->answer_text ?? $siblingCorrect?->option_text ?? ''));

        if (!$termB || $factB === '' || Str::lower($termA) === Str::lower($termB) || Str::lower($factA) === Str::lower($factB)) {
            return null;
        }

        $shorten = fn (string $text): string => Str::limit($text, 140);

        $pairs = [
            ['text' => sprintf('%s: %s', $termA, $shorten($factA)), 'correct' => true],
            ['text' => sprintf('%s: %s', $termB, $shorten($factA)), 'correct' => false],
            ['text' => sprintf('%s: %s', $termA, $shorten($factB)), 'correct' => false],
            ['text' => sprintf('%s: %s', $termB, $shorten($factB)), 'correct' => false],
        ];

        $ordered = collect($pairs)->sortBy(fn (array $pair): string => md5($seed . '|' . $pair['text']))->values();

        $options = [];
        $correctId = null;

        foreach ($ordered as $position => $pair) {
            $id = self::OPTION_IDS[$position];
            $options[] = ['id' => $id, 'text' => $pair['text']];

            if ($pair['correct']) {
                $correctId = $id;
            }
        }

        $prompt = 'Which of the following correctly pairs a concept with its defining detail?';

        return [
            'source_index' => $sourceIndex,
            'prompt' => $prompt,
            'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $options,
            'correct_option_id' => $correctId,
            'explanation' => sprintf('%s is defined by "%s" -- not "%s", which belongs to %s.', $termA, $shorten($factA), $shorten($factB), $termB),
            'difficulty' => $this->estimateDifficulty($prompt, $this->optionsForDifficulty($options, $correctId)),
            'cognitive_level' => QuestionBankQuestion::COGNITIVE_LEVELS[3], // analysis
        ];
    }

    // ---- shared helpers -----------------------------------------------

    /**
     * @param  array<int, string>  $distractorTexts
     */
    private function buildMultipleChoiceItem(
        int $sourceIndex,
        int $seed,
        string $prompt,
        string $correctText,
        array $distractorTexts,
        ?string $explanation,
        string $cognitiveLevel,
    ): ?array {
        $pairs = collect(array_merge([$correctText], $distractorTexts))
            ->unique(fn (string $text): string => Str::lower(trim($text)))
            ->values();

        if ($pairs->count() < 4) {
            return null;
        }

        $ordered = $pairs->take(4)->sortBy(fn (string $text): string => md5($seed . '|' . $text))->values();

        $options = [];
        $correctId = null;

        foreach ($ordered as $position => $text) {
            $id = self::OPTION_IDS[$position];
            $options[] = ['id' => $id, 'text' => $text];

            if ($correctId === null && Str::lower(trim($text)) === Str::lower(trim($correctText))) {
                $correctId = $id;
            }
        }

        if ($correctId === null) {
            return null;
        }

        return [
            'source_index' => $sourceIndex,
            'prompt' => $prompt,
            'type' => QuestionBankQuestion::TYPE_MULTIPLE_CHOICE,
            'options' => $options,
            'correct_option_id' => $correctId,
            'explanation' => $explanation,
            'difficulty' => $this->estimateDifficulty($prompt, $this->optionsForDifficulty($options, $correctId)),
            'cognitive_level' => $cognitiveLevel,
        ];
    }

    private function sourceExplanation(QuestionBankQuestion $source, string $answer): string
    {
        return $source->questionBank?->title !== null
            ? sprintf('Based on item #%d of "%s": %s', (int) $source->item_number, $source->questionBank->title, $answer)
            : $answer;
    }

    /**
     * Strips a known interrogative lead ("What is", "Which of the following
     * is", "Define", ...) off a question stem to recover a plain noun-phrase
     * answer for the comprehension "definition-reversal" template. Returns
     * null (never a mangled guess) when the stem doesn't match a known
     * pattern -- callers fall back to the source's own subtopic/topic name,
     * which is always a clean, template-ready phrase (see
     * TopicKeywordDictionary's docblock).
     */
    private function stripInterrogativeLead(string $stem): ?string
    {
        $patterns = [
            '/^what\s+(?:is|are)\s+the\s+term\s+for\s+/i',
            '/^what\s+(?:is|are)\s+/i',
            '/^which\s+of\s+the\s+following\s+(?:is|are)\s+/i',
            '/^who\s+is\s+/i',
            '/^where\s+is\s+/i',
            '/^define\s*:?\s*/i',
            '/^identify\s+the\s+/i',
            '/^identify\s+/i',
        ];

        $stripped = $stem;
        $matched = false;

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $stripped) === 1) {
                $stripped = preg_replace($pattern, '', $stripped) ?? $stripped;
                $matched = true;

                break;
            }
        }

        if (!$matched) {
            return null;
        }

        $stripped = trim($stripped, " \t\n\r\0\x0B?");

        // A leftover fragment too short/generic isn't a usable noun phrase --
        // the topic/subtopic fallback (see caller) is safer than a guess.
        if (mb_strlen($stripped) < 4) {
            return null;
        }

        return Str::ucfirst($stripped);
    }

    /**
     * @param  array<int, array{id: string, text: string}>  $options
     * @return array<int, array{text: string, is_correct: bool}>
     */
    private function optionsForDifficulty(array $options, ?string $correctId): array
    {
        return array_map(fn (array $option): array => [
            'text' => $option['text'],
            'is_correct' => $option['id'] === $correctId,
        ], $options);
    }

    /**
     * Runs the generated stem/options through the same free difficulty
     * heuristic already used for item mapping (see
     * LocalQuestionClassifier::classifyDifficulty()) instead of inventing a
     * second one -- built as an unsaved, in-memory model since nothing here
     * is persisted yet.
     *
     * @param  array<int, array{text: string, is_correct: bool}>  $options
     */
    private function estimateDifficulty(string $prompt, array $options): string
    {
        $question = new QuestionBankQuestion(['question_text' => $prompt]);
        $question->setRelation('options', collect($options)->map(
            fn (array $option): QuestionBankOption => new QuestionBankOption([
                'option_text' => $option['text'],
                'is_correct' => $option['is_correct'],
            ])
        ));

        return $this->classifier->classifyDifficulty($question)->value;
    }
}
