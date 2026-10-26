<?php

namespace App\Http\Controllers;

use App\Services\RolePermissions;
use App\Support\AuditCatalogue;
use App\Support\AuditLog;
use App\Support\XlsxWriter;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * System Audit Logs: read-only list with filters, search and export.
 * There is no route that edits or deletes an audit entry, and PostgreSQL
 * refuses UPDATE, DELETE and TRUNCATE on the table (append-only trigger).
 */
class AuditLogController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    private const STATUSES = ['success' => 'Success', 'failure' => 'Failed', 'denied' => 'Denied'];

    public function index(Request $request)
    {
        $this->authorize('audits.view');
        $filters = $this->filters($request);
        $page = $this->query($filters)->paginate(50)->withQueryString();
        $page->getCollection()->transform(fn ($row) => $this->present($row));
        $since = now()->subDays(7);

        return Inertia::render('Admin/Audits', [
            'audits' => $page,
            'filters' => $filters,
            'options' => [
                'types' => collect(AuditCatalogue::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'modules' => AuditCatalogue::modules(),
                'actions' => AuditCatalogue::ACTIONS,
                'roles' => collect(RolePermissions::ROLES)->map(fn ($role, $value) => ['value' => $value, 'label' => $role[0]])->values(),
                'statuses' => collect(self::STATUSES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
                'users' => DB::table('users')->orderBy('name')->get(['id', 'name']),
            ],
            'summary' => [
                'today' => DB::table('audit_logs')->where('created_at', '>=', now()->startOfDay())->count(),
                'failed' => DB::table('audit_logs')->where('created_at', '>=', $since)->where('outcome', '!=', 'success')->count(),
                'security' => DB::table('audit_logs')->where('created_at', '>=', $since)->where('log_type', 'security')->count(),
                'errors' => DB::table('audit_logs')->where('created_at', '>=', $since)->where('log_type', 'system_error')->count(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $this->authorize('audits.view');
        $filters = $this->filters($request);
        $format = $request->validate(['format' => 'nullable|in:csv,xlsx'])['format'] ?? 'xlsx';
        $rows = $this->query($filters)->limit(self::EXPORT_LIMIT)->get()->map(fn ($row) => $this->present($row));
        AuditLog::record('audit.exported', 'audit_log', null, ['format' => $format, 'rows' => $rows->count(), 'filters' => array_keys(array_filter($filters))]);

        $headers = ['Log ID', 'Timestamp', 'User', 'User Role', 'Log Type', 'Module', 'Action', 'Record ID', 'Description', 'Old Value', 'New Value', 'IP Address', 'Status'];
        $values = $rows->map(fn (array $row) => [$row['log_id'], $row['timestamp'], $row['user'], $row['role'], $row['type'], $row['module'], $row['action'],
            $row['record'], $row['description'], $row['old_value'], $row['new_value'], $row['ip_address'], $row['status']]);
        $name = 'kalinga-audit-logs-' . now('Asia/Manila')->format('Y-m-d-His');

        if ($format === 'csv') {
            $cell = fn ($value) => '"' . str_replace('"', '""', preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'" . $value : (string) $value) . '"';
            $body = "\u{FEFF}" . collect([$headers])->concat($values)->map(fn ($line) => implode(',', array_map($cell, $line)))->implode("\r\n") . "\r\n";

            return response($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\"", 'Cache-Control' => 'private, no-store']);
        }

        $path = XlsxWriter::write('Audit logs', $headers, $values);

        return response()->download($path, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    /** @return array<string, string|null> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date', 'user' => 'nullable|integer', 'role' => 'nullable|string|max:40',
            'module' => 'nullable|string|max:60', 'type' => 'nullable|string|max:40', 'action' => 'nullable|string|max:20',
            'status' => 'nullable|string|max:20', 'record' => 'nullable|string|max:80', 'q' => 'nullable|string|max:120',
        ]);

        return array_merge(array_fill_keys(['from', 'to', 'user', 'role', 'module', 'type', 'action', 'status', 'record', 'q'], null), array_map(fn ($v) => $v === null ? null : (string) $v, $data));
    }

    private function query(array $f): Builder
    {
        $query = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->select(['audit_logs.*', 'users.name as user_name', 'users.role as current_role'])
            ->orderByDesc('audit_logs.id');

        // Dates are Philippine dates; stored times are UTC.
        if ($f['from']) {
            $query->where('audit_logs.created_at', '>=', Carbon::parse($f['from'], 'Asia/Manila')->startOfDay()->utc());
        }
        if ($f['to']) {
            $query->where('audit_logs.created_at', '<=', Carbon::parse($f['to'], 'Asia/Manila')->endOfDay()->utc());
        }
        foreach (['user' => 'audit_logs.user_id', 'module' => 'audit_logs.module', 'type' => 'audit_logs.log_type', 'action' => 'audit_logs.action', 'status' => 'audit_logs.outcome'] as $key => $column) {
            if ($f[$key] !== null && $f[$key] !== '') {
                $query->where($column, $f[$key]);
            }
        }
        if ($f['role']) {
            $query->where(fn ($q) => $q->where('audit_logs.user_role', $f['role'])->orWhere(fn ($q) => $q->whereNull('audit_logs.user_role')->where('users.role', $f['role'])));
        }
        if ($f['record']) {
            $this->matchId($query, $f['record']) || $query->where('audit_logs.record_ref', $this->like(), '%' . $f['record'] . '%');
        }
        if ($f['q']) {
            $term = '%' . $f['q'] . '%';
            $like = $this->like();
            $query->where(function ($q) use ($term, $f, $like) {
                $q->where('audit_logs.description', $like, $term)->orWhere('audit_logs.event', $like, $term)->orWhere('audit_logs.record_ref', $like, $term)
                    ->orWhere('users.name', $like, $term)->orWhere('audit_logs.ip_address', $like, $term);
                if (preg_match('/^AUD-?0*(\d+)$/i', trim($f['q']), $m)) {
                    $q->orWhere('audit_logs.id', (int) $m[1]);
                }
            });
        }

        return $query;
    }

    private function matchId(Builder $query, string $value): bool
    {
        if (!preg_match('/^AUD-?0*(\d+)$/i', trim($value), $m)) {
            return false;
        }
        $query->where('audit_logs.id', (int) $m[1]);

        return true;
    }

    /** Case-insensitive LIKE on both PostgreSQL and SQLite. */
    private function like(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $class = $row->log_type ? ['type' => $row->log_type, 'module' => $row->module, 'action' => $row->action, 'description' => $row->description] : AuditCatalogue::classify($row->event);
        $role = $row->user_role ?? $row->current_role;

        return [
            'id' => $row->id,
            'log_id' => AuditCatalogue::logId($row->id),
            'timestamp' => Carbon::parse($row->created_at)->setTimezone('Asia/Manila')->format('Y-m-d H:i:s'),
            'user' => $row->user_name ?? ($row->user_id ? 'Removed account' : 'System or signed-out visitor'),
            'role' => $role ? (RolePermissions::ROLES[$role][0] ?? $role) : '-',
            'type' => AuditCatalogue::TYPES[$class['type']] ?? $class['type'],
            'type_key' => $class['type'],
            'module' => $class['module'] ?? 'System',
            'action' => $class['action'] ?? 'UPDATE',
            'record' => $row->record_ref ?? ($row->auditable_id ? $row->auditable_type . '#' . $row->auditable_id : '-'),
            'description' => $class['description'] ?? $row->event,
            'event' => $row->event,
            'old_value' => $row->old_value,
            'new_value' => $row->new_value,
            'ip_address' => $row->ip_address,
            'status' => self::STATUSES[$row->outcome] ?? ucfirst(str_replace('_', ' ', (string) $row->outcome)),
            'status_key' => $row->outcome,
        ];
    }
}
