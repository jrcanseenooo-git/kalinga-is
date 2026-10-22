<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Central Office aggregate-only management view. No case/client narratives are selected. */
class ManagementDashboardController extends Controller
{
    private const MINIMUM_CELL = 5;

    private function safeMetric(int $count): int|string
    {
        return $count >= self::MINIMUM_CELL ? $count : 'Suppressed';
    }

    /**
     * Neutralize spreadsheet formula prefixes before serializing user-supplied
     * geography labels into a downloadable CSV.
     */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match('/^[=+\-@]/', ltrim($value)) === 1) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120',
            'status' => 'nullable|string|max:80', 'stage' => 'nullable|string|max:80', 'risk_level' => 'nullable|in:low,moderate,high,critical',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'aftercare' => 'nullable|boolean', 'overdue' => 'nullable|boolean',
        ]);
    }

    private function base(array $filters)
    {
        $query = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at');
        foreach (['region', 'province', 'municipality'] as $field) {
            if (!empty($filters[$field])) {
                $query->where("clients.$field", $filters[$field]);
            }
        }
        if (!empty($filters['status'])) {
            $query->where('cases.status', $filters['status']);
        }
        if (!empty($filters['stage'])) {
            $query->where('cases.stage', $filters['stage']);
        }
        if (!empty($filters['from'])) {
            $query->whereDate('cases.opened_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('cases.opened_at', '<=', $filters['to']);
        }
        if (array_key_exists('aftercare', $filters) && $filters['aftercare'] !== null) {
            $method = $filters['aftercare'] ? 'whereExists' : 'whereNotExists';
            $query->{$method}(fn ($sub) => $sub->selectRaw('1')->from('case_aftercare_plans')->whereColumn('case_aftercare_plans.case_id', 'cases.id'));
        }
        if (!empty($filters['risk_level'])) {
            $query->where('cases.current_risk_level', $filters['risk_level']);
        }
        if (!empty($filters['overdue'])) {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('tasks')->whereColumn('tasks.case_id', 'cases.id')->where('tasks.status', 'open')->where('tasks.due_at', '<', now()));
        }

        return $query;
    }

    private function dataset(array $filters): array
    {
        $base = $this->base($filters);
        $ids = (clone $base)->select('cases.id');
        $grouped = fn ($query, string $label) => $query->selectRaw("COALESCE($label, 'Not recorded') as label, COUNT(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [self::MINIMUM_CELL])->orderByDesc('total')->get();

        return [
            'filters' => $filters,
            'kpis' => [
                'total_cases' => $this->safeMetric((clone $base)->count()),
                'active_cases' => $this->safeMetric((clone $base)->where('cases.status', '!=', 'CLOSED')->count()),
                'overdue_actions' => $this->safeMetric(DB::table('tasks')->whereIn('case_id', $ids)->where('status', 'open')->where('due_at', '<', now())->count()),
                'open_referrals' => $this->safeMetric(DB::table('referrals')->whereIn('case_id', $ids)->whereNotIn('status', ['COMPLETED', 'WITHDRAWN'])->count()),
                'aftercare_cases' => $this->safeMetric(DB::table('case_aftercare_plans')->whereIn('case_id', $ids)->count()),
            ],
            'by_status' => $grouped(clone $base, 'cases.status'),
            'by_stage' => $grouped(clone $base, 'cases.stage'),
            'by_lgu' => $grouped(clone $base, 'clients.municipality'),
            'by_province' => $grouped(clone $base, 'clients.province'),
            'by_region' => $grouped(clone $base, 'clients.region'),
            'referrals' => DB::table('referrals')->whereIn('case_id', $ids)->select('status as label', DB::raw('COUNT(*) as total'))->groupBy('status')->havingRaw('COUNT(*) >= ?', [self::MINIMUM_CELL])->get(),
            'generated_at' => now()->toIso8601String(),
            'suppression_rule' => 'Cells below 5 are suppressed.',
            'kpi_notice' => 'POLICY VALIDATION REQUIRED: final CEFMU indicator definitions remain owner-approved.',
        ];
    }

    /**
     * The Central Office workspace is deliberately split by management task.
     * Every section is calculated from the same aggregate-only base query; no
     * client names, case narratives, or record identifiers are selected.
     */
    private function sectionDataset(array $filters, string $section): array
    {
        $data = $this->dataset($filters);

        return match ($section) {
            'pipeline' => array_intersect_key($data, array_flip([
                'filters', 'kpis', 'by_status', 'by_stage', 'suppression_rule', 'kpi_notice', 'generated_at',
            ])),
            'geography' => array_intersect_key($data, array_flip([
                'filters', 'kpis', 'by_lgu', 'by_province', 'by_region', 'suppression_rule', 'kpi_notice', 'generated_at',
            ])),
            'continuity' => array_intersect_key($data, array_flip([
                'filters', 'kpis', 'referrals', 'suppression_rule', 'kpi_notice', 'generated_at',
            ])),
            default => $data,
        };
    }

    public function index(Request $request)
    {
        $this->authorize('management-dashboard-view');

        return Inertia::render('Management/Overview', $this->sectionDataset($this->filters($request), 'overview'));
    }

    public function section(Request $request, string $section)
    {
        $this->authorize('management-dashboard-view');
        abort_unless(in_array($section, ['pipeline', 'geography', 'continuity'], true), 404);

        return Inertia::render('Management/Section', $this->sectionDataset($this->filters($request), $section) + ['section' => $section]);
    }

    public function export(Request $request)
    {
        $this->authorize('management-report-export');
        $data = $this->dataset($this->filters($request));
        $rows = collect($data['kpis'])->map(fn ($value, $key) => ['metric' => str_replace('_', ' ', $key), 'value' => $value]);
        foreach (['by_status' => 'Status', 'by_stage' => 'Stage', 'by_lgu' => 'LGU', 'by_province' => 'Province', 'referrals' => 'Referral'] as $key => $prefix) {
            foreach ($data[$key] as $item) {
                $rows->push(['metric' => "$prefix: $item->label", 'value' => $item->total]);
            }
        }
        $contents = "Kalinga CEFMU Management Summary\nGenerated," . now()->toIso8601String() . "\nSuppression," . self::MINIMUM_CELL . "\n\nmetric,value\n" . $rows->map(fn ($row) => $this->csvCell($row['metric']) . ',' . $this->csvCell($row['value']))->implode("\n") . "\n";
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'management_report.exported', 'auditable_type' => 'management_report', 'changes' => json_encode(['filters' => array_keys(array_filter($data['filters'])), 'row_count' => $rows->count()]), 'created_at' => now(), 'updated_at' => now()]);

        return response($contents, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="kalinga-management-summary-' . now()->format('Y-m-d') . '.csv"']);
    }
}
