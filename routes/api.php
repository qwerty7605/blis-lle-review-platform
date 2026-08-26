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
use App\Services\ReportExportService;
use App\Services\RoleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'min:8', 'confirmed'],
        'agreed' => ['required', 'accepted'],
    ]);

    $firstName = Str::squish((string) $validated['first_name']);
    $middleName = array_key_exists('middle_name', $validated)
        ? Str::squish((string) $validated['middle_name'])
        : '';
    $lastName = Str::squish((string) $validated['last_name']);
    $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $lastName])));

    $user = User::create([
        'name' => $fullName,
        'first_name' => $firstName,
        'middle_name' => $middleName !== '' ? $middleName : null,
        'last_name' => $lastName,
        'student_id' => trim((string) $validated['student_id']),
        'year_level' => (int) $validated['year_level'],
        'email' => $validated['email'],
        'role' => User::ROLE_STUDENT,
        'is_active' => true,
        'password' => $validated['password'],
    ]);

    $token = $user->createToken('spa')->plainTextToken;

    $recordAudit(
        $user,
        'auth.register',
        'user',
        $user->id,
        'Student account self-registered',
        ['email' => $user->email, 'student_id' => $user->student_id, 'role' => $user->role],
        $request,
    );

    $response = response()->json([
        'user' => $user,
    ], 201);

    return $authTokenCookieService->attachTokenCookie($response, $token);
});

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

    $token = $user->createToken('spa')->plainTextToken;

    $response = response()->json([
        'user' => $user,
    ]);

    return $authTokenCookieService->attachTokenCookie($response, $token);
});

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
) {
    require __DIR__ . '/api/auth.php';
    require __DIR__ . '/api/rooms.php';
    require __DIR__ . '/api/student.php';
    require __DIR__ . '/api/library.php';
    require __DIR__ . '/api/review-bot.php';
    require __DIR__ . '/api/exams.php';
    require __DIR__ . '/api/reports.php';
    require __DIR__ . '/api/settings.php';
    require __DIR__ . '/api/admin.php';
    require __DIR__ . '/api/predictions.php';
});
