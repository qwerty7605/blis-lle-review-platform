<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which question sets an exam draws from, as the exam form sends them: a list
 * (`question_bank_ids`), or the older single `question_bank_id`.
 */
class ExamQuestionBanks
{
    /** @return list<int> distinct positive ids, in the order given */
    public function normalizeIds(array $bankIds): array
    {
        return collect($bankIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The sets a save asks for; when the request names none, the exam's
     * current ones.
     *
     * @return list<int>
     */
    public function resolveIds(array $validated, ?Exam $exam = null): array
    {
        if (array_key_exists('question_bank_ids', $validated)) {
            return $this->normalizeIds($validated['question_bank_ids'] ?? []);
        }

        if (array_key_exists('question_bank_id', $validated)) {
            return !is_null($validated['question_bank_id'])
                ? [(int) $validated['question_bank_id']]
                : [];
        }

        return $exam ? $exam->resolvedQuestionBankIds() : [];
    }

    /**
     * Those of the sets this person may use (their own, or any for an
     * administrator), in the order asked for, with question counts.
     *
     * @return Collection<int, QuestionBank>
     */
    public function findAccessible(User $user, array $bankIds, bool $admin): Collection
    {
        $normalizedBankIds = $this->normalizeIds($bankIds);

        if ($normalizedBankIds === []) {
            return collect();
        }

        $orderLookup = array_flip($normalizedBankIds);

        return QuestionBank::query()
            ->withCount('questions')
            ->whereIn('id', $normalizedBankIds)
            ->when(!$admin, fn ($query) => $query->where('created_by', $user->id))
            ->get()
            ->sortBy(fn (QuestionBank $bank) => $orderLookup[(int) $bank->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * $totalItems shared out over the sets in proportion to their size
     * (largest remainders), never more than a set holds; then a set left with
     * none takes one from the largest share, so no chosen set goes unused.
     * Two sets of 100 and a 30-item exam give 15 and 15; 100 and 20 give 25
     * and 5.
     *
     * @param  array<int, int>  $sizes  set id => questions it holds, in the exam's order
     * @return array<int, int>
     */
    public function proportionalSplit(array $sizes, int $totalItems): array
    {
        $sizeTotal = array_sum($sizes);
        $split = array_map(fn (): int => 0, $sizes);

        if ($sizeTotal < 1 || $totalItems < 1) {
            return $split;
        }

        $remainders = [];

        foreach ($sizes as $id => $size) {
            $exact = $totalItems * $size / $sizeTotal;
            $split[$id] = min($size, (int) floor($exact));
            $remainders[$id] = $exact - floor($exact);
        }

        arsort($remainders);
        $left = min($totalItems, $sizeTotal) - array_sum($split);

        while ($left > 0) {
            $placed = false;

            foreach (array_keys($remainders) as $id) {
                if ($left > 0 && $split[$id] < $sizes[$id]) {
                    $split[$id]++;
                    $left--;
                    $placed = true;
                }
            }

            if (!$placed) {
                break;
            }
        }

        if ($totalItems >= count($sizes)) {
            foreach ($sizes as $id => $size) {
                if ($split[$id] === 0 && $size > 0) {
                    $donor = array_search(max($split), $split, true);

                    if ($donor !== false && $split[$donor] > 1) {
                        $split[$donor]--;
                        $split[$id] = 1;
                    }
                }
            }
        }

        return $split;
    }

    /**
     * The items each set gives a student, by set id: the counts the save sent
     * (`question_bank_items`, one per set in the order of `question_bank_ids`),
     * else the exam's own when its sets and item count stay as they are, else
     * a split by size. A string says what is wrong with the counts sent.
     *
     * @param  Collection<int, QuestionBank>  $banks  the sets, in order, with questions_count
     * @return array<int, int>|string
     */
    public function resolveSplit(array $validated, Collection $banks, int $totalItems, ?Exam $exam = null): array|string
    {
        $sizes = $banks->mapWithKeys(fn (QuestionBank $bank): array => [(int) $bank->id => (int) $bank->questions_count])->all();
        $sent = $validated['question_bank_items'] ?? null;

        if (is_array($sent)) {
            if (count($sent) !== count($sizes)) {
                return 'Give an item count for each question set.';
            }

            $split = array_combine(array_keys($sizes), array_map('intval', array_values($sent)));

            foreach ($banks as $bank) {
                if ($split[(int) $bank->id] > (int) $bank->questions_count) {
                    return sprintf('"%s" has only %d questions.', $bank->title, $bank->questions_count);
                }
            }

            if (array_sum($split) !== $totalItems) {
                return sprintf('The sets\' item counts add up to %d, but the exam has %d items.', array_sum($split), $totalItems);
            }

            return $split;
        }

        $current = $exam?->itemSplit();

        if ($current !== null && array_keys($current) === array_keys($sizes) && array_sum($current) === $totalItems) {
            return $current;
        }

        return $this->proportionalSplit($sizes, $totalItems);
    }
}
