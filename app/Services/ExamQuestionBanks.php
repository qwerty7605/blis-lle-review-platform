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
}
