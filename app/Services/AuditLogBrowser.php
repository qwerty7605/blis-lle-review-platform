<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditEvents;
use App\Support\AuditTargets;
use App\Support\DeviceLabel;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reading the audit log: the filters the Audit page and its export share,
 * and what each entry looks like to a person (event name, who, what it was
 * done to, from which browser). Entries are only ever read here; nothing in
 * the app edits or deletes one.
 */
class AuditLogBrowser
{
    /** Rows one export may hold; narrow the filters for more. */
    public const EXPORT_LIMIT = 50000;

    /** @return array<string, array<int, mixed>> */
    public static function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', Rule::in(array_keys(AuditEvents::CATEGORIES))],
            'action' => ['nullable', 'string', 'max:120'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'target_type' => ['nullable', 'string', 'max:120'],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'outcome' => ['nullable', Rule::in(['success', 'failure'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    public function query(array $filters): Builder
    {
        $query = AuditLog::query()->with('actor:id,name,email,role');

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $query->where(function (Builder $where) use ($term) {
                Search::whereAnyContains($where, ['audit_logs.description', 'audit_logs.action', 'audit_logs.ip_address'], $term);
                $where->orWhereIn('actor_id', Search::whereAnyContains(User::query()->select('id'), ['name', 'email'], $term));
            });
        }

        if (!empty($filters['category'])) {
            $prefixes = AuditEvents::prefixesFor($filters['category']);
            $query->where(function (Builder $where) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $where->orWhereRaw('action LIKE ? ESCAPE ?', [Search::escapeLike($prefix).'%', '\\']);
                }
            });
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['outcome'])) {
            $failures = AuditEvents::failureActions();
            $filters['outcome'] === 'failure'
                ? $query->whereIn('action', $failures)
                : $query->whereNotIn('action', $failures);
        }

        if (!empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        if (!empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);

            if (!empty($filters['target_id'])) {
                $query->where('target_id', (int) $filters['target_id']);
            }
        }

        // The page sends the ends of the reader's own days as exact instants,
        // so "today" means their today and not the server's (UTC).
        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->utc());
        }

        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'])->utc());
        }

        // Entries can share a second; the id keeps pages from skipping or
        // repeating them.
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $logs): array
    {
        $targets = AuditTargets::describe($logs);

        return $logs->map(function (AuditLog $log) use ($targets) {
            $metadata = (array) ($log->metadata ?? []);
            $category = AuditEvents::category($log->action);

            return [
                'id' => $log->id,
                'created_at' => $log->created_at?->toIso8601String(),
                'action' => $log->action,
                'event' => AuditEvents::label($log->action),
                'category' => $category,
                'category_label' => $category ? AuditEvents::CATEGORIES[$category]['label'] : null,
                'outcome' => AuditEvents::outcome($log->action),
                'description' => $log->description,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                    'email' => $log->actor->email,
                    'role' => $log->actor->role,
                ] : null,
                // A failed sign-in has no actor; it says what was typed.
                'actor_hint' => $log->actor ? null : ($metadata['identifier'] ?? null),
                'target' => $targets[$log->id],
                'ip_address' => $log->ip_address,
                'device' => $log->user_agent ? DeviceLabel::from($log->user_agent) : null,
                'user_agent' => $log->user_agent,
                'metadata' => $metadata,
                // Kept for the old page and anything else reading these fields.
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'actor_id' => $log->actor_id,
            ];
        })->all();
    }

    public function exportCsv(array $filters, string $timezone): StreamedResponse
    {
        $query = $this->query($filters);
        $filename = 'blis-audit-log-'.now($timezone)->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $timezone) {
            $out = fopen('php://output', 'w');
            // Excel opens a UTF-8 CSV correctly only with this mark.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Entry ID', "Time ({$timezone})", 'Actor', 'Actor email', 'Actor role', 'Event', 'Category',
                'Outcome', 'Action code', 'Target type', 'Target', 'Target ID', 'Description', 'IP address',
                'Device', 'User agent', 'Details',
            ]);

            // Pages of the same ordered query: chunk() would drop the newest-first
            // order and the row limit.
            for ($page = 1; ($page - 1) * 1000 < self::EXPORT_LIMIT; $page++) {
                $logs = (clone $query)->forPage($page, 1000)->get();

                foreach ($this->present($logs) as $row) {
                    fputcsv($out, array_map([self::class, 'safeCell'], [
                        $row['id'],
                        $row['created_at'] ? CarbonImmutable::parse($row['created_at'])->setTimezone($timezone)->format('Y-m-d H:i:s') : '',
                        $row['actor']['name'] ?? ($row['actor_hint'] ? "({$row['actor_hint']})" : 'System'),
                        $row['actor']['email'] ?? '',
                        self::roleLabel($row['actor']['role'] ?? null),
                        $row['event'],
                        $row['category_label'] ?? '',
                        $row['outcome'],
                        $row['action'],
                        $row['target']['type_label'] ?? '',
                        $row['target']['name'] ?? '',
                        $row['target']['id'] ?? '',
                        $row['description'] ?? '',
                        $row['ip_address'] ?? '',
                        $row['device'] ?? '',
                        $row['user_agent'] ?? '',
                        $row['metadata'] ? json_encode($row['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
                    ]));
                }

                if ($logs->count() < 1000) {
                    break;
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function roleLabel(?string $role): string
    {
        return match ($role) {
            User::ROLE_ADMIN => 'Administrator',
            User::ROLE_STAFF_MASTER_EXAMINER, 'faculty' => 'Teacher',
            User::ROLE_STUDENT => 'Student',
            default => (string) $role,
        };
    }

    /**
     * A cell a spreadsheet would run as a formula (=, +, -, @ first) is
     * written as text: names and descriptions come from what people typed.
     */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
