<?php

use App\Mail\StudentReportMail;
use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\QuestionBank;
use App\Models\Room;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthTokenCookieService;
use App\Services\DashboardAnalyticsService;
use App\Services\ExamAttemptService;
use App\Services\ExamDeliveryModeService;
use App\Services\Library\DocxQuestionParser;
use App\Services\QuestionMappingService;
use App\Services\ReviewBotService;
use App\Services\ReviewBotAdminService;
use App\Services\ReportExportService;
use App\Services\RoleService;
use App\Rules\InstitutionalEmail;
use App\Support\SystemSettings;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$roleService = app(RoleService::class);
$auditLogService = app(AuditLogService::class);
$authTokenCookieService = app(AuthTokenCookieService::class);
$dashboardAnalyticsService = app(DashboardAnalyticsService::class);
$deliveryModeService = app(ExamDeliveryModeService::class);
$examAttemptService = app(ExamAttemptService::class);
$questionMappingService = app(QuestionMappingService::class);
$reportExportService = app(ReportExportService::class);
$reviewBotService = app(ReviewBotService::class);
$reviewBotAdminService = app(ReviewBotAdminService::class);

$canManageRooms = static function (User $user) use ($roleService): bool {
    return $roleService->canManageRooms($user);
};

$isAdmin = static function (User $user) use ($roleService): bool {
    return $roleService->isAdmin($user);
};

$isStaffMasterExaminer = static function (User $user) use ($roleService): bool {
    return $roleService->isStaffMasterExaminer($user);
};

$recordAudit = static function (
    ?User $actor,
    string $action,
    ?string $targetType = null,
    int|string|null $targetId = null,
    ?string $description = null,
    array $metadata = [],
    ?Request $request = null,
) use ($auditLogService): void {
    $auditLogService->record($actor, $action, $targetType, $targetId, $description, $metadata, $request);
};

$ensureActive = static function (Request $request) use ($authTokenCookieService) {
    $user = $request->user();

    if ($user && !$user->is_active) {
        $user->tokens()->delete();

        return $authTokenCookieService->clearTokenCookie(
            response()->json(['message' => 'Account is disabled.'], 403)
        );
    }

    return null;
};

$ensureTeacherReportAccess = static function (
    Request $request,
    Exam $exam,
    Room $room
) use ($ensureActive, $isStaffMasterExaminer): User|JsonResponse {
    if ($inactiveResponse = $ensureActive($request)) {
        return $inactiveResponse;
    }

    $user = $request->user();

    if (!$isStaffMasterExaminer($user)) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $exam->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    if ((int) $room->created_by !== (int) $user->id) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $isAssigned = $exam->rooms()
        ->where('rooms.id', $room->id)
        ->exists();

    if (!$isAssigned) {
        return response()->json([
            'message' => 'This exam is not assigned to the selected room.',
        ], 422);
    }

    return $user;
};

$defaultTermsOfUseText = <<<'TEXT'
1. Acceptable Use
Use the platform only for official academic review and examination activities. Sharing accounts, impersonating users, or attempting to bypass exam controls is prohibited.

2. Account Responsibility
You are responsible for keeping your credentials secure. Actions performed using your account are treated as your own unless officially reported as compromised.

3. Academic Integrity
Cheating, unauthorized collaboration, copying exam content, or distributing question material may result in account suspension and disciplinary action.

4. Availability and Changes
System features may be updated or temporarily unavailable for maintenance. Administrators may enforce policy updates to keep the platform compliant and secure.

5. Enforcement
Violations can lead to investigation, removal of access, and formal reporting to relevant academic authorities.
TEXT;

$defaultPrivacyPolicyText = <<<'TEXT'
1. Data We Collect
The platform stores identity details (name, student ID, email), role information, room enrollments, and exam performance records required for academic operations.

2. How Data Is Used
Collected data is used to authenticate users, manage rooms and exams, generate reports, and maintain audit logs for security and accountability.

3. Data Access
Access to personal and exam-related data is role-based. Authorized staff and administrators can access only the data needed for their responsibilities.

