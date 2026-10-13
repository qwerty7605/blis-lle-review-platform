<?php

use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

/*
 * What a signed-in person may change about their own account, from the
 * Settings page: their name and password (every role), and the address they
 * sign in with (staff and administrators). A student's student ID, year level
 * and email stay with the administrator -- they are what the school's records
 * are matched on.
 */

// The same shape /auth/me answers with, so the page can replace what it holds.
$accountPayload = static fn (Request $request): array => array_merge($request->user()->fresh()->toArray(), [
    'session_idle_minutes' => $authTokenCookieService->idleMinutes($request->user()->currentAccessToken()),
]);

Route::patch('/account/profile', function (Request $request) use ($ensureActive, $recordAudit, $accountPayload) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    $validated = $request->validate([
        'first_name' => ['required', 'string', 'max:255'],
        'middle_name' => ['nullable', 'string', 'max:255'],
        // The seeded administrator is just "Admin"; everyone else has one.
        'last_name' => [$user->role === User::ROLE_ADMIN ? 'nullable' : 'required', 'string', 'max:255'],
    ]);

    $first = Str::squish((string) $validated['first_name']);
    $middle = Str::squish((string) ($validated['middle_name'] ?? ''));
    $last = Str::squish((string) ($validated['last_name'] ?? ''));
    $previous = $user->name;

    $user->forceFill([
        'first_name' => $first,
        'middle_name' => $middle !== '' ? $middle : null,
        'last_name' => $last !== '' ? $last : null,
        'name' => trim(implode(' ', array_filter([$first, $middle, $last]))),
    ])->save();

    // Results and class lists show this name, so who changed it and from
    // what is kept.
    if ($user->name !== $previous) {
        $recordAudit($user, 'account.update-name', 'user', $user->id, 'Changed their own name', ['from' => $previous, 'to' => $user->name], $request);
    }

    return response()->json(['message' => 'Name updated.', 'user' => $accountPayload($request)]);
});

Route::put('/account/password', function (Request $request) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    $validated = $request->validate([
        'current_password' => ['required', 'string'],
        'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
    ], [
        'password.different' => 'Choose a password that is not your current one.',
    ]);

    if (!Hash::check($validated['current_password'], $user->password)) {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => ['current_password' => ['That is not your current password.']],
        ], 422);
    }

    $user->forceFill(['password' => $validated['password']])->save();

    // Anyone else holding a sign-in for this account loses it; this one stays.
    $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

    $recordAudit($user, 'account.update-password', 'user', $user->id, 'Changed their own password', [], $request);

    return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
});

Route::post('/account/email', function (Request $request) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($user->role === User::ROLE_STUDENT) {
        return response()->json(['message' => 'Ask an administrator to change a student account\'s email address.'], 403);
    }

    $validated = $request->validate([
        'email' => [
            'required',
            'email',
            'max:255',
            // A sign-up nobody verified does not hold the address.
            Rule::unique('users', 'email')->whereNotIn('id', User::query()->unclaimedRegistrations()->select('id')),
        ],
        'current_password' => ['required', 'string'],
    ], [
        'email.unique' => $request->input('email') === $user->email
            ? 'That is already your email address.'
            : 'Another account already uses that email address.',
    ]);

    if (!Hash::check($validated['current_password'], $user->password)) {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => ['current_password' => ['That is not your current password.']],
        ], 422);
    }

    $minutes = (int) config('auth.passwords.users.expire', 60);

    // The link carries the new address and a fingerprint of the current one,
    // so it works once: after the change the fingerprint no longer matches.
    $url = URL::temporarySignedRoute('account.email.confirm', now()->addMinutes($minutes), [
        'id' => $user->id,
        'hash' => sha1($user->email),
        'email' => $validated['email'],
    ]);

    Notification::route('mail', $validated['email'])->notify(new ConfirmEmailChange($url, $minutes));

    $recordAudit($user, 'account.request-email-change', 'user', $user->id, 'Asked to change their sign-in email', ['to' => $validated['email']], $request);

    return response()->json([
        'message' => "We sent a link to {$validated['email']}. Open it within {$minutes} minutes to finish the change.",
    ]);
})->middleware('throttle:verification');

