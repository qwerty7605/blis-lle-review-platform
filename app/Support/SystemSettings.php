<?php

namespace App\Support;

use App\Models\SystemSetting;
use Throwable;

/**
 * Typed reads for the handful of system settings that code outside the
 * settings screen has to act on.
 *
 * The settings endpoints own the full key list and its defaults; this is
 * deliberately only the one the public notice endpoint needs, so there is no
 * second copy of the legal text to keep in step.
 *
 * Registered as a container-scoped binding (see AppServiceProvider), not a
 * static: the lookup is memoised, and memoising it on a static would carry one
 * request's answer into the next under a persistent worker.
 */
class SystemSettings
{
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

    private function raw(string $key, string $default): string
    {
        if ($this->cache === null) {
            try {
                $this->cache = SystemSetting::query()
                    ->whereIn('key', ['announcement_banner'])
                    ->pluck('value', 'key')
                    ->all();
            } catch (Throwable) {
                // Before the table exists (a fresh install mid-migration) the
                // safe answer is "no banner", not an error on every page.
                $this->cache = [];
            }
        }

        return (string) ($this->cache[$key] ?? $default);
    }
}