4. Data Retention
Records are retained based on institutional requirements for academic continuity, reporting, and account recovery workflows.

5. Security and Contact
Reasonable safeguards are applied to protect stored data. Report privacy concerns or account issues to the institution administrators for assistance.
TEXT;

$systemSettingDefaults = [
    'platform_name' => 'LNU LLE Platform',
    'academic_term' => 'AY 2025-2026',
    'allow_public_registration' => true,
    'maintenance_mode' => false,
    'announcement_banner' => '',
    'terms_of_use_text' => $defaultTermsOfUseText,
    'privacy_policy_text' => $defaultPrivacyPolicyText,
];

$systemSettingBooleanKeys = [
    'allow_public_registration',
    'maintenance_mode',
];

$findAccessibleQuestionBank = static function (User $user, int $bankId, bool $admin): ?QuestionBank {
    return QuestionBank::query()
        ->withCount('questions')
        ->whereKey($bankId)
        ->when(!$admin, fn ($query) => $query->where('created_by', $user->id))
        ->first();
};

$normalizeQuestionBankIds = static function (array $bankIds): array {
    return collect($bankIds)
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->unique()
        ->values()
        ->all();
};

$resolveExamQuestionBankIds = static function (array $validated, ?Exam $exam = null) use ($normalizeQuestionBankIds): array {
    if (array_key_exists('question_bank_ids', $validated)) {
        return $normalizeQuestionBankIds($validated['question_bank_ids'] ?? []);
    }

    if (array_key_exists('question_bank_id', $validated)) {
        return !is_null($validated['question_bank_id'])
            ? [(int) $validated['question_bank_id']]
            : [];
    }

    return $exam ? $exam->resolvedQuestionBankIds() : [];
};

$findAccessibleQuestionBanks = static function (User $user, array $bankIds, bool $admin) use ($normalizeQuestionBankIds) {
    $normalizedBankIds = $normalizeQuestionBankIds($bankIds);

    if ($normalizedBankIds === []) {
        return collect();
    }

    $orderLookup = array_flip($normalizedBankIds);

    return QuestionBank::query()
        ->withCount('questions')
        ->whereIn('id', $normalizedBankIds)
        ->when(!$admin, fn ($query) => $query->where('created_by', $user->id))
        ->get()
        ->sortBy(fn (QuestionBank $bank) => $orderLookup[(int) $bank->id] ?? PHP_INT_MAX)
        ->values();
};

$normalizeAnswerText = static function (?string $value): string {
    $normalized = preg_replace('/\s+/', ' ', trim((string) $value));

    return Str::lower((string) $normalized);
};

$normalizeDeliveryMode = static function (?string $mode) use ($deliveryModeService): string {
    return $deliveryModeService->normalize($mode);
};

$refreshAttemptMetrics = static function (ExamAttempt $attempt, bool $finalize = false) use ($examAttemptService): ExamAttempt {
    return $examAttemptService->refreshMetrics($attempt, $finalize);
};

$autoSubmitExpiredAttempt = static function (ExamAttempt $attempt) use ($examAttemptService): ExamAttempt {
    return $examAttemptService->autoSubmitExpiredAttempt($attempt);
};

$buildAttemptPayload = static function (ExamAttempt $attempt) use ($examAttemptService): array {
    return $examAttemptService->buildAttemptPayload($attempt);
};

