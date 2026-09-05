<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automated screening for AI-generated Review Bot questions, run inside the
 * generation job before a teacher ever sees the batch.
 *
 * Three passes, cheapest first:
 *   1. Mechanical checks -- no API call. Structural defects only.
 *   2. Near-duplicate rejection -- one batched embeddings call, then cosine
 *      similarity against questions already in the same subject.
 *   3. Answer-key verification -- one cheap completion per question, shown the
 *      question WITHOUT its key. Disagreement is the strongest inexpensive
 *      signal that an item is miskeyed.
 *
 * Nothing here approves or publishes anything. Every question still lands as a
 * draft for the teacher; screening only decides what gets discarded outright
 * (exact-concept duplicates) and what gets flagged to the top of their queue.
 */
class ReviewBotQuestionScreeningService
{
    /**
     * Thresholds calibrated against text-embedding-3-small at 256 dimensions,
     * measured on real Review Bot phrasing:
     *
     *   1.00  identical text
     *   0.97  trivial rewording ("developed" -> "created")
     *   0.66  same concept asked scenario-style
     *   0.54  same concept, fully reworded
     *   0.37  same topic, different concept
     *   0.16  unrelated
     *
     * The 0.54-0.66 band is exactly what generating several question styles
     * from one source item is supposed to produce, so the reject line sits
     * above it. This catches near-verbatim repeats -- it deliberately does NOT
     * try to enforce one question per concept.
     */
    public const DUPLICATE_REJECT_THRESHOLD = 0.90;

    /** Similar enough to flag for a human, not enough to discard. */
    public const DUPLICATE_FLAG_THRESHOLD = 0.80;

    public const FLAG_NO_CORRECT_OPTION = 'no_correct_option';
    public const FLAG_MULTIPLE_CORRECT_OPTIONS = 'multiple_correct_options';
    public const FLAG_DUPLICATE_OPTIONS = 'duplicate_options';
    public const FLAG_OPTION_COUNT = 'unexpected_option_count';
    public const FLAG_ALL_OF_THE_ABOVE = 'all_or_none_of_the_above';
    public const FLAG_ANSWER_LENGTH_GIVEAWAY = 'answer_length_giveaway';
    public const FLAG_MISSING_EXPLANATION = 'missing_explanation';
    public const FLAG_NEAR_DUPLICATE = 'near_duplicate';

