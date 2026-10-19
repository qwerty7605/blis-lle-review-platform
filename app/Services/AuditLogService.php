<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\QuestionBank;
use App\Models\QuestionBankQuestion;
use App\Models\Room;
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

    /**
     * Record what someone did, naming whose it was when an administrator acts
     * on a teacher's room, exam or Library file, so the teacher can be told
     * what changed and by whom. A caller that has already deleted the thing
     * passes owner_id in the metadata.
     */
    public function recordAction(
        ?User $actor,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        ?string $description = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        $ownerId = $metadata['owner_id'] ?? null;
        unset($metadata['owner_id']);

        if ($actor && $actor->role === User::ROLE_ADMIN) {
            $ownerId ??= match ($targetType) {
                'exam' => Exam::query()->whereKey($targetId)->value('created_by'),
                'room' => Room::query()->whereKey($targetId)->value('created_by'),
                'question_bank' => QuestionBank::query()->whereKey($targetId)->value('created_by'),
                'question_bank_question' => QuestionBankQuestion::query()
                    ->with('questionBank:id,created_by')
                    ->find($targetId)?->questionBank?->created_by,
                default => null,
            };

            if ($ownerId !== null && (int) $ownerId !== (int) $actor->id) {
                $ownerName = User::query()->whereKey($ownerId)->value('name');

                if ($ownerName !== null) {
                    $metadata['owner_id'] = (int) $ownerId;
                    $metadata['owner_name'] = $ownerName;
                    $description = Str::limit(trim((string) $description)." (belongs to {$ownerName})", 250, '');
                }
            }
        }

        $this->record($actor, $action, $targetType, $targetId, $description, $metadata, $request);
    }

    public static function userAgentOf(?Request $request): ?string
    {
        $agent = trim((string) $request?->userAgent());

        return $agent === '' ? null : Str::limit($agent, 500, '');
    }
}
