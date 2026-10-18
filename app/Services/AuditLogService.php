<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    public function record(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        // Most callers pass the request; one that does not still gets the
        // address and browser of the HTTP request it runs in. A console
        // command has no route, so it records neither.
        $request ??= app()->bound('request') && request()->route() !== null ? request() : null;

        AuditLog::create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => self::userAgentOf($request),
        ]);
    }

    public static function userAgentOf(?Request $request): ?string
    {
        $agent = trim((string) $request?->userAgent());

        return $agent === '' ? null : Str::limit($agent, 500, '');
    }
}
