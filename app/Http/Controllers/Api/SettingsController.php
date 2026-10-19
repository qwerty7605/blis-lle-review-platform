<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function legal()
    {
        $legalKeys = ['terms_of_use_text', 'privacy_policy_text'];

        $stored = SystemSetting::query()
            ->whereIn('key', $legalKeys)
            ->pluck('value', 'key')
            ->all();

        $settings = [];

        foreach ($legalKeys as $key) {
            $storedValue = $stored[$key] ?? null;
            $settings[$key] = is_string($storedValue) && trim($storedValue) !== ''
                ? $storedValue
                : (string) SystemSettings::DEFAULTS[$key];
        }

        $lastUpdatedAt = SystemSetting::query()
            ->whereIn('key', $legalKeys)
            ->max('updated_at');

        return response()->json([
            'settings' => $settings,
            'last_updated_at' => $lastUpdatedAt,
        ]);
    }

    /**
     * The announcement every signed-in user has to see, on an endpoint they can
     * actually reach: /settings/system is staff-and-admin only, so until this
     * existed an announcement written by an administrator was readable by nobody
     * but the person who typed it. Public and unauthenticated on purpose -- it
     * carries no private data.
     */
    public function notice(SystemSettings $settings)
    {
        return response()->json([
            'announcement_banner' => $settings->announcementBanner(),
        ]);
    }

    public function show(Request $request)
    {
        $user = $request->user();

        if (!$this->canManageRooms($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $stored = SystemSetting::query()
            ->whereIn('key', array_keys(SystemSettings::DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        $settings = [];

        foreach (SystemSettings::DEFAULTS as $key => $defaultValue) {
            $raw = $stored[$key] ?? $defaultValue;

            if (in_array($key, SystemSettings::BOOLEAN_KEYS, true)) {
                $settings[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
                continue;
            }

            $settings[$key] = $raw;
        }

        return response()->json([
            'settings' => $settings,
            'can_edit' => $this->isAdmin($user),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_term' => ['sometimes', 'required', 'string', 'max:255'],
            'allow_public_registration' => ['sometimes', 'required', 'boolean'],
            'announcement_banner' => ['sometimes', 'nullable', 'string', 'max:500'],
            'terms_of_use_text' => ['sometimes', 'required', 'string', 'max:20000'],
            'privacy_policy_text' => ['sometimes', 'required', 'string', 'max:20000'],
        ]);

        foreach ($validated as $key => $value) {
            if (!array_key_exists($key, SystemSettings::DEFAULTS)) {
                continue;
            }

            SystemSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    'updated_by' => $user->id,
                ],
            );
        }

        // SystemSettings memoises its read per request; drop it so anything later
        // in this request reads what was just saved.
        app(SystemSettings::class)->forget();

        $this->recordAudit(
            $user,
            'settings.update',
            'system_settings',
            null,
            'System settings updated',
            ['keys' => array_keys($validated)],
            $request,
        );

        return response()->json([
            'message' => 'Settings updated',
        ]);
    }
}
