<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PassingMark;
use App\Support\SchoolCalendar;
use App\Support\SystemSettings;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public const PASSING_MARK_MESSAGE = 'Enter a whole number from ' . PassingMark::MIN . ' to ' . PassingMark::MAX . '.';

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

    public function show(Request $request, SchoolCalendar $calendar, SystemSettings $systemSettings)
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

        // The months as the calendar reads them: a stored pair that is not a
        // calendar is answered as the defaults the app is actually using.
        $months = $calendar->startMonths();
        $settings['first_semester_start_month'] = $months['first'];
        $settings['second_semester_start_month'] = $months['second'];
        // Likewise the mark in use: a stored value outside 75-95 reads as 75.
        $settings['students_passing_mark'] = $systemSettings->studentsPassingMark();

        return response()->json([
            'settings' => $settings,
            'current_term' => $calendar->current(),
            'can_edit' => $this->isAdmin($user),
        ]);
    }

    public function update(Request $request, SchoolCalendar $calendar, SystemSettings $systemSettings)
    {
        $user = $request->user();

        if (!$this->isAdmin($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_semester_start_month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'second_semester_start_month' => ['sometimes', 'required', 'integer', 'between:1,12'],
            'students_passing_mark' => ['sometimes', 'required', 'integer', 'between:' . PassingMark::MIN . ',' . PassingMark::MAX],
            'allow_public_registration' => ['sometimes', 'required', 'boolean'],
            'announcement_banner' => ['sometimes', 'nullable', 'string', 'max:500'],
            'terms_of_use_text' => ['sometimes', 'required', 'string', 'max:20000'],
            'privacy_policy_text' => ['sometimes', 'required', 'string', 'max:20000'],
        ], [
            'first_semester_start_month.*' => 'Pick the month the 1st semester starts.',
            'second_semester_start_month.*' => 'Pick the month the 2nd semester starts.',
            'students_passing_mark.*' => self::PASSING_MARK_MESSAGE,
        ]);

        $markBefore = $systemSettings->studentsPassingMark();

        // The pair has to make a calendar, whichever of the two was sent.
        $monthsBefore = $calendar->startMonths();
        $monthsAfter = [
            'first' => (int) ($validated['first_semester_start_month'] ?? $monthsBefore['first']),
            'second' => (int) ($validated['second_semester_start_month'] ?? $monthsBefore['second']),
        ];

        if ($monthsAfter['first'] === $monthsAfter['second']) {
            $message = 'The 2nd semester cannot start in the same month as the 1st.';

            return response()->json([
                'message' => $message,
                'errors' => ['second_semester_start_month' => [$message]],
            ], 422);
        }

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

        // Exams created from now on are filed by the new months, so a change
        // of calendar is written down with what it was before.
        $metadata = ['keys' => array_keys($validated)];
        if ($monthsAfter !== $monthsBefore) {
            $metadata['semester_start_months'] = ['from' => $monthsBefore, 'to' => $monthsAfter];
        }

        // A new mark re-judges every score already on record, on every page,
        // so it is written down with what it was before.
        $markAfter = $systemSettings->studentsPassingMark();
        if ($markAfter !== $markBefore) {
            $metadata['students_passing_mark'] = ['from' => $markBefore, 'to' => $markAfter];
        }

        $this->recordAudit(
            $user,
            'settings.update',
            'system_settings',
            null,
            'System settings updated',
            $metadata,
            $request,
        );

        return response()->json([
            'message' => 'Settings updated',
            'current_term' => $calendar->current(),
        ]);
    }
}
