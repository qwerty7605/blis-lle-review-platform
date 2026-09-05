<?php

use App\Models\AuditLog;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::middleware([])->prefix('/admin')->group(function () use ($ensureActive, $isAdmin, $recordAudit) {
    Route::get('/users', function (Request $request) use ($ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $actor = $request->user();
        if (!$isAdmin($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'year_level' => ['nullable', Rule::in(User::YEAR_LEVELS)],
            'archive_state' => ['nullable', Rule::in(['current', 'archived', 'all'])],
            'student_status' => ['nullable', Rule::in(User::STUDENT_STATUSES)],
            'admission_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 200);

        $users = User::query()
            ->select('id', 'name', 'email', 'student_id', 'role', 'year_level', 'student_status', 'admission_year', 'is_active', 'archived_at', 'created_at', 'updated_at')
            ->when(
                filled($validated['search'] ?? null),
                fn ($query) => $query->where(function ($inner) use ($validated) {
                    $search = trim((string) $validated['search']);

                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%");
                })
            )
            ->when(
                filled($validated['role'] ?? null),
                fn ($query) => $query->where('role', $validated['role'])
            )
            ->when(
                filled($validated['status'] ?? null),
                fn ($query) => $query->where('is_active', $validated['status'] === 'active')
            )
            ->when(
                filled($validated['year_level'] ?? null),
                fn ($query) => $query->where('year_level', (int) $validated['year_level'])
            )
            ->when(
                filled($validated['student_status'] ?? null),
                fn ($query) => $query->where('student_status', $validated['student_status'])
            )
            ->when(
                filled($validated['admission_year'] ?? null),
                fn ($query) => $query->where('admission_year', (int) $validated['admission_year'])
            )
            ->when(
                filled($validated['archive_state'] ?? null) && $validated['archive_state'] !== 'all',
                fn ($query) => $validated['archive_state'] === 'archived'
                    ? $query->whereNotNull('archived_at')
                    : $query->whereNull('archived_at')
            )
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'users' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    });

    Route::post('/users', function (Request $request) use ($ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $actor = $request->user();
        if (!$isAdmin($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'student_id' => [
                Rule::requiredIf(fn () => $request->input('role') === User::ROLE_STUDENT),
                'nullable',
                'string',
                'max:32',
                'regex:/^\d{7,20}$/',
                'unique:users,student_id',
            ],
            'role' => ['required', Rule::in(User::ROLES)],
            'year_level' => [
                // Required for someone who is actually studying; a graduate or
                // a dropout being entered retrospectively has no current level.
                Rule::requiredIf(fn () => $request->input('role') === User::ROLE_STUDENT
                    && User::isEnrolledStatus($request->input('student_status', User::STUDENT_STATUS_ENROLLED))),
                'nullable',
                'integer',
                // A range rather than the standard ladder: irregulars sit
                // beyond fourth year and have to be recordable.
                'min:' . User::YEAR_LEVEL_MIN,
                'max:' . User::YEAR_LEVEL_MAX,
            ],
            'student_status' => ['nullable', Rule::in(User::STUDENT_STATUSES)],
            'admission_year' => ['nullable', 'integer', 'min:1900', 'max:2999'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
            'archived' => ['nullable', 'boolean'],
        ]);

        $normalizedStudentId = filled($validated['student_id'] ?? null)
            ? trim((string) $validated['student_id'])
            : null;
        $normalizedYearLevel = filled($validated['year_level'] ?? null)
            ? (int) $validated['year_level']
            : null;
        $isStudent = ($validated['role'] ?? null) === User::ROLE_STUDENT;

        // An explicit status wins; otherwise the archived checkbox decides.
        // Ticking it without saying why lands in the unspecified holding state
        // rather than silently claiming the student graduated.
        $studentStatus = null;

        if ($isStudent) {
            $studentStatus = $validated['student_status']
                ?? ((bool) ($validated['archived'] ?? false)
                    ? User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED
                    : User::STUDENT_STATUS_ENROLLED);
        }

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'student_id' => $isStudent ? $normalizedStudentId : null,
            'role' => $validated['role'],
            'year_level' => $isStudent ? $normalizedYearLevel : null,
            'admission_year' => $isStudent && filled($validated['admission_year'] ?? null)
                ? (int) $validated['admission_year']
                : null,
            'is_active' => $validated['is_active'] ?? true,
            'password' => $validated['password'],
        ]);

        // Writes student_status and archived_at together so they cannot
        // disagree -- see User::applyStudentStatus().
        if ($studentStatus !== null) {
            $user->applyStudentStatus($studentStatus);
        }

        $user->save();

        $recordAudit(
            $actor,
            'admin.user.create',
            'user',
            $user->id,
            'Admin created user account',
            [
                'email' => $user->email,
                'student_id' => $user->student_id,
                'role' => $user->role,
                'year_level' => $user->year_level,
                'is_active' => $user->is_active,
                'archived_at' => $user->archived_at,
            ],
            $request,
        );

        return response()->json([
            'message' => 'User created',
            'user' => $user,
        ], 201);
    });

    Route::patch('/users/{user}', function (Request $request, User $user) use ($ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $actor = $request->user();
        if (!$isAdmin($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'student_id' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\d{7,20}$/', Rule::unique('users', 'student_id')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
            'year_level' => ['sometimes', 'nullable', 'integer', 'min:' . User::YEAR_LEVEL_MIN, 'max:' . User::YEAR_LEVEL_MAX],
            'student_status' => ['sometimes', 'nullable', Rule::in(User::STUDENT_STATUSES)],
            'admission_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2999'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'archived' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('student_id', $validated)) {
            $validated['student_id'] = filled($validated['student_id'])
                ? trim((string) $validated['student_id'])
                : null;
        }

        $resolvedRole = array_key_exists('role', $validated)
            ? $validated['role']
            : $user->role;
        $resolvedStudentId = array_key_exists('student_id', $validated)
            ? $validated['student_id']
            : $user->student_id;
        $resolvedYearLevel = array_key_exists('year_level', $validated)
            ? (is_null($validated['year_level']) ? null : (int) $validated['year_level'])
            : $user->year_level;

        if ($resolvedRole === User::ROLE_STUDENT && blank($resolvedStudentId)) {
            return response()->json([
                'message' => 'Student ID is required for student accounts.',
            ], 422);
        }

        $resolvedStatus = $validated['student_status']
            ?? $user->student_status
            ?? User::STUDENT_STATUS_ENROLLED;

        // Only an enrolled student needs a current year level. Requiring it of
        // a graduate forced staff to leave a stale level on records that had
        // moved on, which is how a graduate ends up indistinguishable from a
        // real fourth year.
        if ($resolvedRole === User::ROLE_STUDENT
            && User::isEnrolledStatus($resolvedStatus)
            && blank($resolvedYearLevel)) {
            return response()->json([
                'message' => 'Year level is required while a student is enrolled.',
            ], 422);
        }

        if ($resolvedRole !== User::ROLE_STUDENT) {
            $validated['student_id'] = null;
            $validated['year_level'] = null;
            $validated['archived'] = false;
        }

        if ((int) $actor->id === (int) $user->id) {
            if (($validated['is_active'] ?? true) === false) {
                return response()->json([
                    'message' => 'You cannot disable your own account.',
                ], 422);
            }

            if (array_key_exists('role', $validated) && $validated['role'] !== User::ROLE_ADMIN) {
                return response()->json([
                    'message' => 'You cannot remove your own admin role.',
                ], 422);
            }
        }

        $fillPayload = collect($validated)->except(['password', 'archived', 'student_status'])->all();

        if (array_key_exists('year_level', $fillPayload) && !is_null($fillPayload['year_level'])) {
            $fillPayload['year_level'] = (int) $fillPayload['year_level'];
        }

        $user->fill($fillPayload);

        // Status and archived_at are written together, and only one of the two
        // inputs may drive them. An explicit student_status is the richer
        // signal, so it wins; the archived checkbox is the fallback for callers
        // that predate the field.
        if ($resolvedRole !== User::ROLE_STUDENT) {
            $user->student_status = null;
            $user->archived_at = null;
        } elseif (array_key_exists('student_status', $validated) && filled($validated['student_status'])) {
            $user->applyStudentStatus($validated['student_status']);
        } elseif (array_key_exists('archived', $validated)) {
            $user->applyStudentStatus((bool) $validated['archived']
                ? ($user->student_status && !User::isEnrolledStatus($user->student_status)
                    // Already archived for a known reason -- keep the reason.
                    ? $user->student_status
                    : User::STUDENT_STATUS_ARCHIVED_UNSPECIFIED)
                : User::STUDENT_STATUS_ENROLLED);
        }

        if (array_key_exists('password', $validated)) {
            $user->password = $validated['password'];
        }

        $user->save();

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            $user->tokens()->delete();
        }

        $recordAudit(
            $actor,
            'admin.user.update',
            'user',
            $user->id,
            'Admin updated user account',
            [
                'role' => $user->role,
                'student_id' => $user->student_id,
                'year_level' => $user->year_level,
                'is_active' => $user->is_active,
                'archived_at' => $user->archived_at,
                'updated_fields' => array_keys($validated),
            ],
            $request,
        );

        return response()->json([
            'message' => 'User updated',
            'user' => $user,
        ]);
    });

    Route::post('/users/{user}/recover-account', function (Request $request, User $user) use ($ensureActive, $isAdmin, $recordAudit) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        $actor = $request->user();
        if (!$isAdmin($actor)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($user->role !== User::ROLE_STUDENT) {
            return response()->json([
                'message' => 'Account recovery via student ID is only available for student accounts.',
            ], 422);
        }

        if (blank($user->student_id)) {
            return response()->json([
                'message' => 'This student account has no student ID on file.',
            ], 422);
        }

        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:32', 'regex:/^\d{7,20}$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $enteredStudentId = trim((string) $validated['student_id']);
        $storedStudentId = trim((string) $user->student_id);

        if ($enteredStudentId !== $storedStudentId) {
            return response()->json([
                'message' => 'Student ID verification failed.',
            ], 422);
        }

        $oldEmail = $user->email;

        $user->email = $validated['email'];
        $user->password = $validated['password'];
        $user->save();

        // Revoke all active sessions/tokens so only the recovered credentials remain valid.
        $user->tokens()->delete();

        $recordAudit(
            $actor,
            'admin.user.recover',
            'user',
            $user->id,
            'Admin recovered student account access',
            [
                'old_email' => $oldEmail,
                'new_email' => $user->email,
                'student_id' => $user->student_id,
                'tokens_revoked' => true,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Student account recovered. Email and password were updated and active sessions were revoked.',
            'user' => $user->only(['id', 'name', 'email', 'student_id', 'role', 'is_active']),
        ]);
    });

    Route::get('/rooms', function (Request $request) use ($ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        if (!$isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rooms = Room::query()
            ->with('creator:id,name,email,role')
            ->withCount(['members', 'exams'])
            ->latest()
            ->get();

        return response()->json([
            'rooms' => $rooms,
        ]);
    });

    Route::get('/audit-logs', function (Request $request) use ($ensureActive, $isAdmin) {
        if ($inactiveResponse = $ensureActive($request)) {
            return $inactiveResponse;
        }

        if (!$isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $limit = (int) $request->query('limit', 80);
        $limit = max(1, min(200, $limit));

        $logs = AuditLog::query()
            ->with('actor:id,name,email,role')
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json([
            'logs' => $logs,
        ]);
    });
});
