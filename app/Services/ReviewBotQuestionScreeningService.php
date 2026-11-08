<?php

namespace App\Services;

use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Automated screening for locally-generated Review Bot questions (see
 * LocalReviewQuestionGenerator), run inside the generation job before a
 * teacher ever sees the batch.
 *
 * Two passes, cheapest first:
 *   1. Mechanical checks -- structural defects only.
 *   2. Near-duplicate rejection -- local feature-hashed text vectors (no
 *      network call, see embed()), then cosine similarity against questions
 *      already in the same subject.
 *
 * There used to be a third pass, an AI completion asked to independently
 * re-derive the answer key as a miskey check. It's gone, not just disabled:
 * LocalReviewQuestionGenerator never re-derives an answer, it only inherits
 * one from an already-vetted source item (see its class docblock), so there
 * is nothing left for a second opinion to check.
 *
 * Nothing here approves or publishes anything. Every question still lands as a
 * draft for the teacher; screening only decides what gets discarded outright
 * (exact-concept duplicates) and what gets flagged to the top of their queue.
 */
class ReviewBotQuestionScreeningService
{
    /**
     * Dimensionality of the local hashed text vectors embed() produces (see
     * its docblock) -- kept at the same size the old OpenAI embeddings used
     * so the on-disk blob format (encodeVector()/decodeVector()) didn't need
     * to change.
     */
    private const VECTOR_DIMENSIONS = 256;

    /**
     * Thresholds for cosine similarity between two local hashed-word-count
     * vectors (see embed()). Unlike a semantic embedding, these vectors are
     * purely lexical -- similarity tracks shared wording, not shared meaning
     * -- so the practical effect is closer to "textually near-identical" than
     * "same concept different words". That's an intentionally tighter net
     * than the old semantic version: it catches accidental near-verbatim
     * repeats (e.g. a top-up run re-covering an already-generated area)
     * without trying to police paraphrase quality, which local generation's
     * deterministic templates don't produce in the first place.
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

            $accepted[] = $item;
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
     * Turns each text into a fixed-length local vector via feature hashing
     * (the "hashing trick" -- the same idea behind scikit-learn's
     * HashingVectorizer / Vowpal Wabbit) weighted by batch-local inverse
     * document frequency (the same pairing scikit-learn's TfidfTransformer
     * gives a HashingVectorizer): tokenize into lowercase words, hash each
     * token into one of VECTOR_DIMENSIONS buckets, and accumulate each hit
     * weighted by how rare that token is *within this call's own texts*.
     *
     * The IDF weighting matters specifically because of how
     * LocalReviewQuestionGenerator works: many generated prompts in the same
     * batch share a fixed template ("Which of the following is best
     * identified by this description...", the same scenario carrier
     * sentence, ...) and differ only in a handful of content words. A plain
     * word-count vector would let that shared boilerplate dominate and flag
     * unrelated variants as near-duplicates; downweighting tokens that
     * appear in most of the batch's texts lets the actual distinguishing
     * words -- the ones that differ per concept -- drive the comparison
     * instead. No training, no persisted vocabulary, no network call.
     *
     * Same return contract the old OpenAI-backed version had (unit-normalised
     * vectors keyed by input index, missing index for a text with no
     * tokens), so every caller downstream (applyDuplicateScreening(),
     * bestSimilarityAgainstBank(), dot(), encodeVector()/decodeVector())
     * needed zero changes.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        $documents = [];

        foreach ($texts as $index => $text) {
            $tokens = $this->tokenize((string) $text);

            if ($tokens !== []) {
                $documents[$index] = $tokens;
            }
        }

        if ($documents === []) {
            return [];
        }

        $documentFrequency = [];

        foreach ($documents as $tokens) {
            foreach (array_unique($tokens) as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }

        $documentCount = count($documents);
        $vectors = [];

        foreach ($documents as $index => $tokens) {
            $vector = array_fill(0, self::VECTOR_DIMENSIONS, 0.0);

            foreach ($tokens as $token) {
                // Smoothed IDF: a token in every text of the batch (weight
                // approaching log(1) = 0) contributes almost nothing; a token
                // unique to one text weighs the most.
                $weight = log((1 + $documentCount) / (1 + $documentFrequency[$token]));
                $bucket = crc32($token) % self::VECTOR_DIMENSIONS;
                $vector[$bucket] += $weight;
            }

            $vectors[$index] = $this->normalise($vector);
        }

        return $vectors;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $normalized = Str::lower(trim($text));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? $normalized;
        $tokens = preg_split('/\s+/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Single-character tokens are almost entirely punctuation/option
        // labels ("a", "b") left over from stripping, not real words -- they
        // add noise without adding signal to a similarity comparison.
        return array_values(array_filter($tokens, fn (string $token): bool => mb_strlen($token) > 1));
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

}
