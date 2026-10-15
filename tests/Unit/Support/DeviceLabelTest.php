<?php

namespace Tests\Unit\Support;

use App\Support\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DeviceLabel turns a browser's user-agent string into the short name shown
 * in "Where You're Signed In".
 */
class DeviceLabelTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function agents(): array
    {
        return [
            'Chrome on Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'Chrome on Windows'],
            'Edge is not taken for Chrome' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/129.0 Safari/537.36 Edg/129.0', 'Edge on Windows'],
            'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile Safari/604.1', 'Safari on iPhone'],
            'Chrome on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) CriOS/129.0 Mobile Safari/604.1', 'Chrome on iPhone'],
            'Samsung Internet on Android' => ['Mozilla/5.0 (Linux; Android 14) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36', 'Samsung Internet on Android'],
            'Firefox on Mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.0; rv:131.0) Gecko/20100101 Firefox/131.0', 'Firefox on Mac'],
            'Opera on Linux' => ['Mozilla/5.0 (X11; Linux x86_64) Chrome/129.0 Safari/537.36 OPR/114.0', 'Opera on Linux'],
            'Chromebook' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) Chrome/129.0 Safari/537.36', 'Chrome on Chromebook'],
            'a browser with no system' => ['Firefox/131.0', 'Firefox'],
            'a system with no browser' => ['SomeApp (iPad)', 'iPad'],
            'nothing known' => ['curl/8.0', 'Unknown device'],
            'no user agent at all' => [null, 'Unknown device'],
        ];
    }

    #[DataProvider('agents')]
    public function test_it_names_the_browser_and_system(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, DeviceLabel::from($userAgent));
    }
}
