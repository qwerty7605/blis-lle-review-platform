<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Rules\InstitutionalEmail;
use App\Services\AuthTokenCookieService;
use App\Support\SchoolCalendar;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthTokenCookieService $authTokenCookieService,
    ) {
    }

    public function register(Request $request)
    {
        $registrationSetting = SystemSetting::query()
            ->where('key', 'allow_public_registration')
            ->value('value');

        $allowPublicRegistration = is_null($registrationSetting)
            ? true
            : filter_var($registrationSetting, FILTER_VALIDATE_BOOLEAN);

        if (!$allowPublicRegistration) {
            return response()->json([
                'message' => 'Public registration is currently disabled.',
            ], 403);
        }

        // An email address or student ID is taken once somebody has verified an
        // account with it, not when somebody types it into this form. A sign-up
        // whose link was never opened is replaced by this one instead of blocking
        // it: otherwise anyone could lock a student out of registering by entering
        // that student's ID or address first.
        $unclaimed = fn () => User::query()
            ->unclaimedRegistrations()
            ->where(fn ($query) => $query
                ->where('email', (string) $request->input('email'))
                ->orWhere('student_id', trim((string) $request->input('student_id'))));

        $unclaimedIds = $unclaimed()->pluck('id')->all();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'student_id' => [
                'required',
                'string',
                'max:32',
                'regex:/^\d{7,10}$/',
                Rule::unique('users', 'student_id')->whereNotIn('id', $unclaimedIds),
            ],
            'year_level' => ['required', Rule::in(User::YEAR_LEVELS)],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNotIn('id', $unclaimedIds),
                new InstitutionalEmail((string) config('auth.registration.allowed_email_domain')),
            ],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'agreed' => ['required', 'accepted'],
        ]);

        $firstName = Str::squish((string) $validated['first_name']);
        $middleName = array_key_exists('middle_name', $validated)
            ? Str::squish((string) $validated['middle_name'])
            : '';
        $lastName = Str::squish((string) $validated['last_name']);
        $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));

        $yearLevel = (int) $validated['year_level'];

        $user = new User([
            'name' => $fullName,
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'student_id' => trim((string) $validated['student_id']),
            'year_level' => $yearLevel,
            // The cohort anchor. Nobody is asked for it at registration, so it is
            // worked out from the school year (SchoolCalendar), not the calendar
            // year: a second year signing up in January 2027 is in school year
            // 2026, so was admitted in 2025.
            'admission_year' => app(SchoolCalendar::class)->schoolYearFor(now()) - ($yearLevel - 1),
            'email' => $validated['email'],
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
            'password' => $validated['password'],
        ]);

        // Without this a self-registered student is left with a null status, which
        // reads as "--" in the staff directory and matches no student_status
        // filter -- so the people who just signed up are the ones staff cannot
        // find. Admin-provisioned accounts already default to enrolled.
        $user->applyStudentStatus(User::STUDENT_STATUS_ENROLLED);

        try {
            $replaced = DB::transaction(function () use ($unclaimed, $user): int {
                // Asked again inside the transaction: one of them may have been
                // verified since the check above, and then it stays.
                $stale = $unclaimed()->lockForUpdate()->get(['id', 'email']);

                DB::table('password_reset_tokens')->whereIn('email', $stale->pluck('email'))->delete();
                User::query()->whereIn('id', $stale->pluck('id'))->delete();

                $user->save();

                return $stale->count();
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two sign-ups for the same address or ID at the same moment.
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['That email address or student ID has just been registered. Try again.'],
            ]);
        }

        $user->sendEmailVerificationNotification();

        $this->recordAudit(
            $user,
            'auth.register',
            'user',
            $user->id,
            'Student account self-registered, pending email verification',
            ['email' => $user->email, 'student_id' => $user->student_id, 'role' => $user->role, 'replaced_unverified_signups' => $replaced],
            $request,
        );

        // No token and no cookie on purpose. Login refuses unverified accounts, so
        // signing them in here would drop them into a dashboard where every
        // request fails -- worse than telling them to go and check their inbox.
        return response()->json([
            'message' => 'Account created. Check your inbox for the verification link.',
            'email' => $user->email,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $validated['identifier'])
            ->orWhere('student_id', $validated['identifier'])
            ->first();

        // A refused sign-in is logged too: repeated failures on one account are
        // what an administrator looks for after "someone got into my account".
        // What was typed in the identifier box is kept; the password never is.
        $logFailure = function (string $reason) use ($user, $validated, $request): void {
            $this->recordAudit(
                $user,
                'auth.login_failed',
                $user ? 'user' : null,
                $user?->id,
                match ($reason) {
                    'account_disabled' => 'Sign-in refused: the account is disabled',
                    'email_not_verified' => 'Sign-in refused: the email is not verified yet',
                    default => $user ? 'Sign-in failed: wrong password' : 'Sign-in failed: no account matches',
                },
                ['identifier' => Str::limit($validated['identifier'], 120, ''), 'reason' => $reason],
                $request,
            );
        };

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            $logFailure('invalid_credentials');

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            $logFailure('account_disabled');

            return response()->json(['message' => 'Account is disabled. Contact an administrator.'], 403);
        }

        // Reached only after the password checks out, so this reveals nothing to
        // someone who does not already hold the credentials.
        if (!$user->hasVerifiedEmail()) {
            $logFailure('email_not_verified');

            return response()->json([
                'message' => 'Please verify your email address first. Check your inbox for the verification link.',
                'unverified' => true,
                // Safe to echo back: the password already matched, so the caller
                // owns this account. Login also accepts a student ID, and the
                // resend endpoint needs an address.
                'email' => $user->email,
            ], 403);
        }

        // "Remember me" decides what kind of sign-in this is; see
        // AuthTokenCookieService for what each kind means.
        $remember = (bool) ($validated['remember'] ?? false);

        $newToken = $user->createToken($this->authTokenCookieService->tokenName($remember));
        $token = $newToken->plainTextToken;

        // So Settings can tell this sign-in from the person's others.
        $newToken->accessToken->forceFill([
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'ip_address' => $request->ip(),
        ])->save();

        $this->recordAudit($user, 'auth.login', 'user', $user->id, $remember ? 'Signed in (remember me)' : 'Signed in', [
            'remember' => $remember,
        ], $request);

        $response = response()->json([
            'user' => array_merge($user->toArray(), [
                'session_idle_minutes' => $this->authTokenCookieService->idleMinutes($newToken->accessToken),
            ]),
        ]);

        return $this->authTokenCookieService->attachTokenCookie($response, $token, $remember);
    }

    public function me(Request $request)
    {
        return response()->json(array_merge($request->user()->toArray(), [
            'session_idle_minutes' => $this->authTokenCookieService->idleMinutes($request->user()->currentAccessToken()),
        ]));
    }

    public function logout(Request $request)
    {
        $this->recordAudit($request->user(), 'auth.logout', 'user', $request->user()->id, 'Signed out', [], $request);

        $request->user()->currentAccessToken()?->delete();

        return $this->authTokenCookieService->clearTokenCookie(
            response()->json(['message' => 'Logged out'])
        );
    }
}
