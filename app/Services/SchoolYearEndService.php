<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Closing a school year: the finishing class graduates and everyone else moves
 * up a year.
 *
 * Nothing here decides who that is. BLIS holds no grades or clearances, so it
 * can only propose from the year level on file -- fourth years and above to
 * graduate, the rest to move up -- and an admin confirms the list student by
 * student. A wrong "graduated" is not a harmless label: graduates are the
 * people the licensure predictor trains on (see User::STUDENT_STATUS_GRADUATED),
 * so the decision stays with someone who has the registrar's list to hand.
 *
 * apply() therefore takes exactly the students that admin confirmed, each with
 * the year level they were shown. A student whose record has moved on since --
 * already graduated, already moved up, level edited in another tab -- is
 * skipped and reported rather than changed a second time, which also makes a
 * double submission harmless.
 */
class SchoolYearEndService
{
    public const ACTION_GRADUATE = 'graduate';

    public const ACTION_ADVANCE = 'advance';

    public const ACTIONS = [
        self::ACTION_GRADUATE,
        self::ACTION_ADVANCE,
    ];

    /** The audit action written once each time the year is closed. */
    private const AUDIT_CLOSED = 'admin.year_end.apply';

    /**
     * Every student still in circulation, with what closing the year would do
     * to them by default. Changes nothing.
     *
     * @return array{
     *   summary: array{students: int, graduate: int, advance: int, none: int},
     *   last_closed: array{at: ?string, by: ?string, graduated: int, advanced: int}|null,
     *   students: list<array<string, mixed>>
     * }
     */
    public function plan(): array
    {
        $students = User::query()
            ->currentStudents()
            // Year by year, irregular levels after the standard four, and
            // students with no level on file last.
            ->orderByRaw('CASE WHEN year_level IS NULL THEN 1 ELSE 0 END')
            ->orderBy('year_level')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'student_id', 'email', 'year_level', 'admission_year'])
            ->map(function (User $student): array {
                $yearLevel = $student->year_level;
                $proposed = $this->proposedAction($yearLevel);

                return [
                    'id' => (int) $student->id,
                    'name' => $student->name,
                    'student_id' => $student->student_id,
                    'email' => $student->email,
                    'year_level' => $yearLevel,
                    'admission_year' => $student->admission_year,
                    'proposed' => $proposed,
                    'next_year_level' => $proposed === self::ACTION_ADVANCE ? $yearLevel + 1 : null,
                ];
            })
            ->values();

        return [
            'summary' => [
                'students' => $students->count(),
                'graduate' => $students->where('proposed', self::ACTION_GRADUATE)->count(),
                'advance' => $students->where('proposed', self::ACTION_ADVANCE)->count(),
                'none' => $students->whereNull('proposed')->count(),
            ],
            'last_closed' => $this->lastClosed(),
            'students' => $students->all(),
        ];
    }

    /**
     * When the year was last closed, so the screen can say so before anyone
     * confirms it again. Closed twice, a whole cohort moves up two levels, and
     * the per-student check in apply() cannot catch that: a freshly loaded
     * list is a fresh, valid request.
     *
     * @return array{at: ?string, by: ?string, graduated: int, advanced: int}|null
     */
    private function lastClosed(): ?array
    {
        $entry = AuditLog::query()
            ->with('actor:id,name')
            ->where('action', self::AUDIT_CLOSED)
            ->latest()
            ->orderByDesc('id')
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'at' => $entry->created_at?->toIso8601String(),
            'by' => $entry->actor?->name,
            'graduated' => (int) ($entry->metadata['graduated'] ?? 0),
            'advanced' => (int) ($entry->metadata['advanced'] ?? 0),
        ];
    }

    /**
     * Carry out the decisions an admin confirmed. All of it, or none of it.
     *
     * @param  list<array{id: int, action: string, year_level: int}>  $decisions
     * @return array{
     *   graduated: int,
     *   advanced: int,
     *   skipped: list<array{id: int, name: ?string, student_id: ?string, reason: string}>
     * }
     */
    public function apply(User $actor, array $decisions, ?Request $request = null): array
    {
        return DB::transaction(function () use ($actor, $decisions, $request): array {
            // Locked so that two admins closing the year at once cannot both
            // read "second year" and each move the same student up.
            $students = User::query()
                ->whereIn('id', array_column($decisions, 'id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $graduated = 0;
            $advanced = 0;
            $skipped = [];
            $auditRows = [];
            $now = now();

            foreach ($decisions as $decision) {
                $student = $students->get((int) $decision['id']);
                $action = (string) $decision['action'];
                $seenYearLevel = (int) $decision['year_level'];

                if ($reason = $this->reasonToSkip($student, $action, $seenYearLevel)) {
                    $skipped[] = [
                        'id' => (int) $decision['id'],
                        'name' => $student?->name,
                        'student_id' => $student?->student_id,
                        'reason' => $reason,
                    ];

                    continue;
                }

                if ($action === self::ACTION_GRADUATE) {
                    $student->applyStudentStatus(User::STUDENT_STATUS_GRADUATED)->save();
                    $graduated++;

                    $auditRows[] = $this->auditRow(
                        $actor,
                        'admin.year_end.graduate',
                        $student,
                        'Marked as graduated at the end of the school year',
                        ['student_id' => $student->student_id, 'year_level' => $seenYearLevel],
                        $request,
                        $now,
                    );

                    continue;
                }

                $student->year_level = $seenYearLevel + 1;
                $student->save();
                $advanced++;

                $auditRows[] = $this->auditRow(
                    $actor,
                    'admin.year_end.advance',
                    $student,
                    sprintf('Moved up from year level %d to %d at the end of the school year', $seenYearLevel, $seenYearLevel + 1),
                    ['student_id' => $student->student_id, 'from_year_level' => $seenYearLevel, 'to_year_level' => $seenYearLevel + 1],
                    $request,
                    $now,
                );
            }

            // One row per student and one for the action as a whole, so the log
            // answers both "who moved this student up" and "when was the year
            // closed". The summary goes in last: the log reads newest first,
            // which puts it above the students it covers. Inserted in bulk,
            // because a full cohort is over a thousand rows.
            $auditRows[] = [
                'actor_id' => $actor->id,
                'action' => self::AUDIT_CLOSED,
                'target_type' => 'school_year',
                'target_id' => null,
                'description' => sprintf(
                    'Closed the school year: %d graduated, %d moved up, %d skipped',
                    $graduated,
                    $advanced,
                    count($skipped),
                ),
                'metadata' => json_encode([
                    'graduated' => $graduated,
                    'advanced' => $advanced,
                    'skipped' => count($skipped),
                ]),
                'ip_address' => $request?->ip(),
                'user_agent' => AuditLogService::userAgentOf($request),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            foreach (array_chunk($auditRows, 500) as $chunk) {
                AuditLog::insert($chunk);
            }

            return [
                'graduated' => $graduated,
                'advanced' => $advanced,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * Fourth years and above are put forward to graduate; anyone below moves
     * up. A student with no year level on file gets no proposal -- there is
     * nothing to move them up from.
     */
    private function proposedAction(?int $yearLevel): ?string
    {
        if ($yearLevel === null) {
            return null;
        }

        return $yearLevel >= max(User::YEAR_LEVELS)
            ? self::ACTION_GRADUATE
            : self::ACTION_ADVANCE;
    }

    /**
     * Why a confirmed decision can no longer be carried out, or null when it
     * still can.
     */
    private function reasonToSkip(?User $student, string $action, int $seenYearLevel): ?string
    {
        if ($student === null || $student->role !== User::ROLE_STUDENT) {
            return 'This is not a student account.';
        }

        if ($student->archived_at !== null) {
            return 'This student is no longer enrolled.';
        }

        if ((int) $student->year_level !== $seenYearLevel) {
            return 'Their year level changed after this list was loaded.';
        }

        if ($action === self::ACTION_ADVANCE && $seenYearLevel >= User::YEAR_LEVEL_MAX) {
            return 'They are already at the highest year level.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function auditRow(
        User $actor,
        string $action,
        User $student,
        string $description,
        array $metadata,
        ?Request $request,
        \DateTimeInterface $at,
    ): array {
        return [
            'actor_id' => $actor->id,
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $student->id,
            'description' => $description,
            // insert() bypasses the model's array cast, so encode it here.
            'metadata' => json_encode($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => AuditLogService::userAgentOf($request),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
