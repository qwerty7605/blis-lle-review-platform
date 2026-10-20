<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Services\AuthTokenCookieService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AccountController extends Controller
{
    public function __construct(
        private readonly AuthTokenCookieService $authTokenCookieService,
    ) {
    }

    /**
     * Target of the link sent to an address someone asked to move their account
     * to (POST /account/email). Opened from an inbox, so like the verification
     * link it answers with a redirect to the sign-in page, which says what
     * happened. The account is signed out everywhere: the address is what it
     * signs in with.
     */
    public function confirmEmailChange(Request $request, string $id, string $hash)
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        if (!$request->hasValidSignature()) {
            return redirect($frontend.'/login?email_changed=expired');
        }

        $user = User::find($id);
        $newEmail = (string) $request->query('email');

        // The fingerprint is of the address the link was asked for from: it stops
        // matching once that address has changed, so a link is good for one use.
        if (!$user || !$user->is_active || !hash_equals($hash, sha1($user->email)) || $newEmail === '') {
            return redirect($frontend.'/login?email_changed=invalid');
        }

        $taken = User::query()
            ->where('email', $newEmail)
            ->whereNotIn('id', User::query()->unclaimedRegistrations()->select('id'))
            ->exists();

        if ($taken) {
            return redirect($frontend.'/login?email_changed=invalid');
        }

        $oldEmail = $user->email;

        DB::transaction(function () use ($user, $newEmail): void {
            User::query()->unclaimedRegistrations()->where('email', $newEmail)->delete();

            $user->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();
            $user->tokens()->delete();
        });

        \Illuminate\Support\Facades\Notification::route('mail', $oldEmail)
            ->notify(new \App\Notifications\EmailWasChanged($newEmail));

        $this->recordAudit($user, 'account.update-email', 'user', $user->id, 'Changed their sign-in email', ['from' => $oldEmail, 'to' => $newEmail], $request);

        return redirect($frontend.'/login?email_changed=1');
    }

    public function updateProfile(Request $request)
    {
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
            $this->recordAudit($user, 'account.update-name', 'user', $user->id, 'Changed their own name', ['from' => $previous, 'to' => $user->name], $request);
        }

        return response()->json(['message' => 'Name updated.', 'user' => $this->accountPayload($request)]);
    }

    public function updatePassword(Request $request)
    {
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

        $this->recordAudit($user, 'account.update-password', 'user', $user->id, 'Changed their own password', [], $request);

        return response()->json(['message' => 'Password changed. Other devices have been signed out.']);
    }

    public function requestEmailChange(Request $request)
    {
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

        $this->recordAudit($user, 'account.request-email-change', 'user', $user->id, 'Asked to change their sign-in email', ['to' => $validated['email']], $request);

        return response()->json([
            'message' => "We sent a link to {$validated['email']}. Open it within {$minutes} minutes to finish the change.",
        ]);
    }

    /**
     * The person's own choices, kept with their account. A partial save: what is
     * not sent stays as it is: `exam_defaults`, which a teacher's New Exam form
     * starts from, and `theme` (light, dark, or follow the device), for anyone.
     */
    public function updatePreferences(Request $request)
    {
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
            'theme' => ['sometimes', Rule::in(['light', 'dark', 'system'])],
        ]);

        $user->forceFill(['preferences' => array_merge($user->preferences ?? [], $validated)])->save();

        return response()->json(['message' => 'Saved.', 'user' => $this->accountPayload($request)]);
    }

    private function accountPayload(Request $request): array
    {
        return array_merge($request->user()->fresh()->toArray(), [
            'session_idle_minutes' => $this->authTokenCookieService->idleMinutes($request->user()->currentAccessToken()),
        ]);
    }
}
