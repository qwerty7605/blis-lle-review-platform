<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * What may still change about an exam once students have started it.
 *
 * Every student who sits an exam sits the same one: once anyone has started,
 * its question sets, item count, items per set, shuffle and duration stay as they are (a
 * student short of time gets extra time on the Live Board instead). Nobody
 * taking it is locked out or has time taken away: the start time does not
 * move past now, a room with a student in it stays, and "End for everyone"
 * does not bring a deadline forward. Once that closing time has ended
 * someone's exam early, it does not move later either, nor is it switched
 * off: those after them would get more time. "One take only" is not turned on
 * once anyone has retaken the exam. A room whose students have results is
 * archived from the exam, not removed.
 *
 * The title, description, results, rooms added and the type stay editable.
 */
class ExamEditRules
{
    /**
     * What students have done with the exam so far, for the edit form.
     *
     * @return array<string, mixed>
     */
    public function state(Exam $exam): array
    {
        $attempts = $this->attempts($exam);
        $live = $this->live($attempts);
        $rooms = Room::query()
            ->whereIn('id', $attempts->pluck('room_id')->unique()->values())
            ->pluck('name', 'id');

        return [
            'students_started' => $attempts->pluck('user_id')->unique()->count(),
            'students_taking_now' => $live->pluck('user_id')->unique()->count(),
            'students_retaken' => $this->retakers($attempts),
            'ended_by_closing_time' => $this->endedByClosingTime($attempts)->count(),
            'rooms_with_attempts' => $attempts->pluck('room_id')->unique()->values()
                ->map(fn ($id): array => ['id' => (int) $id, 'name' => $rooms->get($id), 'taking_now' => $live->contains('room_id', $id)])
                ->all(),
        ];
    }

