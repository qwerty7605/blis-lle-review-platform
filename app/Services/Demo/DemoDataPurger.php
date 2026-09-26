<?php

namespace App\Services\Demo;

use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Removes everything the demo seeders created, and nothing else.
 *
 * Demo data is found from its accounts outward: every demo account is marked
 * users.is_demo (or, if seeded before that flag existed, sits on a demo-only
 * domain), and every room, exam and Library file one of them made carries its
 * id in created_by. Nothing is matched by title, room code or address, so a
 * real "BLIS 4-A" -- or a real teacher on the same domain as the demo's -- is
 * never at risk.
 *
 * While demo data is live, real accounts can get tangled up with it. Each case
 * is handled so no real record disappears without being accounted for:
 *  - a demo Library file that a real exam uses (or that real attempts already
 *    drew items from) is kept and handed to that exam's author -- it stopped
 *    being demo content when real students sat it;
 *  - a demo-owned Review Bot bank that real teachers have since generated into
 *    is kept, minus the demo questions;
 *  - real accounts' attempts on demo exams, their Review Bot sessions that drew
 *    demo questions, and their memberships in demo rooms go with the demo
 *    content (plan() counts them first);
 *  - a real attempt on a real exam inside a demo room would be cascade-deleted
 *    with the room, so the purge refuses and names it instead.
 *
 * It all runs in one transaction: everything goes, or nothing does.
 */
final class DemoDataPurger
{
    /** @var list<string> */
    private array $domains;

    /**
     * @param  list<string>|null  $domains  demo-only domains whose accounts count as demo even without the
     *                                      flag; defaults to all of them
     */
    public function __construct(?array $domains = null)
    {
        $this->domains = array_values($domains ?? DemoData::emailDomains());
    }