Route::post('/auth/register', function (Request $request) use ($recordAudit, $authTokenCookieService) {
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

    $validated = $request->validate([
        'first_name' => ['required', 'string', 'max:255'],
        'middle_name' => ['nullable', 'string', 'max:255'],
        'last_name' => ['required', 'string', 'max:255'],
        'student_id' => ['required', 'string', 'max:32', 'regex:/^\d{7,20}$/', 'unique:users,student_id'],
        'year_level' => ['required', Rule::in(User::YEAR_LEVELS)],
        'email' => [
            'required',
            'email',
            'max:255',
            'unique:users,email',
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
        // derived the same way the lifecycle migration backfilled existing
        // rows: a second year signing up this year was admitted last year.
        'admission_year' => (int) now()->year - ($yearLevel - 1),
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
    $user->save();

    $user->sendEmailVerificationNotification();

    $recordAudit(
        $user,
        'auth.register',
        'user',
        $user->id,
        'Student account self-registered, pending email verification',
        ['email' => $user->email, 'student_id' => $user->student_id, 'role' => $user->role],
        $request,
    );

    // No token and no cookie on purpose. Login refuses unverified accounts, so
    // signing them in here would drop them into a dashboard where every
    // request fails -- worse than telling them to go and check their inbox.
    return response()->json([
        'message' => 'Account created. Check your inbox for the verification link.',
        'email' => $user->email,
    ], 201);
})->middleware('throttle:register');

Route::post('/auth/login', function (Request $request) use ($authTokenCookieService) {
    $validated = $request->validate([
        'identifier' => ['required', 'string'],
        'password' => ['required'],
    ]);

    $user = User::where('email', $validated['identifier'])
        ->orWhere('student_id', $validated['identifier'])
        ->first();

    if (!$user || !Hash::check($validated['password'], $user->password)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    if (!$user->is_active) {
        return response()->json(['message' => 'Account is disabled. Contact an administrator.'], 403);
    }

    // Reached only after the password checks out, so this reveals nothing to
    // someone who does not already hold the credentials.
    if (!$user->hasVerifiedEmail()) {
        return response()->json([
            'message' => 'Please verify your email address first. Check your inbox for the verification link.',
            'unverified' => true,
            // Safe to echo back: the password already matched, so the caller
            // owns this account. Login also accepts a student ID, and the
            // resend endpoint needs an address.
            'email' => $user->email,
        ], 403);
    }

    $token = $user->createToken('spa')->plainTextToken;

    $response = response()->json([
        'user' => $user,
    ]);

    return $authTokenCookieService->attachTokenCookie($response, $token);
})->middleware('throttle:login');

/*
 * Step one of a password reset: mail a single-use link. The reply is
 * identical whether or not the address is on file -- anything else turns
 * this endpoint into a way to test which addresses have accounts here.
 */
Route::post('/auth/forgot-password', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
    ]);

    // Demo accounts keep the password the seeder gave them, and the demo's
    // staff sit on real-domain addresses that someone else could own -- a
    // reset link there would hand them a staff login. Same reply either way.
    $isDemoAccount = User::where('email', $validated['email'])->where('is_demo', true)->exists();

    if (!$isDemoAccount) {
        Password::sendResetLink(['email' => $validated['email']]);
    }

    return response()->json([
        'message' => 'If that address has an account, a reset link is on its way.',
    ]);
})->middleware('throttle:password-reset-request');

/*
 * Step two: spend the token and set the new password.
 *
 * Every existing API token is destroyed in the same transaction. People
 * reset passwords precisely because they think somebody else got in, and
 * leaving that person's session alive would defeat the entire exercise --
 * this is the part that matters far more than how long the link lives.
 */
Route::post('/auth/reset-password', function (Request $request) use ($recordAudit) {
    $validated = $request->validate([
        'token' => ['required', 'string'],
        'email' => ['required', 'email'],
        'password' => ['required', 'confirmed', PasswordRule::defaults()],
    ]);

    $reset = null;

    $status = Password::reset($validated, function (User $user, string $password) use (&$reset): void {
        $user->password = $password;
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Sign every device out, including whoever prompted the reset.
        $user->tokens()->delete();

        $reset = $user;

        event(new PasswordReset($user));
    });

    if ($status !== Password::PASSWORD_RESET) {
        return response()->json([
            'message' => 'That reset link is invalid or has already been used. Request a new one.',
        ], 422);
    }

    $recordAudit(
        $reset,
        'auth.password_reset',
        'user',
        $reset->id,
        'Password reset through emailed link; all sessions revoked',
        ['email' => $reset->email],
        $request,
    );

    return response()->json(['message' => 'Password updated. You can sign in now.']);
})->middleware('throttle:password-reset-confirm');

/*
 * Target of the link in the verification email. Laravel's VerifyEmail
 * notification builds that link with URL::temporarySignedRoute(), so this
 * route's name must stay exactly "verification.verify" or the notification
 * cannot resolve it.
 *
 * Failures redirect instead of returning JSON: someone clicking a link in
 * their inbox arrives in a browser, where a raw 403 payload is a dead end.
 */
Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
    $frontend = rtrim((string) config('app.frontend_url'), '/');

    // Covers tampering and expiry in one check.
    if (!$request->hasValidSignature()) {
        return redirect($frontend.'/login?verified=expired');
    }

    $user = User::find($id);

    if (!$user || !hash_equals($hash, sha1($user->getEmailForVerification()))) {
        return redirect($frontend.'/login?verified=invalid');
    }

    if (!$user->hasVerifiedEmail()) {
        $user->markEmailAsVerified();
        event(new Verified($user));
    }

    return redirect($frontend.'/login?verified=1');
})->name('verification.verify');

