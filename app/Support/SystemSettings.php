<?php

namespace App\Support;

use App\Models\SystemSetting;
use Throwable;

/**
 * The system settings: every key with its default (DEFAULTS, read by the
 * settings endpoints), and typed reads of the ones code outside the settings
 * screen has to act on.
 *
 * Registered as a container-scoped binding (see AppServiceProvider), not a
 * static: the lookup is memoised, and memoising it on a static would carry one
 * request's answer into the next under a persistent worker.
 */
class SystemSettings
{
    /**
     * Every setting the System Settings card edits, with the value used until
     * an administrator saves one. /settings/system answers these keys only.
     */
    public const DEFAULTS = [
        'platform_name' => 'LNU LLE Platform',
        // The school calendar (SchoolCalendar): the month each semester
        // starts in. They replaced a typed "Academic Term" that nothing read.
        'first_semester_start_month' => SchoolCalendar::DEFAULT_FIRST_SEMESTER_MONTH,
        'second_semester_start_month' => SchoolCalendar::DEFAULT_SECOND_SEMESTER_MONTH,
        // The mark students are judged against; teachers see grades where it
        // is 75 (PassingMark).
        'students_passing_mark' => PassingMark::DEFAULT,
        'allow_public_registration' => true,
        'announcement_banner' => '',
        'terms_of_use_text' => self::DEFAULT_TERMS_OF_USE,
        'privacy_policy_text' => self::DEFAULT_PRIVACY_POLICY,
    ];

    /** Settings stored as '1' / '0' and answered as true / false. */
    public const BOOLEAN_KEYS = [
        'allow_public_registration',
    ];

    /** Settings stored as digits and answered as integers. */
    public const INTEGER_KEYS = [
        'first_semester_start_month',
        'second_semester_start_month',
        'students_passing_mark',
    ];

    /** What raw() reads, in one query. */
    private const MEMOISED_KEYS = [
        'announcement_banner',
        'first_semester_start_month',
        'second_semester_start_month',
        'students_passing_mark',
    ];

    public const DEFAULT_TERMS_OF_USE = <<<'TEXT'
        1. Acceptable Use
        Use the platform only for official academic review and examination activities. Sharing accounts, impersonating users, or attempting to bypass exam controls is prohibited.

        2. Account Responsibility
        You are responsible for keeping your credentials secure. Actions performed using your account are treated as your own unless officially reported as compromised.

        3. Academic Integrity
        Cheating, unauthorized collaboration, copying exam content, or distributing question material may result in account suspension and disciplinary action.

        4. Availability and Changes
        System features may be updated or temporarily unavailable for maintenance. Administrators may enforce policy updates to keep the platform compliant and secure.

        5. Enforcement
        Violations can lead to investigation, removal of access, and formal reporting to relevant academic authorities.
        TEXT;

    public const DEFAULT_PRIVACY_POLICY = <<<'TEXT'
        1. Data We Collect
        The platform stores identity details (name, student ID, email), role information, room enrollments, and exam performance records required for academic operations.

        2. How Data Is Used
        Collected data is used to authenticate users, manage rooms and exams, generate reports, and maintain audit logs for security and accountability.

        3. Data Access
        Access to personal and exam-related data is role-based. Authorized staff and administrators can access only the data needed for their responsibilities.

        4. Data Retention
        Records are retained based on institutional requirements for academic continuity, reporting, and account recovery workflows.

        5. Security and Contact
        Reasonable safeguards are applied to protect stored data. Report privacy concerns or account issues to the institution administrators for assistance.
        TEXT;

    /** @var array<string, string>|null */
    private ?array $cache = null;

    /**
     * Drop the memo after a write, so the rest of the request that saved a
     * setting reads the new value.
     */
    public function forget(): void
    {
        $this->cache = null;
    }

    public function announcementBanner(): string
    {
        return trim($this->raw('announcement_banner', ''));
    }

    /**
     * The month each semester starts in. A stored pair that is not a
     * calendar (out of range, or both the same month) reads as the default,
     * so a bad row can never stop exams being filed.
     *
     * @return array{first: int, second: int}
     */
    public function semesterStartMonths(): array
    {
        $first = $this->raw('first_semester_start_month', (string) SchoolCalendar::DEFAULT_FIRST_SEMESTER_MONTH);
        $second = $this->raw('second_semester_start_month', (string) SchoolCalendar::DEFAULT_SECOND_SEMESTER_MONTH);

        if (!SchoolCalendar::validMonths($first, $second)) {
            return ['first' => SchoolCalendar::DEFAULT_FIRST_SEMESTER_MONTH, 'second' => SchoolCalendar::DEFAULT_SECOND_SEMESTER_MONTH];
        }

        return ['first' => (int) $first, 'second' => (int) $second];
    }

    /**
     * The students' passing mark. A stored value outside 75-95 reads as 75,
     * so a bad row can only ever mean "no conversion".
     */
    public function studentsPassingMark(): int
    {
        $mark = $this->raw('students_passing_mark', (string) PassingMark::DEFAULT);

        return PassingMark::valid($mark) ? (int) $mark : PassingMark::DEFAULT;
    }

    private function raw(string $key, string $default): string
    {
        if ($this->cache === null) {
            try {
                $this->cache = SystemSetting::query()
                    ->whereIn('key', self::MEMOISED_KEYS)
                    ->pluck('value', 'key')
                    ->all();
            } catch (Throwable) {
                // Before the table exists (a fresh install mid-migration) the
                // safe answer is the defaults (no banner, the usual calendar),
                // not an error on every page.
                $this->cache = [];
            }
        }

        return (string) ($this->cache[$key] ?? $default);
    }
}
