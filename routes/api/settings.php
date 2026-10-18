<?php

use App\Models\SystemSetting;
use App\Support\SystemSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/settings/system', function (Request $request) use (
    $canManageRooms,
    $ensureActive,
    $isAdmin,
    $systemSettingBooleanKeys,
    $systemSettingDefaults
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$canManageRooms($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $stored = SystemSetting::query()
        ->whereIn('key', array_keys($systemSettingDefaults))
        ->pluck('value', 'key')
        ->all();

    $settings = [];

    foreach ($systemSettingDefaults as $key => $defaultValue) {
        $raw = $stored[$key] ?? $defaultValue;

        if (in_array($key, $systemSettingBooleanKeys, true)) {
            $settings[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            continue;
        }

        $settings[$key] = $raw;
    }

    return response()->json([
        'settings' => $settings,
        'can_edit' => $isAdmin($user),
    ]);
});

Route::put('/settings/system', function (Request $request) use (
    $ensureActive,
    $isAdmin,
    $recordAudit,
    $systemSettingDefaults
) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isAdmin($user)) {
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
        if (!array_key_exists($key, $systemSettingDefaults)) {
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

    $recordAudit(
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
});
