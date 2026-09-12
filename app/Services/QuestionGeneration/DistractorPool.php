<?php

namespace App\Services\QuestionGeneration;

use App\Models\QuestionBankQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pools real, already-vetted "wrong answer" text -- other faculty-authored
 * items' distractor options and correct answers -- scoped to one topic, with
 * a fallback to the whole subject when a topic is too thin to supply enough
 * distinct alternatives. LocalReviewQuestionGenerator never invents a
 * distractor from scratch: every candidate here is something a real teacher
 * wrote as true for SOME item, just not the one being asked about.
 */
class DistractorPool
{
    private const SCOPE_QUERY_LIMIT = 200;

    /**
     * @return Collection<int, string>
     */
    public function candidatesForTopic(string $subject, ?int $topicId, int $excludeQuestionId, string $excludeText): Collection
    {
        $pool = $topicId !== null
            ? $this->collectFromScope($subject, $topicId, $excludeQuestionId)
            : collect();

        // A topic with too few sibling items to supply 3 distinct distractors
        // falls back to the whole subject rather than skipping the variant.
        if ($pool->count() < 3) {
            $pool = $pool->merge($this->collectFromScope($subject, null, $excludeQuestionId));
        }

        $excludeNormalized = Str::lower(trim($excludeText));

        return $pool
            ->map(fn (string $text): string => trim($text))
            ->filter(fn (string $text): bool => $text !== '' && Str::lower($text) !== $excludeNormalized)
            ->unique(fn (string $text): string => Str::lower($text))
            ->values();
    }

    /**
     * Deterministic pick, so results are stable and testable across runs
     * instead of depending on PHP's global RNG state. Ordering is derived
     * from a seed (the source item's id, distinguished per variant) hashed
     * together with each candidate's own text.
     *
     * @param  Collection<int, string>  $candidates
     * @return array<int, string>
     */
    public function pick(Collection $candidates, int $count, int $seed): array
    {
        return $candidates
            ->sortBy(fn (string $text): string => md5($seed . '|' . $text))
            ->values()
            ->take($count)
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    private function collectFromScope(string $subject, ?int $topicId, int $excludeQuestionId): Collection
    {
        $questions = QuestionBankQuestion::query()
            ->with('options:id,question_bank_question_id,option_text,is_correct')
            ->where('usage_type', QuestionBankQuestion::USAGE_ASSESSMENT)
            ->where('subject', $subject)
            ->when($topicId !== null, fn ($query) => $query->where('topic_id', $topicId))
            ->where('id', '!=', $excludeQuestionId)
            ->limit(self::SCOPE_QUERY_LIMIT)
            ->get(['id', 'answer_text']);

        $texts = collect();

        foreach ($questions as $question) {
            $texts->push((string) $question->answer_text);

            foreach ($question->options as $option) {
                $texts->push((string) $option->option_text);
            }
        }

        return $texts->filter(fn (string $text): bool => trim($text) !== '');
    }
}
