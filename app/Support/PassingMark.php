<?php

namespace App\Support;

/**
 * The students' passing mark, and the grade teachers see in its place.
 *
 * An administrator can hold students to a higher mark than the LLE's 75 (System
 * Settings, "Students' Passing Mark", 75 to 95). Students keep their real
 * percentage and are judged against that mark. Teachers and administrators
 * work on the usual scale, where 75 is passing: they see a score converted so
 * that the students' mark lands exactly on 75. The conversion is two straight
 * lines that meet there, so 0 stays 0 and 100 stays 100:
 *
 *   below the mark:       grade = score x 75 / mark
 *   from the mark up:     grade = 75 + (score - mark) x 25 / (100 - mark)
 *
 * With the mark at 85, a score of 84% is a grade of 74.1 and 90% is 83.3. A
 * grade is 75 or more exactly when the score is at least the mark, so the two
 * sides never disagree on who passed. At 75 nothing converts.
 *
 * Every other cut-off (60 for "follow up", 85 for "strong", the score bands)
 * is written once on the teachers' scale and compared through scoreFor(), so
 * it moves with the mark. Averages are taken from the real scores and
 * converted last: the conversion bends at the mark, so an average of
 * converted grades could pass a class whose real average does not.
 *
 * Only what is shown converts. Scores are saved as they are, and the
 * Estimated Outcome model, topic mastery and question statistics read the
 * real percentages.
 */
final class PassingMark
{
    /** The teachers' scale: a grade of 75 is passing, the LLE's passing average. */
    public const STAFF_MARK = 75;

    public const DEFAULT = 75;

    public const MIN = 75;

    /**
     * Highest mark an administrator can set. Close to 100 the upper line gets
     * so steep that one question swings a grade by many points, and at 100
     * only a perfect score could pass.
     */
    public const MAX = 95;

    public readonly int $mark;

    public function __construct(int $mark = self::DEFAULT)
    {
        $this->mark = self::valid($mark) ? $mark : self::DEFAULT;
    }

    /** The mark the administrator has set (the default when none is). */
    public static function current(): self
    {
        return new self(app(SystemSettings::class)->studentsPassingMark());
    }

    public static function valid(mixed $value): bool
    {
        return is_numeric($value)
            && (float) $value === (float) (int) $value
            && (int) $value >= self::MIN
            && (int) $value <= self::MAX;
    }

    /** Whether teachers see grades: only once the mark is above their 75. */
    public function converts(): bool
    {
        return $this->mark > self::STAFF_MARK;
    }

    public function passes(?float $score): bool
    {
        return $score !== null && $score >= $this->mark;
    }

    /**
     * The score (percent right) that reaches a cut-off written on the
     * teachers' scale: scoreFor(75) is the mark itself, scoreFor(60) with an
     * 85 mark is 68.
     */
    public function scoreFor(float $cutoff): float
    {
        if ($cutoff <= self::STAFF_MARK) {
            return round($cutoff * $this->mark / self::STAFF_MARK, 4);
        }

        return round(
            $this->mark + ($cutoff - self::STAFF_MARK) * (100 - $this->mark) / (100 - self::STAFF_MARK),
            4,
        );
    }

    /** A score (percent right) as a grade on the teachers' scale. */
    public function grade(?float $score): ?float
    {
        if ($score === null) {
            return null;
        }

        $score = max(0.0, min(100.0, $score));

        $grade = $score <= $this->mark
            ? $score * self::STAFF_MARK / $this->mark
            : self::STAFF_MARK + ($score - $this->mark) * (100 - self::STAFF_MARK) / (100 - $this->mark);

        return round($grade, 2);
    }

    /**
     * For the pages: the mark, the teachers' 75, and whether teachers see
     * grades. The pages convert with the same formula (utils/passingMark.js).
     *
     * @return array{mark: int, staff_mark: int, converts: bool}
     */
    public function toArray(): array
    {
        return [
            'mark' => $this->mark,
            'staff_mark' => self::STAFF_MARK,
            'converts' => $this->converts(),
        ];
    }
}
