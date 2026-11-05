<?php

namespace App\Models;

use App\Support\LleTaxonomy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Exam extends Model
{
    use HasFactory;

    public const DELIVERY_MODE_OPEN_NAVIGATION = 'open_navigation';

    /**
     * With results Visible, when a student is shown the correct answers (and
     * when Review Bot practice from the exam opens): right after each attempt,
     * or once they cannot sit the exam again (canBeRetakenBy()).
     */
    public const ANSWERS_AFTER_EACH_ATTEMPT = 'each_attempt';

    public const ANSWERS_AFTER_LAST_ATTEMPT = 'last_attempt';

    /** @var list<string> */
    public const ANSWER_TIMINGS = [
        self::ANSWERS_AFTER_EACH_ATTEMPT,
        self::ANSWERS_AFTER_LAST_ATTEMPT,
    ];

    /** Practice; the default. */
    public const TYPE_REGULAR = 'regular';

    /** Given each semester. */
    public const TYPE_BATTERY = 'battery';

    /** The LLE review: a pre-test and the mock boards. */
    public const TYPE_PRE_TEST = 'pre_test';

    public const TYPE_MOCK_BOARD = 'mock_board';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_REGULAR,
        self::TYPE_BATTERY,
        self::TYPE_PRE_TEST,
        self::TYPE_MOCK_BOARD,
    ];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_REGULAR => 'Regular mock exam',
        self::TYPE_BATTERY => 'Battery exam',
        self::TYPE_PRE_TEST => 'Pre-test',
        self::TYPE_MOCK_BOARD => 'Mock board',
    ];

    /**
     * The types that belong to an exam series, one exam per board subject,
     * and that the Estimated Outcome model is trained on and reads. Battery
     * and regular mock exams never feed it.
     *
     * @var list<string>
     */
    public const SERIES_TYPES = [
        self::TYPE_PRE_TEST,
        self::TYPE_MOCK_BOARD,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'subject',
        'exam_type',
        'exam_series_id',
        'series_subject',
        'description',
        'question_bank_id',
        'total_items',
        'duration_minutes',
        'scheduled_at',
        'schedule_start_at',
        'schedule_end_at',
        'ends_at_close',
        'delivery_mode',
        'one_take_only',
        'shuffle_questions',
        'results_visibility_mode',
        'answers_shown_after',
        'school_year',
        'semester',
        'created_by',
    ];

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'schedule_start_at' => 'datetime',
        'schedule_end_at' => 'datetime',
        'ends_at_close' => 'boolean',
        'one_take_only' => 'boolean',
        'shuffle_questions' => 'boolean',
        'school_year' => 'integer',
        'semester' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'exam_type' => self::TYPE_REGULAR,
    ];

    /** @var list<string> */
    protected $appends = ['exam_type_label'];

    public const SEMESTERS = [1, 2];

    /**
     * The school year and semester an exam is filed under. The Estimated
     * Outcome no longer reads them (it reads exam series). An exam saved
     * without them takes them from its date.
     */
    protected static function booted(): void
    {
        static::saving(function (Exam $exam): void {
            if ($exam->school_year && $exam->semester) {
                return;
            }

            $term = self::termForDate($exam->schedule_start_at ?? $exam->scheduled_at ?? $exam->created_at ?? now());
            $exam->school_year = $exam->school_year ?: $term['school_year'];
            $exam->semester = $exam->semester ?: $term['semester'];
        });
    }

    /**
     * The term a date falls in: August to December is the first semester of
     * the school year starting that August, January to July the second
     * semester of the one that started the August before.
     *
     * @return array{school_year: int, semester: int}
     */
    public static function termForDate(CarbonInterface $date): array
    {
        return $date->month >= 8
            ? ['school_year' => $date->year, 'semester' => 1]
            : ['school_year' => $date->year - 1, 'semester' => 2];
    }

    /** "1st Semester, AY 2026-2027": a school year is named by the year it starts in. */
    public static function termLabel(int $schoolYear, int $semester): string
    {
        return sprintf('%s Semester, AY %d-%d', $semester === 1 ? '1st' : '2nd', $schoolYear, $schoolYear + 1);
    }

    public static function isSeriesType(?string $type): bool
    {
        return in_array($type, self::SERIES_TYPES, true);
    }

    /** "Regular mock exam", "Pre-test", ...; null when the type was not loaded. */
    public function getExamTypeLabelAttribute(): ?string
    {
        if (!array_key_exists('exam_type', $this->attributes)) {
            return null;
        }

        return self::TYPE_LABELS[$this->attributes['exam_type']] ?? (string) $this->attributes['exam_type'];
    }

    /**
     * The pre-test or mock board this exam is one subject of.
     *
     * @return BelongsTo<ExamSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(ExamSeries::class, 'exam_series_id');
    }

    /**
     * Exam creator (staff/admin).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Source question bank for this exam.
     */
    public function questionBank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    /**
     * Question banks linked to this exam in the selected order.
     */
    public function questionBanks(): BelongsToMany
    {
        return $this->belongsToMany(QuestionBank::class, 'exam_question_bank')
            ->withPivot(['position'])
            ->withTimestamps()
            ->orderBy('exam_question_bank.position')
            ->orderBy('question_banks.id');
    }

    /**
     * Rooms where this exam is assigned.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'exam_room')
            ->withPivot(['assigned_by', 'archived_at', 'archived_by'])
            ->withTimestamps();
    }

    /**
     * Rooms where this exam is currently active.
     */
    public function activeRooms(): BelongsToMany
    {
        return $this->rooms()
            ->wherePivotNull('archived_at');
    }

    /**
     * Rooms where this exam is archived.
     */
    public function archivedRooms(): BelongsToMany
    {
        return $this->rooms()
            ->wherePivotNotNull('archived_at')
            ->withTimestamps();
    }

    /**
     * Student attempts for this exam.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Resolve the configured question banks, falling back to the legacy single-bank link.
     *
     * @return Collection<int, QuestionBank>
     */
    public function resolvedQuestionBanks(): Collection
    {
        if ($this->relationLoaded('questionBanks') && $this->questionBanks->isNotEmpty()) {
            return $this->questionBanks->values();
        }

        $linkedBanks = $this->questionBanks()->get();
        if ($linkedBanks->isNotEmpty()) {
            return $linkedBanks->values();
        }

        if (!$this->question_bank_id) {
            return collect();
        }

        $legacyBank = $this->relationLoaded('questionBank')
            ? $this->questionBank
            : $this->questionBank()->first();

        return $legacyBank ? collect([$legacyBank]) : collect();
    }

    /**
     * @return list<int>
     */
    public function resolvedQuestionBankIds(): array
    {
        return $this->resolvedQuestionBanks()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * When the exam opens -- schedule_start_at, falling back to the older
     * single-field scheduled_at that some rows still carry.
     */
    public function scheduleOpensAt(): ?CarbonInterface
    {
        return $this->schedule_start_at ?? $this->scheduled_at;
    }

    /**
     * Whether "now" sits inside the exam's schedule window.
     *
     * Either end may be absent, which means unbounded on that side.
     */
    public function isWithinScheduleWindow(): bool
    {
        $opensAt = $this->scheduleOpensAt();
        $closesAt = $this->schedule_end_at;

        if ($opensAt && now()->lt($opensAt)) {
            return false;
        }

        return !($closesAt && now()->gt($closesAt));
    }

    /**
     * When an attempt started at $startedAt must be handed in: its full
     * duration, or the closing time if the exam ends there (ends_at_close)
     * and that comes first. Extra time a teacher gave the student comes on
     * top of either, so it also runs past the closing time.
     */
    public function attemptDeadline(CarbonInterface $startedAt, int $durationMinutes, int $extraMinutes = 0): CarbonInterface
    {
        $deadline = $startedAt->copy()->addMinutes($durationMinutes);

        if ($this->ends_at_close && $this->schedule_end_at && $this->schedule_end_at->lt($deadline)) {
            $deadline = $this->schedule_end_at->copy();
        }

        return $deadline->addMinutes(max(0, $extraMinutes));
    }

    /**
     * Whether this student could still start another attempt: attempts left,
     * the end time not passed, and the exam still assigned (not archived) in
     * a room that is not archived and that they are in. The same reading as
     * ReviewBotEligibility, which does it for many exams at once.
     */
    public function canBeRetakenBy(int $userId): bool
    {
        $submitted = $this->attempts()
            ->where('user_id', $userId)
            ->where('status', ExamAttempt::STATUS_SUBMITTED)
            ->count();

        if ($submitted >= ($this->one_take_only ? 1 : 2)) {
            return false;
        }

        if ($this->schedule_end_at && now()->gt($this->schedule_end_at)) {
            return false;
        }

        return DB::table('exam_room')
            ->join('rooms', 'rooms.id', '=', 'exam_room.room_id')
            ->join('room_user', function ($join) use ($userId): void {
                $join->on('room_user.room_id', '=', 'exam_room.room_id')
                    ->where('room_user.user_id', '=', $userId);
            })
            ->where('exam_room.exam_id', $this->id)
            ->whereNull('exam_room.archived_at')
            ->whereNull('rooms.archived_at')
            ->exists();
    }

    /**
     * Whether the student who sat $attempt may see its correct answers: the
     * results are Visible, it was handed in, and either answers come after
     * each attempt or the student cannot sit the exam again.
     */
    public function answersShownFor(ExamAttempt $attempt): bool
    {
        if (($this->results_visibility_mode ?? 'hidden') !== 'visible' || $attempt->status !== ExamAttempt::STATUS_SUBMITTED) {
            return false;
        }

        return ($this->answers_shown_after ?? self::ANSWERS_AFTER_EACH_ATTEMPT) === self::ANSWERS_AFTER_EACH_ATTEMPT
            || !$this->canBeRetakenBy((int) $attempt->user_id);
    }

    /**
     * Works out again the deadline of every attempt still under way, after
     * the closing time or ends_at_close changed. Moving the closing time
     * later, or switching ends_at_close off, gives back time it had cut.
     */
    public function refreshOpenAttemptDeadlines(): void
    {
        $this->attempts()
            ->where('status', ExamAttempt::STATUS_IN_PROGRESS)
            ->whereNotNull('started_at')
            ->get()
            ->each(function (ExamAttempt $attempt): void {
                $deadline = $this->attemptDeadline(
                    $attempt->started_at,
                    max(1, (int) $attempt->duration_minutes),
                    (int) $attempt->extra_minutes,
                );

                if (!$attempt->expires_at || !$attempt->expires_at->eq($deadline)) {
                    $attempt->forceFill(['expires_at' => $deadline])->save();
                }
            });
    }

    public function hasQuestionSet(): bool
    {
        return $this->resolvedQuestionBankIds() !== [];
    }

    /**
     * Whether a student with $remainingAttempts left could actually start now.
     *
     * The three conditions are the same ones the start endpoint rejects on, in
     * the same order: having attempts left says nothing about whether the
     * window is open or a question set was ever linked. Reporting "can start"
     * off the attempt count alone hands the UI an enabled button that answers
     * 422 -- which is exactly what it used to do.
     */
    public function studentCanStartAttempt(int $remainingAttempts, bool $assignmentArchived = false): bool
    {
        return !$assignmentArchived
            && $remainingAttempts > 0
            && $this->isWithinScheduleWindow()
            && $this->hasQuestionSet();
    }

    /**
     * Persist linked question banks in the given order.
     *
     * @param  list<int>  $questionBankIds
     */
    public function syncQuestionBanks(array $questionBankIds): void
    {
        $normalizedIds = collect($questionBankIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $now = now();
        $syncPayload = $normalizedIds
            ->mapWithKeys(fn ($bankId, $index) => [$bankId => [
                'position' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]])
            ->all();

        $this->questionBanks()->sync($syncPayload);
    }

    /**
     * Summarize the exam subject from its linked question banks. Two sets
     * filed under different spellings of one board subject are one subject.
     *
     * @param  iterable<QuestionBank>  $questionBanks
     */
    public static function summarizeQuestionBankSubject(iterable $questionBanks): ?string
    {
        $subjects = collect($questionBanks)
            ->map(fn (QuestionBank $bank) => trim((string) ($bank->subject ?? '')))
            ->map(fn (string $subject) => LleTaxonomy::canonicalSubject($subject) ?? $subject)
            ->filter()
            ->unique()
            ->values();

        if ($subjects->isEmpty()) {
            return null;
        }

        if ($subjects->count() === 1) {
            return (string) $subjects->first();
        }

        return 'Multiple Subjects';
    }
}