    /**
     * What purge() would remove and keep, without changing anything.
     *
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        $scope = $this->scope();

        return [
            'accounts' => $this->demoAccounts()->count(),
            'students' => $this->demoAccounts()->where('role', User::ROLE_STUDENT)->count(),
            'rooms' => count($scope['rooms']),
            'exams' => count($scope['exams']),
            'library_files' => count($scope['banks']),
            'questions' => count($scope['questions']),
            'exam_attempts' => $this->attempts($scope)->count(),
            'review_sessions' => $this->sessions($scope)->count(),
            'licensure_results' => DB::table('lle_results')->whereIn('user_id', $this->demoUserIds())->count(),
            'audit_entries' => DB::table('audit_logs')->whereIn('actor_id', $this->demoUserIds())->count(),
            'real_account_attempts' => $this->attempts($scope)->whereNotIn('user_id', $this->demoUserIds())->count(),
            'real_account_sessions' => $this->sessions($scope)->whereNotIn('user_id', $this->demoUserIds())->count(),
            'real_room_memberships' => DB::table('room_user')
                ->whereIn('room_id', $scope['rooms'])
                ->whereNotIn('user_id', $this->demoUserIds())
                ->count(),
            'adopted_files' => $this->titles(array_keys($scope['adopted'])),
            'shared_review_banks' => $this->titles($scope['shared']),
            'blocking' => $scope['blocking'],
        ];
    }

    /**
     * @return array<string, int> what was removed
     *
     * @throws RuntimeException when real records would be lost (see plan()['blocking'])
     */
    public function purge(): array
    {
        return DB::transaction(function (): array {
            $scope = $this->scope();

            if ($scope['blocking'] !== []) {
                throw new RuntimeException(
                    "Demo data was not removed, because real records would go with it:\n"
                    . implode("\n", $scope['blocking'])
                );
            }

            $removed = [];

            // Sessions first: review_bot_attempts restricts deleting its user.
            // Their per-item rows cascade with them.
            $removed['review_sessions'] = $this->deleteInChunks($this->sessions($scope));

            DB::table('review_bot_item_states')
                ->where(fn (Builder $query) => $query
                    ->whereIn('user_id', $this->demoUserIds())
                    ->orWhereIn('question_bank_question_id', $scope['questions']))
                ->delete();

            // Also restricted on the user; answers and item rows cascade.
            $removed['exam_attempts'] = $this->deleteInChunks($this->attempts($scope));
            $removed['licensure_results'] = DB::table('lle_results')->whereIn('user_id', $this->demoUserIds())->delete();
            $removed['audit_entries'] = DB::table('audit_logs')->whereIn('actor_id', $this->demoUserIds())->delete();

            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $this->demoUserIds())
                ->delete();
            DB::table('sessions')->whereIn('user_id', $this->demoUserIds())->delete();
            DB::table('password_reset_tokens')->whereIn('email', $this->demoAccounts()->select('email'))->delete();

            // Review questions generated from demo items, including any that
            // landed in a real teacher's Review Bot bank -- whose item count
            // the generator raised, so it is recounted once they are gone.
            $touchedBanks = DB::table('question_bank_questions')
                ->whereIn('id', $scope['variants'])
                ->distinct()
                ->pluck('question_bank_id')
                ->all();

            DB::table('question_bank_questions')->whereIn('id', $scope['variants'])->delete();

            foreach (array_diff($touchedBanks, $scope['banks']) as $bankId) {
                DB::table('question_banks')->where('id', $bankId)->update([
                    'total_items' => DB::table('question_bank_questions')->where('question_bank_id', $bankId)->count(),
                ]);
            }

            $removed['exams'] = DB::table('exams')->whereIn('id', $scope['exams'])->delete();
            $removed['rooms'] = DB::table('rooms')->whereIn('id', $scope['rooms'])->delete();
            $removed['library_files'] = DB::table('question_banks')->whereIn('id', $scope['banks'])->delete();

            foreach ($scope['adopted'] as $bankId => $ownerId) {
                DB::table('question_banks')->where('id', $bankId)->update(['created_by' => $ownerId]);
            }

            DB::table('question_banks')->whereIn('id', $scope['shared'])->update(['created_by' => null]);

            $removed['topics'] = $this->deleteUnusedTaxonomy();
            $removed['accounts'] = $this->deleteInChunks($this->demoAccounts());

            return $removed;
        });
    }

    /**
     * @return array{
     *   rooms: list<int>,
     *   exams: list<int>,
     *   banks: list<int>,
     *   variants: list<int>,
     *   questions: list<int>,
     *   adopted: array<int, int|null>,
     *   shared: list<int>,
     *   blocking: list<string>
     * }
     */
    private function scope(): array
    {
        $rooms = $this->ownedByDemo('rooms');
        $exams = $this->ownedByDemo('exams');

        $owned = DB::table('question_banks')
            ->whereIn('created_by', $this->demoUserIds())
            ->get(['id', 'title']);

        $isReviewBank = fn (object $bank): bool => Str::startsWith(
            (string) $bank->title,
            QuestionBank::REVIEW_BOT_BANK_TITLE_PREFIX,
        );

        $library = $owned->reject($isReviewBank)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $adopted = $this->adoptedLibraryFiles($library, $exams);
        $deletableLibrary = array_values(array_diff($library, array_keys($adopted)));

        $variants = $this->ids(
            DB::table('question_bank_questions')
                ->where('usage_type', QuestionBankQuestion::USAGE_REVIEW_BOT)
                ->whereIn('source_question_id', DB::table('question_bank_questions')
                    ->select('id')
                    ->whereIn('question_bank_id', $deletableLibrary))
        );

        // reviewBotBankFor() hands out one bank per subject to whoever asks,
        // so a bank the demo created may since hold real teachers' questions.
        $shared = [];
        $deletableReview = [];

        foreach ($owned->filter($isReviewBank) as $bank) {
            $holdsRealQuestions = DB::table('question_bank_questions')
                ->where('question_bank_id', $bank->id)
                ->whereNotIn('id', $variants)
                ->exists();

            if ($holdsRealQuestions) {
                $shared[] = (int) $bank->id;
            } else {
                $deletableReview[] = (int) $bank->id;
            }
        }

        $banks = array_merge($deletableLibrary, $deletableReview);

        $questions = array_values(array_unique(array_merge(
            $this->ids(DB::table('question_bank_questions')->whereIn('question_bank_id', $banks)),
            $variants,
        )));

        return [
            'rooms' => $rooms,
            'exams' => $exams,
            'banks' => $banks,
            'variants' => $variants,
            'questions' => $questions,
            'adopted' => $adopted,
            'shared' => $shared,
            'blocking' => $this->blockingAttempts($rooms, $exams),
        ];
    }

    /**
     * Demo Library files a real exam has started using, mapped to who should
     * own them from now on.
     *
     * @param  list<int>  $library
     * @param  list<int>  $demoExams
     * @return array<int, int|null>
     */
    private function adoptedLibraryFiles(array $library, array $demoExams): array
    {
        if ($library === []) {
            return [];
        }

        $uses = DB::table('exams')
            ->whereNotIn('id', $demoExams)
            ->whereIn('question_bank_id', $library)
            ->get(['question_bank_id as bank_id', 'created_by'])
            ->concat(
                DB::table('exam_question_bank')
                    ->join('exams', 'exams.id', '=', 'exam_question_bank.exam_id')
                    ->whereNotIn('exams.id', $demoExams)
                    ->whereIn('exam_question_bank.question_bank_id', $library)
                    ->get(['exam_question_bank.question_bank_id as bank_id', 'exams.created_by'])
            )
            // Past real attempts already holding its items, even if the exam
            // has since been pointed at a different file.
            ->concat(
                DB::table('exam_attempt_questions')
                    ->join('question_bank_questions', 'question_bank_questions.id', '=', 'exam_attempt_questions.question_bank_question_id')
                    ->join('exam_attempts', 'exam_attempts.id', '=', 'exam_attempt_questions.exam_attempt_id')
                    ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
                    ->whereIn('question_bank_questions.question_bank_id', $library)
                    ->whereNotIn('exam_attempts.exam_id', $demoExams)
                    ->whereNotIn('exam_attempts.user_id', $this->demoUserIds())
                    ->distinct()
                    ->get(['question_bank_questions.question_bank_id as bank_id', 'exams.created_by'])
            );

        $adopted = [];

        foreach ($uses as $use) {
            // ??= keeps looking past an author-less exam for one with an owner.
            $adopted[(int) $use->bank_id] ??= $use->created_by !== null ? (int) $use->created_by : null;
        }

        return $adopted;
    }

    /**
     * Real attempts on real exams that sit inside a demo room. Deleting the
     * room would cascade to them, so these stop the purge.
     *
     * @param  list<int>  $rooms
     * @param  list<int>  $exams
     * @return list<string>
     */
    private function blockingAttempts(array $rooms, array $exams): array
    {
        if ($rooms === []) {
            return [];
        }

        return DB::table('exam_attempts')
            ->join('exams', 'exams.id', '=', 'exam_attempts.exam_id')
            ->join('rooms', 'rooms.id', '=', 'exam_attempts.room_id')
            ->whereIn('exam_attempts.room_id', $rooms)
            ->whereNotIn('exam_attempts.exam_id', $exams)
            ->whereNotIn('exam_attempts.user_id', $this->demoUserIds())
            ->groupBy('exams.title', 'rooms.name')
            ->selectRaw('exams.title as exam_title, rooms.name as room_name, COUNT(*) as attempts')
            ->get()
            ->map(fn (object $row): string => sprintf(
                '- the real exam "%s" has %d attempt(s) by real students in the demo room "%s". '
                . 'Unassign that exam from the room or move those students first.',
                $row->exam_title,
                $row->attempts,
                $row->room_name,
            ))
            ->all();
    }

    /**
     * Topics and subtopics a demo account created that nothing uses any more.
     * The seeders only create a curated topic when it is missing, so one a
     * real teacher's upload was since classified into is still referenced and
     * stays.
     */
    private function deleteUnusedTaxonomy(): int
    {
        $subtopics = DB::table('subtopics')
            ->whereIn('created_by', $this->demoUserIds())
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('question_bank_questions')
                ->whereColumn('question_bank_questions.subtopic_id', 'subtopics.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('review_bot_attempts')
                ->whereColumn('review_bot_attempts.subtopic_id', 'subtopics.id'))
            ->delete();

        $topics = DB::table('topics')
            ->whereIn('created_by', $this->demoUserIds())
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('question_bank_questions')
                ->whereColumn('question_bank_questions.topic_id', 'topics.id'))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('review_bot_attempts')
                ->whereColumn('review_bot_attempts.topic_id', 'topics.id'))
            // Any subtopic still under it -- someone else's, or one in use.
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                ->from('subtopics')
                ->whereColumn('subtopics.topic_id', 'topics.id'))
            ->delete();

        return $subtopics + $topics;
    }

    /**
     * Every exam attempt that goes: by a demo account, on a demo exam, or in a
     * demo room.
     *
     * @param  array{rooms: list<int>, exams: list<int>}  $scope
     */
    private function attempts(array $scope): Builder
    {
        return DB::table('exam_attempts')->where(fn (Builder $query) => $query
            ->whereIn('user_id', $this->demoUserIds())
            ->orWhereIn('exam_id', $scope['exams'])
            ->orWhereIn('room_id', $scope['rooms']));
    }

    /**
     * Every Review Bot session that goes: by a demo account, or one that drew
     * a demo question.
     *
     * @param  array{questions: list<int>}  $scope
     */
    private function sessions(array $scope): Builder
    {
        return DB::table('review_bot_attempts')->where(fn (Builder $query) => $query
            ->whereIn('user_id', $this->demoUserIds())
            ->orWhereIn('id', DB::table('review_bot_attempt_questions')
                ->select('review_bot_attempt_id')
                ->whereIn('question_bank_question_id', $scope['questions'])));
    }

    private function demoAccounts(): Builder
    {
        return DB::table('users')->where(function (Builder $query): void {
            $query->where('is_demo', true);

            foreach ($this->domains as $domain) {
                $query->orWhere('email', 'like', '%@' . $domain);
            }
        });
    }

    /**
     * A fresh subquery each call: query builders are mutable, so one instance
     * shared between queries would collect every caller's constraints.
     */
    private function demoUserIds(): Builder
    {
        return $this->demoAccounts()->select('id');
    }

    /**
     * @return list<int>
     */
    private function ownedByDemo(string $table): array
    {
        return $this->ids(DB::table($table)->whereIn('created_by', $this->demoUserIds()));
    }

    /**
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  list<int>  $bankIds
     * @return list<string>
     */
    private function titles(array $bankIds): array
    {
        return DB::table('question_banks')->whereIn('id', $bankIds)->orderBy('title')->pluck('title')->all();
    }

    /**
     * Deletes in batches, so a large demo does not become one statement with
     * tens of thousands of cascades behind it.
     */
    private function deleteInChunks(Builder $query, int $size = 1000): int
    {
        $deleted = 0;

        while (true) {
            $ids = (clone $query)->orderBy('id')->limit($size)->pluck('id')->all();

            if ($ids === []) {
                return $deleted;
            }

            $deleted += DB::table($query->from)->whereIn('id', $ids)->delete();
        }
    }
}