/*
 * Resend a verification link. The reply is identical whether the address is
 * unknown, already verified, or genuinely pending -- an unauthenticated caller
 * should not be able to probe which addresses are registered here.
 */
Route::post('/email/verification-notification', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
    ]);

    $user = User::where('email', $validated['email'])->first();

    if ($user && !$user->hasVerifiedEmail()) {
        $user->sendEmailVerificationNotification();
    }

    return response()->json([
        'message' => 'If that address still needs verifying, a new link is on its way.',
    ]);
})->middleware('throttle:verification');

Route::get('/settings/public/legal', function () use ($systemSettingDefaults) {
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
            : (string) $systemSettingDefaults[$key];
    }

    $lastUpdatedAt = SystemSetting::query()
        ->whereIn('key', $legalKeys)
        ->max('updated_at');

    return response()->json([
        'settings' => $settings,
        'last_updated_at' => $lastUpdatedAt,
    ]);
});

/*
 * The two settings every signed-in user has to see, on an endpoint they can
 * actually reach: /settings/system is staff-and-admin only, so until this
 * existed an announcement written by an administrator was readable by nobody
 * but the person who typed it. Public and unauthenticated on purpose -- it
 * carries no private data, and the login screen needs the maintenance notice
 * before anyone has signed in.
 */
Route::get('/settings/public/notice', function (SystemSettings $settings) {
    return response()->json([
        'announcement_banner' => $settings->announcementBanner(),
        'maintenance_mode' => $settings->maintenanceMode(),
    ]);
});

Route::middleware('auth:sanctum')->group(function () use (
    $canManageRooms,
    $dashboardAnalyticsService,
    $ensureActive,
    $isAdmin,
    $isStaffMasterExaminer,
    $recordAudit,
    $findAccessibleQuestionBank,
    $resolveExamQuestionBankIds,
    $findAccessibleQuestionBanks,
    $normalizeAnswerText,
    $normalizeDeliveryMode,
    $refreshAttemptMetrics,
    $autoSubmitExpiredAttempt,
    $buildAttemptPayload,
    $systemSettingBooleanKeys,
    $systemSettingDefaults,
    $authTokenCookieService,
    $ensureTeacherReportAccess,
    $questionMappingService,
    $reportExportService,
    $reviewBotService,
    $reviewBotAdminService,
) {
    require __DIR__ . '/api/auth.php';
    require __DIR__ . '/api/rooms.php';
    require __DIR__ . '/api/student.php';
    require __DIR__ . '/api/library.php';
    require __DIR__ . '/api/review-bot.php';
    require __DIR__ . '/api/review-bot-admin.php';
    require __DIR__ . '/api/exams.php';
    require __DIR__ . '/api/reports.php';
    require __DIR__ . '/api/settings.php';
    require __DIR__ . '/api/admin.php';
    require __DIR__ . '/api/predictions.php';
    require __DIR__ . '/api/lle-results.php';
});