    public function hasApiKey(): bool
    {
        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    /**
     * Screen a freshly generated batch.
     *
     * @param  array<int, array<string, mixed>>  $generated
     * @return array{accepted: array<int, array<string, mixed>>, rejected: array<int, array<string, string>>}
     */
    public function screen(array $generated, string $subject, ?int $topicId = null): array
    {
        if ($generated === []) {
            return ['accepted' => [], 'rejected' => []];
        }

        foreach ($generated as $index => $item) {
            $generated[$index]['validation_flags'] = $this->mechanicalFlags($item);
            $generated[$index]['verification_status'] = QuestionBankQuestion::VERIFICATION_UNCHECKED;
            $generated[$index]['verifier_answer_label'] = null;
            $generated[$index]['embedding'] = null;
        }

        $generated = $this->applyDuplicateScreening($generated, $subject, $topicId);

        $accepted = [];
        $rejected = [];

        foreach ($generated as $item) {
            if (($item['rejected_reason'] ?? null) !== null) {
                $rejected[] = [
                    'prompt' => Str::limit((string) ($item['prompt'] ?? ''), 120),
                    'reason' => (string) $item['rejected_reason'],
                ];

                continue;
            }

            $accepted[] = $this->applyAnswerKeyVerification($item);
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Structural defects detectable without any model call.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, string>
     */
    public function mechanicalFlags(array $item): array
    {
        $flags = [];
        $options = is_array($item['options'] ?? null) ? $item['options'] : [];
        $type = (string) ($item['type'] ?? QuestionBankQuestion::TYPE_MULTIPLE_CHOICE);

        $correctCount = collect($options)->filter(fn ($option) => (bool) ($option['is_correct'] ?? false))->count();

        if ($correctCount === 0) {
            $flags[] = self::FLAG_NO_CORRECT_OPTION;
        } elseif ($correctCount > 1) {
            $flags[] = self::FLAG_MULTIPLE_CORRECT_OPTIONS;
        }

        $expectedCount = $type === QuestionBankQuestion::TYPE_TRUE_FALSE ? 2 : 4;

        if (count($options) !== $expectedCount) {
            $flags[] = self::FLAG_OPTION_COUNT;
        }

        $texts = collect($options)
            ->map(fn ($option) => Str::lower(trim((string) ($option['text'] ?? ''))))
            ->filter()
            ->values();

        if ($texts->unique()->count() !== $texts->count()) {
            $flags[] = self::FLAG_DUPLICATE_OPTIONS;
        }

        if ($texts->contains(fn (string $text) => Str::contains($text, ['all of the above', 'none of the above']))) {
            $flags[] = self::FLAG_ALL_OF_THE_ABOVE;
        }

        if (trim((string) ($item['explanation'] ?? '')) === '') {
            $flags[] = self::FLAG_MISSING_EXPLANATION;
        }

        if ($type === QuestionBankQuestion::TYPE_MULTIPLE_CHOICE && $correctCount === 1 && count($options) > 2) {
            $flags = array_merge($flags, $this->answerLengthFlags($options));
        }

        return array_values(array_unique($flags));
    }

    /**
     * A correct option noticeably longer than every distractor is a classic
     * test-writing tell -- students learn to pick the wordiest option.
     *
     * @param  array<int, array<string, mixed>>  $options
     * @return array<int, string>
     */
    protected function answerLengthFlags(array $options): array
    {
        $correct = null;
        $distractorLengths = [];

        foreach ($options as $option) {
            $length = mb_strlen(trim((string) ($option['text'] ?? '')));

            if ((bool) ($option['is_correct'] ?? false)) {
                $correct = $length;

                continue;
            }

            $distractorLengths[] = $length;
        }

        if ($correct === null || $distractorLengths === []) {
            return [];
        }

        $average = array_sum($distractorLengths) / count($distractorLengths);

        if ($average > 0 && $correct > $average * 1.6) {
            return [self::FLAG_ANSWER_LENGTH_GIVEAWAY];
        }

        return [];
    }

    /**
     * Embed the batch, drop anything that already exists in the subject, and
     * drop near-identical items generated within the same batch.
     *
     * @param  array<int, array<string, mixed>>  $generated
     * @return array<int, array<string, mixed>>
     */
    protected function applyDuplicateScreening(array $generated, string $subject, ?int $topicId): array
    {
        $vectors = $this->embed(array_map(
            fn (array $item): string => (string) ($item['prompt'] ?? ''),
            $generated
        ));

        if ($vectors === []) {
            // Embeddings unavailable -- generation still proceeds, just without
            // duplicate screening. Better a possible duplicate than no questions.
            return $generated;
        }

        // One streamed pass over the bank scores every candidate at once,
        // instead of decoding the whole bank into memory and then scanning it
        // per candidate.
        $bestExistingByIndex = $this->bestSimilarityAgainstBank($vectors, $subject, $topicId);
        $acceptedVectors = [];

        foreach ($generated as $index => $item) {
            $vector = $vectors[$index] ?? null;

            if ($vector === null) {
                continue;
            }

            $generated[$index]['embedding'] = $this->encodeVector($vector);

            $bestExisting = $bestExistingByIndex[$index] ?? 0.0;

            if ($bestExisting >= self::DUPLICATE_REJECT_THRESHOLD) {
                $generated[$index]['rejected_reason'] = 'duplicate_of_existing_question';

                continue;
            }

            // Split in-batch similarity by lineage. Variants of one exam item
            // are deliberately related -- a recall and a comprehension question
            // on the same concept sit right in the flag band -- so flagging
            // those would put a warning on a quarter of every batch and teach
            // teachers to ignore flags entirely.
            $sourceIndex = $item['source_index'] ?? null;
            $bestSameSource = 0.0;
            $bestOtherSource = 0.0;

            foreach ($acceptedVectors as $accepted) {
                $similarity = $this->dot($vector, $accepted['vector']);

                if ($sourceIndex !== null && $accepted['source_index'] === $sourceIndex) {
                    $bestSameSource = max($bestSameSource, $similarity);

                    continue;
                }

                $bestOtherSource = max($bestOtherSource, $similarity);
            }

            // Rejection still applies to both: two variants of one item that
            // come back near-identical are as wasteful as a cross-item repeat.
            if (max($bestSameSource, $bestOtherSource) >= self::DUPLICATE_REJECT_THRESHOLD) {
                $generated[$index]['rejected_reason'] = $bestSameSource >= self::DUPLICATE_REJECT_THRESHOLD
                    ? 'duplicate_variant_of_same_item'
                    : 'duplicate_within_batch';

                continue;
            }

            if (max($bestExisting, $bestOtherSource) >= self::DUPLICATE_FLAG_THRESHOLD) {
                $generated[$index]['validation_flags'][] = self::FLAG_NEAR_DUPLICATE;
            }

            $acceptedVectors[] = ['source_index' => $sourceIndex, 'vector' => $vector];
        }

        return $generated;
    }

    /**
     * Highest cosine similarity between each candidate vector and anything
     * already in the bank for this subject (narrowed to the topic when one is
     * given). Vectors are stored normalised, so similarity is a plain dot
     * product.
     *
     * Streamed deliberately: the previous version decoded every stored vector
     * into one PHP array first, which grew without bound as a subject's bank
     * did. Scoring each chunk against all candidates as it arrives keeps only a
     * chunk in memory and produces exactly the same numbers.
     *
     * Still a linear scan -- comfortably fast at a few thousand questions per
     * subject inside a queued job. If a subject's bank grows past that, this is
     * the piece to move behind a vector index.
     *
     * @param  array<int, array<int, float>>  $candidates  index => unit vector
     * @return array<int, float>  index => best similarity found
     */
    protected function bestSimilarityAgainstBank(array $candidates, string $subject, ?int $topicId): array
    {
        $best = [];

        foreach (array_keys($candidates) as $index) {
            $best[$index] = 0.0;
        }

        if ($candidates === []) {
            return $best;
        }

        DB::table('question_bank_questions')
            ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
            ->where('subject', $subject)
            ->when($topicId, fn ($query) => $query->where('topic_id', $topicId))
            ->whereNotNull('embedding')
            ->select('embedding')
            ->orderBy('id')
            ->chunk(500, function (Collection $rows) use ($candidates, &$best): void {
                foreach ($rows as $row) {
                    $stored = $this->decodeVector($row->embedding);

                    if ($stored === []) {
                        continue;
                    }

                    foreach ($candidates as $index => $vector) {
                        $similarity = $this->dot($vector, $stored);

                        if ($similarity > $best[$index]) {
                            $best[$index] = $similarity;
                        }
                    }
                }
            });

        return $best;
    }

    /**
     * Both vectors are unit length, so the dot product is the cosine similarity.
     *
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    protected function dot(array $left, array $right): float
    {
        $length = min(count($left), count($right));
        $sum = 0.0;

        for ($index = 0; $index < $length; $index++) {
            $sum += $left[$index] * $right[$index];
        }

        return $sum;
    }

    /**
     * One batched embeddings call for the whole set.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $texts = array_map(fn (string $text): string => trim($text), $texts);

        if (!$this->hasApiKey() || array_filter($texts) === []) {
            return [];
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        try {
            $response = Http::withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->timeout(60)
                ->post($baseUrl . '/embeddings', [
                    'model' => (string) config('services.openai.embedding_model', 'text-embedding-3-small'),
                    'dimensions' => (int) config('services.openai.embedding_dimensions', 256),
                    'input' => $texts,
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return [];
        }

        $vectors = [];

        foreach (($response['data'] ?? []) as $row) {
            $index = (int) ($row['index'] ?? count($vectors));
            $embedding = $row['embedding'] ?? null;

            if (!is_array($embedding) || $embedding === []) {
                continue;
            }

            $vectors[$index] = $this->normalise(array_map('floatval', $embedding));
        }

        return $vectors;
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    protected function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $value): float => $value / $magnitude, $vector);
    }

    /**
     * @param  array<int, float>  $vector
     */
    public function encodeVector(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * @return array<int, float>
     */
    public function decodeVector(mixed $blob): array
    {
        if (!is_string($blob) || $blob === '') {
            return [];
        }

        $unpacked = unpack('g*', $blob);

        return $unpacked === false ? [] : array_values($unpacked);
    }

    /**
     * Ask an independent completion to answer the question with no answer key
     * in sight, then compare. Failure leaves the item "unchecked" rather than
     * blocking generation.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function applyAnswerKeyVerification(array $item): array
    {
        $expectedLabel = Str::upper(trim((string) ($item['correct_option_label'] ?? '')));
        $options = is_array($item['options'] ?? null) ? $item['options'] : [];

        if (!$this->hasApiKey() || $expectedLabel === '' || $options === []) {
            return $item;
        }

        $answered = $this->askVerifier((string) ($item['prompt'] ?? ''), $options);

        if ($answered === null) {
            return $item;
        }

        $item['verifier_answer_label'] = $answered;
        $item['verification_status'] = $answered === $expectedLabel
            ? QuestionBankQuestion::VERIFICATION_AGREED
            : QuestionBankQuestion::VERIFICATION_DISAGREED;

        return $item;
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    protected function askVerifier(string $prompt, array $options): ?string
    {
        if (trim($prompt) === '') {
            return null;
        }

        $rendered = collect($options)
            ->map(fn ($option): string => sprintf(
                '%s. %s',
                Str::upper(trim((string) ($option['label'] ?? ''))),
                trim((string) ($option['text'] ?? ''))
            ))
            ->implode("\n");

        $systemPrompt = 'You are answering a single exam question. Reply with ONLY the letter label of the option you believe is correct (for example: A). No punctuation, no explanation, no other text.';

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');

        try {
            $response = Http::withToken((string) config('services.openai.api_key'))
                ->acceptJson()
                ->timeout(30)
                ->post($baseUrl . '/chat/completions', [
                    'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                    'temperature' => 0,
                    'max_tokens' => 4,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => trim($prompt) . "\n\n" . $rendered],
                    ],
                ])
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        $content = Str::upper(trim((string) data_get($response, 'choices.0.message.content', '')));

        if (!preg_match('/[A-Z]/', $content, $matches)) {
            return null;
        }

        return $matches[0];
    }
}
