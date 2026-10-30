<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which Review Bot questions a student may practise: those built from an
 * exam item the student was dealt (listed in exam_attempt_questions) in one
 * of their own submitted sittings.
 *
 * An exam's items are left out while:
 *  - its results are set to Hidden: a Review Bot question carries its source
 *    item's answer, which would undo the teacher's choice;
 *  - the student could still sit it again: sittings left (one, or two unless
 *    one_take_only), its end time not passed, and still assigned (not
 *    archived) in a room that is not archived and that the student is in.
 *    Once any of those stops being true, its items are offered.
 *
 * An item dealt in any sitting of an exam that qualifies counts, so a retake
 * that dealt different items adds them. Every student-side read of Review Bot
 * questions goes through constrain(): the catalog, the session selection, the
 * study plan's drill links and the action plan's drill sizes.
 *
 * Nothing is cached here: a controller can outlive one request, and a sitting
 * handed in a moment ago must count at once. Callers work out sourceIds()
 * once per call and pass it on.
 */
class ReviewBotEligibility
{
    public const EMPTY_MESSAGE = 'Practice questions for this topic appear after you take an exam that covers it.';

    /**
     * Narrow a query on question_bank_questions to the questions built from
     * $sourceIds (from sourceIds()). With none, it matches nothing.
     *
     * @template TQuery of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @param  list<int>  $sourceIds
     * @return TQuery
     */
    public function constrain($query, array $sourceIds, string $column = 'question_bank_questions.source_question_id')
    {
        // Inlined integers rather than bindings: a student who has sat many
        // exams can have more ids than SQLite allows bound parameters.
        return $sourceIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIntegerInRaw($column, $sourceIds);
    }

    /**
     * The exam items whose Review Bot questions this student may practise.
     *
     * @return list<int>
     */
    public function sourceIds(User $student): array
    {
        $attempts = DB::table('exam_attempts')
            ->where('user_id', $student->id)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->get(['id', 'exam_id']);

        if ($attempts->isEmpty()) {
            return [];
        }

        $examIds = $attempts->pluck('exam_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $sittings = $attempts->countBy('exam_id');

        $exams = DB::table('exams')
            ->whereIn('id', $examIds)
            ->get(['id', 'results_visibility_mode', 'one_take_only', 'schedule_end_at'])
            ->keyBy('id');

        // Exams still assigned, in a room that is not archived, to a room the
        // student is in: the only places a retake could start.
        $stillAssigned = DB::table('exam_room')
            ->join('rooms', 'rooms.id', '=', 'exam_room.room_id')
            ->join('room_user', function ($join) use ($student): void {
                $join->on('room_user.room_id', '=', 'exam_room.room_id')
                    ->where('room_user.user_id', '=', $student->id);
            })
            ->whereIn('exam_room.exam_id', $examIds)
            ->whereNull('exam_room.archived_at')
            ->whereNull('rooms.archived_at')
            ->distinct()
            ->pluck('exam_room.exam_id')
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $now = now();

        $eligibleExamIds = collect($examIds)
            ->filter(function (int $examId) use ($exams, $sittings, $stillAssigned, $now): bool {
                $exam = $exams->get($examId);

                if (!$exam || ($exam->results_visibility_mode ?? 'hidden') !== 'visible') {
                    return false;
                }

                $allowed = (bool) $exam->one_take_only ? 1 : 2;
                $canRetake = (int) $sittings->get($examId, 0) < $allowed
                    && ($exam->schedule_end_at === null || $now->lt($exam->schedule_end_at))
                    && $stillAssigned->has($examId);

                return !$canRetake;
            })
            ->values()
            ->all();

        if ($eligibleExamIds === []) {
            return [];
        }

        $attemptIds = $attempts
            ->filter(fn ($attempt): bool => in_array((int) $attempt->exam_id, $eligibleExamIds, true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return DB::table('exam_attempt_questions')
            ->whereIntegerInRaw('exam_attempt_id', $attemptIds)
            ->distinct()
            ->pluck('question_bank_question_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
