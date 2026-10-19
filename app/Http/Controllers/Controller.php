<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RoleService;
use Illuminate\Http\Request;

/**
 * Base for the API controllers (App\Http\Controllers\Api). Holds the checks
 * almost every endpoint makes -- which role, whose thing -- and audit
 * recording, so each controller reads as what it does.
 *
 * Whether the account is still active is checked once for every signed-in
 * request, by the `active` middleware (EnsureAccountIsActive), not here.
 */
abstract class Controller
{
    protected function isAdmin(User $user): bool
    {
        return app(RoleService::class)->isAdmin($user);
    }

    protected function canManageRooms(User $user): bool
    {
        return app(RoleService::class)->canManageRooms($user);
    }

    protected function canTeach(User $user): bool
    {
        return app(RoleService::class)->canTeach($user);
    }

    /** See RoleService::oversees(). */
    protected function oversees(User $user, int|string|null $ownerId = null): bool
    {
        return app(RoleService::class)->oversees($user, $ownerId);
    }

    /** See AuditLogService::recordAction(). */
    protected function recordAudit(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        app(AuditLogService::class)->recordAction($actor, $action, $targetType, $targetId, $description, $metadata, $request);
    }
}
