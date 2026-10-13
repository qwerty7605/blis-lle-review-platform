<?php

namespace App\Services\Demo;

/**
 * Who the demo accounts are, and how to recognise them.
 *
 * Every account a demo seeder creates is marked with users.is_demo, and that
 * flag is what `php artisan demo:purge` goes by. Demo students also live on a
 * reserved domain (RFC 2606 / RFC 6761) that never resolves to a real
 * mailbox, so nothing sent to them can reach a real person. The admin and
 * teachers instead sign in with easy-to-remember addresses on the real
 * domain -- which is why the flag, not the address, decides what is demo data.
 */
final class DemoData
{
    public const EMAIL_DOMAIN = 'blis.test';

    /**
     * Domains earlier versions of the seeders used, before the flag existed.
     * Still recognised so a database seeded back then cleans up the same way.
     */
    public const LEGACY_EMAIL_DOMAINS = [
        'showcase.local',
        'loadtest.local',
    ];

    /** Teachers sign in as teacher1, teacher2, teacher3. */
    public const TEACHER_EMAILS = [
        'teacher1@lnu.edu.ph',
        'teacher2@lnu.edu.ph',
        'teacher3@lnu.edu.ph',
    ];

    public const DEFAULT_ADMIN_EMAIL = '2300001@lnu.edu.ph';

    public const DEFAULT_ADMIN_PASSWORD = 'Qazwsxedc_123';

    /** Every other demo account: teachers and students. */
    public const DEFAULT_PASSWORD = 'Qwerty_321';

    /**
     * The password for every demo account except the admin. DEMO_SEED_PASSWORD
     * overrides it, so the live site does not have to use the one committed here.
     */
    public static function password(): string
    {
        return self::fromEnv('DEMO_SEED_PASSWORD', self::DEFAULT_PASSWORD);
    }

    public static function adminEmail(): string
    {
        return strtolower(self::fromEnv('DEMO_ADMIN_EMAIL', self::DEFAULT_ADMIN_EMAIL));
    }

    public static function adminPassword(): string
    {
        return self::fromEnv('DEMO_ADMIN_PASSWORD', self::DEFAULT_ADMIN_PASSWORD);
    }

    /**
     * The three teachers' addresses. DEMO_TEACHER_EMAIL replaces the first, so
     * a local database can have a sign-in that is easier to type.
     *
     * @return list<string>
     */
    public static function teacherEmails(): array
    {
        [$first, $second, $third] = self::TEACHER_EMAILS;

        return [strtolower(self::fromEnv('DEMO_TEACHER_EMAIL', $first)), $second, $third];
    }

    /**
     * An address for the first featured student in place of the blis.test one
     * they would get, when DEMO_STUDENT_EMAIL is set.
     */
    public static function studentEmail(): ?string
    {
        $email = strtolower(self::fromEnv('DEMO_STUDENT_EMAIL', ''));

        return $email !== '' ? $email : null;
    }

    /**
     * @return list<string>
     */
    public static function emailDomains(): array
    {
        return [self::EMAIL_DOMAIN, ...self::LEGACY_EMAIL_DOMAINS];
    }

    /**
     * Whether an address is on a demo-only domain -- one that cannot receive
     * mail. The demo's staff addresses are real, so they are not.
     */
    public static function isDemoEmail(?string $email): bool
    {
        $at = strrpos((string) $email, '@');

        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr((string) $email, $at + 1)), self::emailDomains(), true);
    }

    private static function fromEnv(string $key, string $default): string
    {
        $value = env($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
