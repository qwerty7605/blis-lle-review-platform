<?php

namespace App\Support;

use App\Models\SystemSetting;
use Throwable;

/**
 * Typed reads for the handful of system settings that code outside the
 * settings screen has to act on.
 *
 * The settings endpoints own the full key list and its defaults; this is
 * deliberately only the ones the middleware and the public notice endpoint
 * need, so there is no second copy of the legal text to keep in step.
 *
 * Registered as a container-scoped binding (see AppServiceProvider), not a
 * static: the maintenance check runs on every API call, so the lookup is
 * memoised, and memoising it on a static would carry one request's answer into
 * the next under a persistent worker -- leaving the platform stuck in (or out
 * of) maintenance until the worker recycled.
 */
class SystemSettings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    /**
     * Drop the memo after a write, so the response to the request that just
     * flipped the switch already reflects it.
     */
    public function forget(): void
    {
        $this->cache = null;
    }

    public function maintenanceMode(): bool
    {
        return filter_var($this->raw('maintenance_mode', '0'), FILTER_VALIDATE_BOOLEAN);
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
                    ->whereIn('key', ['maintenance_mode', 'announcement_banner'])
                    ->pluck('value', 'key')
                    ->all();
            } catch (Throwable) {
                // Before the table exists (a fresh install mid-migration) the
                // safe answer is "not in maintenance, no banner" -- refusing
                // every request because a settings lookup failed would be a
                // worse outage than the one the setting exists to announce.
                $this->cache = [];
            }
        }

        return (string) ($this->cache[$key] ?? $default);
    }
}
