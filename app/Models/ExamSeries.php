<?php

namespace App\Models;

use App\Support\LleTaxonomy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The six exams, one per board subject, that make up one pre-test or one
 * mock board ("2026 Pre-test", "2026 Mock Board 1"). A student has completed
 * a series once they have a submitted sitting of each of its six subject
 * exams; the latest sitting of each counts, as everywhere else.
 *
 * Shared by every teacher: the six subject exams are often written by
 * different teachers, so anyone who teaches can put an exam of theirs into
 * any series of the same type. Its creator or an administrator can rename
 * it, and delete it while it holds no exams.
 */
class ExamSeries extends Model
{
    protected $table = 'exam_series';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'created_by',
    ];

    /**
     * @return HasMany<Exam, $this>
     */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'exam_series_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return Exam::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * The board subjects that still have no exam in the series, by official name.
     *
     * @param  iterable<Exam>|null  $exams  the series' exams, if already loaded
     * @return list<string>
     */
    public function missingSubjects(?iterable $exams = null): array
    {
        $present = collect($exams ?? $this->exams()->get(['id', 'series_subject']))
            ->pluck('series_subject')
            ->filter()
            ->all();

        return array_values(array_map(
            static fn (string $key): string => (string) LleTaxonomy::subjectName($key),
            array_diff(LleTaxonomy::keys(), $present),
        ));
    }
}