/*
 * The person's own choices, kept with their account. A partial save: what is
 * not sent stays as it is. So far there is one: `exam_defaults`, which a
 * teacher's New Exam form starts from.
 */
Route::put('/account/preferences', function (Request $request) use ($ensureActive, $accountPayload) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    $validated = $request->validate([
        // Students make no exams.
        'exam_defaults' => [$user->role === User::ROLE_STUDENT ? 'prohibited' : 'sometimes', 'array'],
        // The same limits the exam itself is saved under.
        'exam_defaults.total_items' => ['required_with:exam_defaults', 'integer', 'min:1', 'max:1000'],
        'exam_defaults.duration_minutes' => ['required_with:exam_defaults', 'integer', 'min:1', 'max:600'],
        'exam_defaults.one_take_only' => ['required_with:exam_defaults', 'boolean'],
        'exam_defaults.shuffle_questions' => ['required_with:exam_defaults', 'boolean'],
        'exam_defaults.results_visibility_mode' => ['required_with:exam_defaults', Rule::in(['hidden', 'visible'])],
    ]);

    $user->forceFill(['preferences' => array_merge($user->preferences ?? [], $validated)])->save();

    return response()->json(['message' => 'Saved.', 'user' => $accountPayload($request)]);
});

/*
 * Where this account is signed in: every sign-in that still works, newest
 * use first. One that has expired or been left idle past its limit is left
 * out -- it is already signed out, whatever the table still holds.
 */
$liveSignIns = static function (Request $request) use ($authTokenCookieService) {
    $longest = (int) config('sanctum.expiration');

    return $request->user()->tokens()
        ->when($longest > 0, fn ($query) => $query->where('created_at', '>', now()->subMinutes($longest)))
        ->get()
        ->reject(fn ($token) => $authTokenCookieService->hasGoneIdle($token));
};

Route::get('/account/sessions', function (Request $request) use ($ensureActive, $liveSignIns, $authTokenCookieService) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $currentId = $request->user()->currentAccessToken()?->id;

    return response()->json([
        'sessions' => $liveSignIns($request)
            ->sortByDesc(fn ($token) => [$token->id === $currentId, $token->last_used_at ?? $token->created_at])
            ->values()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device' => DeviceLabel::from($token->user_agent),
                'ip_address' => $token->ip_address,
                'signed_in_at' => $token->created_at,
                'last_used_at' => $token->last_used_at ?? $token->created_at,
                'remembered' => $authTokenCookieService->idleMinutes($token) === null,
                'current' => $token->id === $currentId,
            ]),
    ]);
});

Route::delete('/account/sessions/{tokenId}', function (Request $request, int $tokenId) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if ($tokenId === (int) $user->currentAccessToken()?->id) {
        return response()->json(['message' => 'To sign out here, use Log out.'], 422);
    }

    // Only ever one of their own: someone else's id finds nothing.
    $removed = $user->tokens()->where('id', $tokenId)->delete();

    if ($removed === 0) {
        return response()->json(['message' => 'That sign-in has already ended.'], 404);
    }

    $recordAudit($user, 'account.sign-out-device', 'user', $user->id, 'Signed out one of their other devices', [], $request);

    return response()->json(['message' => 'That device has been signed out.']);
})->whereNumber('tokenId');

Route::post('/account/sessions/sign-out-others', function (Request $request) use ($ensureActive, $recordAudit) {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();
    $removed = $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

    $recordAudit($user, 'account.sign-out-others', 'user', $user->id, 'Signed out all of their other devices', ['signed_out' => $removed], $request);

    return response()->json([
        'message' => $removed > 0
            ? 'Every other device has been signed out.'
            : 'You are not signed in anywhere else.',
        'signed_out' => $removed,
    ]);
});
