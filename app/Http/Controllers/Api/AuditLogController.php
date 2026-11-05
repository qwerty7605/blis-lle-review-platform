<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogBrowser;
use App\Support\AuditEvents;
use App\Support\AuditTargets;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Route;

class AuditLogController extends Controller
{
    /**
     * The audit log, newest first, filtered as the Audit page asks: a search
     * across the description, action, address and who did it; a group of
     * events; one person; one target; a time range. Read-only: no route edits
     * or deletes an entry.
     */
    public function index(Request $request, AuditLogBrowser $browser)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            ...AuditLogBrowser::filterRules(),
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            // The old name for per_page, still honoured.
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 80);
        $perPage = max(1, min(200, $perPage));

        $logs = $browser->query($validated)
            ->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        // Names for the "who" and "what" filters, which the page sets by id.
        $actorName = !empty($validated['actor_id'])
            ? User::query()->whereKey($validated['actor_id'])->value('name')
            : null;
        $targetName = null;
        if (!empty($validated['target_type']) && !empty($validated['target_id'])) {
            $probe = new AuditLog(['target_type' => $validated['target_type'], 'target_id' => $validated['target_id']]);
            $probe->id = 0;
            $targetName = AuditTargets::describe(collect([$probe]))[0]['name'] ?? null;
        }

        return response()->json([
            'logs' => $browser->present(collect($logs->items())),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
            'categories' => AuditEvents::categoryList(),
            'filter_names' => [
                'actor' => $actorName,
                'target' => $targetName,
                'target_type' => AuditTargets::typeLabel($validated['target_type'] ?? null),
            ],
        ]);

    }

    /**
     * The same filtered log as a CSV or Excel file, for keeping or handing on. The
     * export is itself an audit entry: who took a copy, and of what.
     */
    public function export(Request $request, AuditLogBrowser $browser)
    {
        if (!$this->isAdmin($request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            ...AuditLogBrowser::filterRules(),
            'tz' => ['nullable', 'timezone:all'],
            // csv (the default) streams up to 50,000 rows; xlsx opens readable in
            // Excel, with the newest 5,000.
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);

        $format = $validated['format'] ?? 'csv';
        $filters = collect($validated)->except(['tz', 'format'])->filter(fn ($value) => $value !== null && $value !== '')->all();
        $limit = $format === 'xlsx' ? AuditLogBrowser::XLSX_LIMIT : AuditLogBrowser::EXPORT_LIMIT;
        $count = min($browser->query($filters)->count(), $limit);

        $this->recordAudit($request->user(), 'audit.export', null, null, "Exported {$count} audit log entries", [
            'rows' => $count,
            'format' => $format,
            'filters' => $filters,
        ], $request);

        $timezone = $validated['tz'] ?? config('app.timezone');

        return $format === 'xlsx'
            ? $browser->exportXlsx($filters, $timezone)
            : $browser->exportCsv($filters, $timezone);

    }
}
