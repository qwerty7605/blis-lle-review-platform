<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\StudentHasAcademicHistoryException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF_MASTER_EXAMINER = 'staff_master_examiner';
    public const ROLE_STUDENT = 'student';
    /** The standard four-year ladder, used for self-service registration. */
    public const YEAR_LEVELS = [1, 2, 3, 4];

    /**
     * Staff may record a level outside the standard ladder. Irregular students
     * -- shifters, repeaters, anyone returning from a leave -- routinely sit
     * beyond fourth year, and a field that cannot express them forces staff to
     * either mis-file them as fourth years or leave the level blank.
     */
    public const YEAR_LEVEL_MIN = 1;
    public const YEAR_LEVEL_MAX = 8;

    /** Currently studying. The only status that leaves archived_at null. */
    public const STUDENT_STATUS_ENROLLED = 'enrolled';

    /** Completed the programme. Pairs with an lle_results row to train on. */
    public const STUDENT_STATUS_GRADUATED = 'graduated';

    /** Left without completing. Never sat the board -- censored, not a failure. */
    public const STUDENT_STATUS_DROPPED = 'dropped';

    /** Moved to another programme or institution. */
    public const STUDENT_STATUS_TRANSFERRED = 'transferred';

    /** Temporarily away and expected back. */
    public const STUDENT_STATUS_ON_LEAVE = 'on_leave';

    /**
     * Archived before the reason was recorded. Everything archived prior to
     * this field existing lands here, because guessing would be worse than
     * admitting we do not know -- a dropout filed as a graduate becomes a
     * fabricated training example.
     */
    public const STUDENT_STATUS_ARCHIVED_UNSPECIFIED = 'archived_unspecified';

    /**
     * @var list<string>
     */
    public const STUDENT_STATUSES = [
        self::STUDENT_STATUS_ENROLLED,
        self::STUDENT_STATUS_GRADUATED,
        self::STUDENT_STATUS_DROPPED,
        self::STUDENT_STATUS_TRANSFERRED,
        self::STUDENT_STATUS_ON_LEAVE,
        self::STUDENT_STATUS_ARCHIVED_UNSPECIFIED,
    ];

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_STAFF_MASTER_EXAMINER,
        self::ROLE_STUDENT,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'student_id',
        'role',
        'year_level',
        'student_status',
        'admission_year',
        'is_active',
        'archived_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Rooms created by this user.
     */
    public function createdRooms(): HasMany
    {
        return $this->hasMany(Room::class, 'created_by');
    }

    /**
     * Exams created by this user.
     */
    public function createdExams(): HasMany
    {
        return $this->hasMany(Exam::class, 'created_by');
    }

    /**
     * Question banks created by this user.
     */
    public function createdQuestionBanks(): HasMany
    {
        return $this->hasMany(QuestionBank::class, 'created_by');
    }

    /**
     * Audit logs performed by this user.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    /**
     * Rooms where this user is a member.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class)->withTimestamps();
    }

    /**
     * Rooms where this user is a member that are still active.
     *
     * The unfiltered rooms() above is a lifetime membership list, so counting
     * it makes a fourth year look like they belong to every section they have
     * ever joined. Anything showing "current sections" wants this instead --
     * it is the mirror of Room::currentStudentMembers(), which already filters
     * archived users from the other side of the same pivot.
     */
    public function currentRooms(): BelongsToMany
    {
        return $this->rooms()->whereNull('rooms.archived_at');
    }

    /**
     * Whether a status means the student is still in circulation.
     *
     * Only `enrolled` does. Everything else -- graduated, dropped, transferred,
     * on leave, or archived before anyone said why -- means they should be off
     * the rosters, which is what archived_at expresses.
     */
    public static function isEnrolledStatus(?string $status): bool
    {
        return $status === self::STUDENT_STATUS_ENROLLED;
    }

    /**
     * Set a student's lifecycle status and keep archived_at consistent with it.
     *
     * These two fields have to agree: a student marked `graduated` who is still
     * un-archived would keep appearing in the directory and on class lists, and
     * an archived student marked `enrolled` would vanish from the very list
     * meant to show them. Writing both here means no caller has to remember.
     *
     * Does not save -- the caller decides when, so this composes with other
     * changes in one write.
     */
    public function applyStudentStatus(string $status): static
    {
        if (!in_array($status, self::STUDENT_STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown student status [{$status}].");
        }

        $this->student_status = $status;

        if (self::isEnrolledStatus($status)) {
            $this->archived_at = null;
        } elseif (is_null($this->archived_at)) {
            // Preserved when already set: re-classifying an archived student
            // records why they left, not a new leaving date.
            $this->archived_at = now();
        }

        return $this;
    }

    /**
     * Limit the query to student accounts.
     */
    public function scopeStudents(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_STUDENT);
    }

    /**
     * Limit the query to current student accounts.
     */
    public function scopeCurrentStudents(Builder $query): Builder
    {
        return $query->students()->whereNull('archived_at');
    }

    /**
     * Limit the query to archived student accounts.
     */
    public function scopeArchivedStudents(Builder $query): Builder
    {
        return $query->students()->whereNotNull('archived_at');
    }

    /**
     * Exam attempts taken by this user.
     */
    public function examAttempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'user_id');
    }

    /**
     * Licensure examination outcomes recorded for this student.
     *
     * @return HasMany<LleResult, $this>
     */
    public function lleResults(): HasMany
    {
        return $this->hasMany(LleResult::class, 'user_id');
    }

    /**
     * Refuse to delete a student who has sat exams.
     *
     * The foreign keys were switched to restrictOnDelete in
     * 2026_09_06_020000, so the database already rejects this -- but it does so
     * with a raw integrity-constraint error. Catching it here turns that into
     * something a caller can act on, and states the reason: attempt history is
     * the feature half of the LLE training set and cannot be regenerated.
     * Archive the student instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->examAttempts()->exists()) {
                throw new StudentHasAcademicHistoryException(
                    "Cannot delete user {$user->id}: they have exam attempt history. "
                    . 'Archive the account instead -- attempt history is training data '
                    . 'for the LLE predictor and cannot be recovered once deleted.'
                );
            }

            if ($user->lleResults()->exists()) {
                throw new StudentHasAcademicHistoryException(
                    "Cannot delete user {$user->id}: they have a recorded licensure result."
                );
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'year_level' => 'integer',
            'archived_at' => 'datetime',
            'admission_year' => 'integer',
            'password' => 'hashed',
        ];
    }
}
