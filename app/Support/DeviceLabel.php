<?php

namespace App\Support;

/**
 * Turns a browser's User-Agent into the few words a person would use for it:
 * "Chrome on Windows". Only as exact as telling one's own devices apart
 * needs -- the order matters, because Edge and Opera also say "Chrome" and
 * Chrome also says "Safari".
 */
final class DeviceLabel
{
    private const BROWSERS = [
        'Edg' => 'Edge',
        'OPR' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'FxiOS' => 'Firefox',
        'CriOS' => 'Chrome',
        'Chrome' => 'Chrome',
        'Safari' => 'Safari',
    ];

    private const SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Windows' => 'Windows',
        'Macintosh' => 'Mac',
        'CrOS' => 'Chromebook',
        'Linux' => 'Linux',
    ];

    public static function from(?string $userAgent): string
    {
        $userAgent = (string) $userAgent;

        $browser = self::firstMatch(self::BROWSERS, $userAgent);
        $system = self::firstMatch(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => $system,
            default => 'Unknown device',
        };
    }

    /** @param array<string, string> $names */
    private static function firstMatch(array $names, string $userAgent): ?string
    {
        foreach ($names as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