    /**
     * The changes an update would make that students' attempts rule out, as
     * field => reason. Empty when the update may go ahead.
     *
     * @param  array<string, mixed>  $validated
     * @param  list<int>  $bankIds  the question sets the update resolves to, in order
     * @param  list<int>|null  $roomIds  the rooms it assigns, or null when it does not touch them
     * @param  array<int, int>|null  $itemSplit  the items each set will give (ExamQuestionBanks::resolveSplit())
     * @return array<string, string>
     */
    public function problems(Exam $exam, array $validated, array $bankIds, ?array $roomIds, mixed $start, mixed $end, ?array $itemSplit = null): array
    {
        $attempts = $this->attempts($exam);

        if ($attempts->isEmpty()) {
            return [];
        }

        $problems = [];
        $started = $attempts->pluck('user_id')->unique()->count();
        $live = $this->live($attempts);
        $taking = $live->pluck('user_id')->unique()->count();
        $fixed = sprintf(
            '%s started this exam, so this is fixed: everyone has to sit the same exam. To change it, archive this exam and make a new one.',
            $this->students($started, 'has', 'have'),
        );

        if ($bankIds !== array_map('intval', $exam->resolvedQuestionBankIds())) {
            $problems['question_bank_ids'] = $fixed;
        }

        if (array_key_exists('total_items', $validated) && (int) $validated['total_items'] !== (int) $exam->total_items) {
            $problems['total_items'] = $fixed;
        }

        $currentSplit = $exam->itemSplit();

        if ($currentSplit !== null && $itemSplit !== null && $itemSplit !== $currentSplit) {
            $problems['question_bank_items'] = $fixed;
        }

        if (array_key_exists('shuffle_questions', $validated) && (bool) $validated['shuffle_questions'] !== (bool) $exam->shuffle_questions) {
            $problems['shuffle_questions'] = $fixed;
        }

        if (array_key_exists('duration_minutes', $validated) && (int) $validated['duration_minutes'] !== (int) $exam->duration_minutes) {
            $problems['duration_minutes'] = sprintf(
                '%s started this exam, so its time limit is fixed: everyone gets the same time. To give a student more, use + Time on the Live Board.',
                $this->students($started, 'has', 'have'),
            );
        }

        $retaken = $this->retakers($attempts);

        if (($validated['one_take_only'] ?? false) && !$exam->one_take_only && $retaken > 0) {
            $problems['one_take_only'] = sprintf(
                '%s already retaken this exam. Making it one take only would take the retake away from everyone else.',
                $this->students($retaken, 'has', 'have'),
            );
        }

        if ($roomIds !== null) {
            $kept = array_map('intval', $roomIds);
            $removed = $exam->rooms()->whereNull('rooms.archived_at')->pluck('rooms.name', 'rooms.id')
                ->reject(fn ($name, $id): bool => in_array((int) $id, $kept, true));

            foreach ($removed as $id => $name) {
                if ($live->contains('room_id', (int) $id)) {
                    $problems['room_ids'] = "Students in {$name} are taking this exam right now, so it stays assigned there.";
                    break;
                }

                if ($attempts->contains('room_id', (int) $id)) {
                    $problems['room_ids'] = "{$name} has results for this exam. Archive the exam from that room instead: its results stay.";
                    break;
                }
            }
        }

        if ($taking > 0) {
            $newStart = $start ? Carbon::parse($start) : null;
            $oldStart = $exam->schedule_start_at ?? $exam->scheduled_at;

            if ($newStart && $newStart->isFuture() && (!$oldStart || !$newStart->eq($oldStart))) {
                $problems['schedule_start_at'] = sprintf(
                    '%s taking this exam right now. A start time after now would lock out anyone who reloads the page.',
                    $this->students($taking, 'is', 'are'),
                );
            }
        }

        $endsAtClose = array_key_exists('ends_at_close', $validated) ? (bool) $validated['ends_at_close'] : (bool) $exam->ends_at_close;
        $newEnd = $end ? Carbon::parse($end) : null;

        // Would anyone taking it now have time taken away?
        $preview = (clone $exam)->forceFill(['ends_at_close' => $endsAtClose, 'schedule_end_at' => $newEnd]);
        $cut = $live->contains(fn (ExamAttempt $attempt): bool => $attempt->started_at && $attempt->expires_at
            && $preview->attemptDeadline($attempt->started_at, max(1, (int) $attempt->duration_minutes), (int) $attempt->extra_minutes)->lt($attempt->expires_at));

        if ($cut) {
            $field = $exam->ends_at_close && $endsAtClose ? 'schedule_end_at' : 'ends_at_close';
            $problems[$field] = sprintf(
                '%s taking this exam right now, and this would end their exam before their time is up.',
                $this->students($taking, 'is', 'are'),
            );
        }

        // Once the closing time has ended someone's exam early, it neither
        // moves later nor stops ending exams: those after would get more time.
        $endedEarly = $this->endedByClosingTime($attempts)->count();

        if ($endedEarly > 0 && $exam->ends_at_close) {
            $oldEnd = $exam->schedule_end_at;
            $later = !$endsAtClose || !$newEnd || ($oldEnd && $newEnd->gt($oldEnd));

            if ($later) {
                $problems[$endsAtClose ? 'schedule_end_at' : 'ends_at_close'] = sprintf(
                    'The end time already ended %s early. Moving it later, or letting students finish, would give everyone after them more time.',
                    $endedEarly === 1 ? "1 student's exam" : "{$endedEarly} students' exams",
                );
            }
        }

        return $problems;
    }

    /** @return Collection<int, ExamAttempt> */
    private function attempts(Exam $exam): Collection
    {
        return ExamAttempt::query()
            ->where('exam_id', $exam->id)
            ->get(['id', 'user_id', 'room_id', 'status', 'started_at', 'expires_at', 'duration_minutes', 'extra_minutes']);
    }

    /** Attempts still under way: not handed in, and their time not up. */
    private function live(Collection $attempts): Collection
    {
        return $attempts->filter(fn (ExamAttempt $attempt): bool => $attempt->status === ExamAttempt::STATUS_IN_PROGRESS
            && ($attempt->expires_at === null || $attempt->expires_at->isFuture()));
    }

    /** Students with more than one attempt, under way or handed in. */
    private function retakers(Collection $attempts): int
    {
        return $attempts->groupBy('user_id')->filter(fn (Collection $own): bool => $own->count() > 1)->count();
    }

    /**
     * Attempts whose time is up and whose deadline came before their full
     * duration: the closing time cut them short.
     */
    private function endedByClosingTime(Collection $attempts): Collection
    {
        return $attempts->filter(fn (ExamAttempt $attempt): bool => $attempt->started_at && $attempt->expires_at
            && !$attempt->expires_at->isFuture()
            && $attempt->expires_at->lt($attempt->started_at->copy()->addMinutes(max(1, (int) $attempt->duration_minutes) + (int) $attempt->extra_minutes)));
    }

    private function students(int $count, string $one, string $many): string
    {
        return $count === 1 ? "1 student {$one}" : "{$count} students {$many}";
    }
}
