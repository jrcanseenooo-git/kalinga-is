<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\Kavat;
use App\Services\MonitoringScope;
use App\Services\RolePermissions;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    private const PUBLIC_MINIMUM = 5;

    public function __construct(private readonly CaseAccessScope $scope, private readonly RolePermissions $permissions) {}

    private function scopedCases($query, Request $request)
    {
        $user = $request->user();
        if ($this->permissions->isMonitoringRole($user)) {
            return app(MonitoringScope::class)->scopeCases($query, $user);
        }
        if ($this->permissions->canViewAggregateOnly($user)) {
            return $query;
        }

        return $this->scope->scopeCases($query, $user);
    }

    private function minimum(Request $request): int
    {
        return $this->permissions->canViewAggregateOnly($request->user()) ? self::PUBLIC_MINIMUM : 1;
    }

    /** Match the existing aggregate-only threshold, including zero-valued cells. */
    private function safeCount(Request $request, int $count): int|string
    {
        return $this->permissions->canViewAggregateOnly($request->user()) && $count < self::PUBLIC_MINIMUM
            ? 'Suppressed'
            : $count;
    }

    private function safeCounts(Request $request, array $counts): array
    {
        return array_map(fn (int $count) => $this->safeCount($request, $count), $counts);
    }

    /** Prevent formula interpretation when aggregate labels are opened in spreadsheets. */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;
        if (preg_match('/^[=+\-@]/', ltrim($value)) === 1) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function ageBandExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CASE WHEN clients.birthdate IS NULL THEN 'Not recorded' WHEN CAST((julianday('now') - julianday(clients.birthdate)) / 365.2425 AS INTEGER) <= 5 THEN '0-5' WHEN CAST((julianday('now') - julianday(clients.birthdate)) / 365.2425 AS INTEGER) <= 11 THEN '6-11' WHEN CAST((julianday('now') - julianday(clients.birthdate)) / 365.2425 AS INTEGER) <= 17 THEN '12-17' ELSE '18+' END";
        }

        return "CASE WHEN clients.birthdate IS NULL THEN 'Not recorded' WHEN EXTRACT(YEAR FROM AGE(CURRENT_DATE, clients.birthdate)) <= 5 THEN '0-5' WHEN EXTRACT(YEAR FROM AGE(CURRENT_DATE, clients.birthdate)) <= 11 THEN '6-11' WHEN EXTRACT(YEAR FROM AGE(CURRENT_DATE, clients.birthdate)) <= 17 THEN '12-17' ELSE '18+' END";
    }

    private function grouped($query, string $group, Request $request)
    {
        return $query->groupBy($group)->havingRaw('COUNT(*) >= ?', [$this->minimum($request)]);
    }

    private function rows(Request $request): array
    {
        $cases = $this->scopedCases(
            DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at'),
            $request,
        );
        $caseIds = (clone $cases)->select('cases.id');

        return [
            ['metric' => 'Total cases', 'value' => $this->safeCount($request, (clone $cases)->count())],
            ['metric' => 'Active cases', 'value' => $this->safeCount($request, (clone $cases)->whereNotIn('cases.status', ['CLOSED'])->count())],
            ['metric' => 'Open referrals', 'value' => $this->safeCount($request, DB::table('referrals')->whereIn('case_id', $caseIds)->whereNotIn('status', ['COMPLETED', 'WITHDRAWN'])->count())],
            ...(clone $cases)->selectRaw("'Cases by status: ' || cases.status as metric, count(*) as value")->groupBy('cases.status')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderBy('cases.status')->get()->map(fn ($row) => ['metric' => $row->metric, 'value' => $row->value])->all(),
            ...(clone $cases)->selectRaw("'Cases by municipality: ' || COALESCE(clients.municipality, 'Unspecified') as metric, count(*) as value")->groupBy('metric')->havingRaw('COUNT(*) >= ?', [self::PUBLIC_MINIMUM])->orderBy('metric')->get()->map(fn ($row) => ['metric' => $row->metric, 'value' => $row->value])->all(),
        ];
    }

    private function quarter(Request $request): array
    {
        $year = max(2020, min((int) now()->year, (int) $request->integer('year', now()->year)));
        $quarter = max(1, min(4, (int) $request->integer('quarter', (int) ceil(now()->month / 3))));
        $start = Carbon::create($year, (($quarter - 1) * 3) + 1, 1)->startOfDay();

        return [$year, $quarter, $start, $start->copy()->addMonths(3)->subSecond()];
    }

    private function quarterlyDataset(Request $request): array
    {
        [$year, $quarter, $start, $end] = $this->quarter($request);
        $cases = $this->scopedCases(
            DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')
                ->whereNull('cases.deleted_at')->whereBetween('cases.opened_at', [$start, $end]),
            $request,
        );
        $caseIds = (clone $cases)->select('cases.id');

        return [
            'period' => ['year' => $year, 'quarter' => $quarter, 'label' => "Q{$quarter} {$year}", 'start' => $start->toDateString(), 'end' => $end->toDateString()],
            'canExport' => $request->user()->can('analytics.export'),
            'totals' => $this->safeCounts($request, [
                'newCases' => (clone $cases)->count(),
                'closedCases' => (clone $cases)->where('cases.status', 'CLOSED')->count(),
                'reportsReceived' => DB::table('reports')->whereIn('client_id', (clone $cases)->select('clients.id'))->whereBetween('submitted_at', [$start, $end])->count(),
                'referralsCompleted' => DB::table('referrals')->whereIn('case_id', $caseIds)->whereBetween('completed_at', [$start, $end])->count(),
            ]),
            'classification' => (clone $cases)->selectRaw("COALESCE(cases.category, 'Not classified') as label, COUNT(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'status' => (clone $cases)->select('cases.status as label', DB::raw('COUNT(*) as total'))->groupBy('cases.status')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'sex' => (clone $cases)->selectRaw("COALESCE(clients.sex, 'Not recorded') as label, COUNT(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'services' => $this->scopedCases(DB::table('interventions')->join('case_plans', 'case_plans.id', '=', 'interventions.case_plan_id')->join('cases', 'cases.id', '=', 'case_plans.case_id')->join('clients', 'clients.id', '=', 'cases.client_id')
                ->whereNull('cases.deleted_at')->whereBetween('interventions.created_at', [$start, $end]), $request)
                ->selectRaw("COALESCE(interventions.service_category, 'Other') as label, COUNT(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'areas' => (clone $cases)->selectRaw("COALESCE(clients.municipality, 'Unspecified') as area, COUNT(*) as total")
                ->groupBy('area')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
        ];
    }

    /**
     * Area and period chosen on the analytics map. The area narrows by the
     * child's recorded address (region > province > municipality > barangay);
     * the period narrows by the case opening date.
     *
     * @return array{region: string, province: string, municipality: string, barangay: string, year: ?int, quarter: ?int}
     */
    private function analyticsFilters(Request $request): array
    {
        $data = $request->validate([
            'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120', 'barangay' => 'nullable|string|max:120',
            'year' => 'nullable|integer|min:2020|max:' . now()->year, 'quarter' => 'nullable|integer|min:1|max:4',
        ]);
        $value = fn (string $key) => trim((string) ($data[$key] ?? ''));

        return ['region' => $value('region'), 'province' => $value('region') !== '' ? $value('province') : '', 'municipality' => $value('region') !== '' ? $value('municipality') : '',
            'barangay' => $value('municipality') !== '' ? $value('barangay') : '', 'year' => isset($data['year']) ? (int) $data['year'] : null,
            'quarter' => isset($data['year'], $data['quarter']) ? (int) $data['quarter'] : null];
    }

    private function applyArea($query, array $filters)
    {
        foreach (['region', 'province', 'municipality', 'barangay'] as $level) {
            if ($filters[$level] !== '') {
                $query->where("clients.{$level}", $filters[$level]);
            }
        }

        return $query;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function periodRange(array $filters): array
    {
        if (!$filters['year']) {
            return [null, null];
        }
        $start = $filters['quarter'] ? Carbon::create($filters['year'], ($filters['quarter'] - 1) * 3 + 1, 1, 0, 0, 0, 'Asia/Manila') : Carbon::create($filters['year'], 1, 1, 0, 0, 0, 'Asia/Manila');
        $end = $start->copy()->addMonths($filters['quarter'] ? 3 : 12)->subSecond();

        return [$start->utc(), $end->utc()];
    }

    public function index(Request $request)
    {
        $this->authorize('view-analytics');
        $filters = $this->analyticsFilters($request);
        [$from, $to] = $this->periodRange($filters);
        $area = $this->applyArea($this->scopedCases(DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at'), $request), $filters);
        $base = (clone $area)->when($from, fn ($q) => $q->whereBetween('cases.opened_at', [$from, $to]));
        $caseIds = (clone $base)->select('cases.id');
        $min = $this->minimum($request);
        $trend = collect(range(5, 0))->map(function ($offset) use ($base, $request) {
            $month = now()->startOfMonth()->subMonths($offset);

            return ['label' => $month->format('M Y'), 'total' => $this->safeCount($request, (clone $base)->whereBetween('cases.opened_at', [$month, $month->copy()->endOfMonth()])->count())];
        });
        // Trend lines follow the area (not the period): 12 months of new and closed cases.
        $monthly = collect(range(11, 0))->map(function ($offset) use ($area, $request) {
            $month = now('Asia/Manila')->startOfMonth()->subMonths($offset);
            [$start, $end] = [$month->copy()->utc(), $month->copy()->addMonth()->utc()];

            return ['label' => $month->format('M Y'), 'opened' => $this->safeCount($request, (clone $area)->where('cases.opened_at', '>=', $start)->where('cases.opened_at', '<', $end)->count()),
                'closed' => $this->safeCount($request, (clone $area)->where('cases.closed_at', '>=', $start)->where('cases.closed_at', '<', $end)->count())];
        });
        $quarters = collect(range(7, 0))->map(function ($offset) use ($area, $request) {
            $start = now('Asia/Manila')->firstOfQuarter()->subQuarters($offset);

            return ['label' => 'Q' . $start->quarter . ' ' . $start->year, 'total' => $this->safeCount($request, (clone $area)->where('cases.opened_at', '>=', $start->copy()->utc())->where('cases.opened_at', '<', $start->copy()->addQuarter()->utc())->count())];
        });
        // The map shows the next level down inside the selected area.
        $next = $filters['region'] === '' ? 'region' : ($filters['province'] === '' && $filters['municipality'] === '' ? 'province' : ($filters['municipality'] === '' ? 'municipality' : 'barangay'));
        // A region without provinces (NCR): the next level after the region is the city or municipality.
        if ($next === 'province' && !(clone $base)->whereNotNull('clients.province')->where('clients.province', '!=', '')->exists()
            && (clone $base)->whereNotNull('clients.municipality')->where('clients.municipality', '!=', '')->exists()) {
            $next = 'municipality';
        }
        $children = (clone $base)->selectRaw("COALESCE(clients.{$next}, 'Not recorded') as name, COUNT(*) as total, SUM(CASE WHEN cases.status != 'CLOSED' THEN 1 ELSE 0 END) as active")
            ->groupBy('name')->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('total')->get()->map(fn ($row) => ['name' => $row->name, 'total' => (int) $row->total, 'active' => (int) $row->active]);
        $latestKavat = DB::table('kavat_assessments as k')->whereIn('k.case_id', $caseIds)->where('k.status', 'final')
            ->whereRaw('k.id = (select max(k2.id) from kavat_assessments k2 where k2.case_id = k.case_id and k2.status = ?)', ['final']);
        $grants = DB::table('case_grants')->whereIn('case_id', $caseIds)->whereNull('voided_at');

        return Inertia::render('Analytics/Index', [
            'canExport' => $request->user()->can('analytics.export'),
            'canQuarterly' => $request->user()->can('analytics.quarterly'),
            'filters' => $filters,
            'level' => $next,
            'children' => $children,
            'status' => (clone $base)->select('cases.status', DB::raw('count(*) as total'))->groupBy('cases.status')->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('total')->get(),
            'geo' => (clone $base)->selectRaw("COALESCE(clients.municipality, 'Unspecified') as area, count(*) as total")->groupBy('area')->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('total')->get(),
            'referrals' => DB::table('referrals')->whereIn('case_id', $caseIds)->select('status', DB::raw('count(*) as total'))->groupBy('status')->havingRaw('COUNT(*) >= ?', [$min])->get(),
            'trend' => $trend,
            'monthly' => $monthly,
            'quarters' => $quarters,
            'sex' => (clone $base)->selectRaw("COALESCE(clients.sex, 'Not recorded') as label, count(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('total')->get(),
            'classification' => $this->classificationCounts(clone $base, $min),
            'age' => (clone $base)->selectRaw($this->ageBandExpression() . ' as label, count(*) as total')->groupBy('label')->havingRaw('COUNT(*) >= ?', [$min])->get(),
            'services' => DB::table('services_provided')->whereIn('case_id', $caseIds)->select('service_type as label', DB::raw('count(*) as total'))->groupBy('service_type')->havingRaw('COUNT(*) >= ?', [$min])->orderByDesc('total')->limit(8)->get(),
            'kavatPriority' => (clone $latestKavat)->select('k.priority as label', DB::raw('count(*) as total'))->groupBy('k.priority')->havingRaw('COUNT(*) >= ?', [$min])->get()
                ->map(fn ($row) => ['label' => Kavat::priorityLabel($row->label), 'total' => (int) $row->total]),
            'totals' => $this->safeCounts($request, ['cases' => (clone $base)->count(), 'clients' => (clone $base)->distinct('clients.id')->count('clients.id'), 'openReferrals' => DB::table('referrals')->whereIn('case_id', $caseIds)->whereNotIn('status', ['COMPLETED', 'WITHDRAWN'])->count(),
                'active' => (clone $base)->where('cases.status', '!=', 'CLOSED')->count(), 'closed' => (clone $base)->where('cases.status', 'CLOSED')->count(),
                'highPriority' => (clone $latestKavat)->where('k.priority', 'high')->count(), 'grantsCount' => (clone $grants)->count()]),
            // Money is not a count of people; it is shown unless there are too few cases to show safely.
            'grantsTotal' => $this->permissions->canViewAggregateOnly($request->user()) && (clone $base)->count() < self::PUBLIC_MINIMUM ? 'Suppressed' : round((float) (clone $grants)->sum('amount'), 2),
            'exportedBy' => ['name' => $request->user()->name, 'at' => now()->toIso8601String()],
        ]);
    }

    /** CEFMU classification counts (a case can have more than one), falling back to the category. */
    private function classificationCounts($cases, int $min): array
    {
        $labels = ['early_marriage' => 'Early marriage', 'early_union' => 'Early union', 'forced_marriage' => 'Forced marriage', 'forced_union' => 'Forced union'];
        $counts = [];
        foreach ($cases->select('cases.cefmu_classifications', 'cases.category')->cursor() as $row) {
            $codes = json_decode($row->cefmu_classifications ?? '[]', true) ?: [];
            foreach ($codes ?: [$row->category ?: 'Not classified'] as $code) {
                $label = $labels[$code] ?? (string) $code;
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        }
        arsort($counts);

        return collect($counts)->filter(fn ($total) => $total >= $min)->map(fn ($total, $label) => ['label' => $label, 'total' => $total])->values()->all();
    }

    /** Records that a user printed or saved the analytics view as PDF (the page carries a watermark with the same name and time). */
    public function printed(Request $request)
    {
        $this->authorize('view-analytics');
        $this->authorize('analytics.export');
        $filters = $this->analyticsFilters($request);
        $area = implode(' > ', array_filter([$filters['region'], $filters['province'], $filters['municipality'], $filters['barangay']])) ?: 'Philippines';
        AuditLog::record('analytics.pdf_exported', 'analytics', null, ['area' => $area, 'year' => $filters['year'], 'quarter' => $filters['quarter']], $request->user()->id, 'success',
            ['description' => 'Analytics exported to PDF: ' . $area]);

        return response()->json(['logged' => true]);
    }

    public function quarterly(Request $request)
    {
        $this->authorize('view-analytics');
        $this->authorize('analytics.quarterly');

        return Inertia::render('Analytics/Quarterly', $this->quarterlyDataset($request));
    }

    public function quarterlyExport(Request $request)
    {
        $this->authorize('view-analytics');
        $this->authorize('analytics.quarterly');
        $this->authorize('analytics.export');
        $data = $this->quarterlyDataset($request);
        $rows = collect([
            ['metric' => 'Reporting period', 'value' => $data['period']['label']],
            ...collect($data['totals'])->map(fn ($value, $key) => ['metric' => preg_replace('/([A-Z])/', ' $1', $key), 'value' => $value])->values()->all(),
            ...collect($data['classification'])->map(fn ($item) => ['metric' => "Classification: {$item->label}", 'value' => $item->total])->all(),
            ...collect($data['status'])->map(fn ($item) => ['metric' => "Status: {$item->label}", 'value' => $item->total])->all(),
            ...collect($data['areas'])->map(fn ($item) => ['metric' => "Municipality: {$item->area}", 'value' => $item->total])->all(),
        ]);
        $contents = "metric,value\n" . $rows->map(fn ($row) => $this->csvCell($row['metric']) . ',' . $this->csvCell($row['value']))->implode("\n") . "\n";
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'analytics.quarterly_exported', 'auditable_type' => 'analytics', 'changes' => json_encode(['period' => $data['period']['label'], 'row_count' => $rows->count()]), 'created_at' => now(), 'updated_at' => now()]);

        return response($contents, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"kalinga-quarterly-{$data['period']['year']}-q{$data['period']['quarter']}.csv\""]);
    }

    public function export(Request $request)
    {
        $this->authorize('view-analytics');
        $this->authorize('analytics.export');
        $rows = $this->rows($request);
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'analytics.exported', 'auditable_type' => 'analytics', 'changes' => json_encode(['row_count' => count($rows)]), 'created_at' => now(), 'updated_at' => now()]);
        $contents = "metric,value\n" . collect($rows)->map(fn ($row) => $this->csvCell($row['metric']) . ',' . $this->csvCell($row['value']))->implode("\n") . "\n";

        return response($contents, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="kalinga-operational-summary-' . now()->format('Y-m-d') . '.csv"']);
    }

    public function publicDashboard()
    {
        $status = DB::table('cases')->select('status', DB::raw('count(*) as total'))->whereNull('deleted_at')->groupBy('status')->havingRaw('count(*) >= ?', [self::PUBLIC_MINIMUM])->orderByDesc('total')->get();
        $areas = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->selectRaw("COALESCE(clients.municipality, 'Unspecified') as area, count(*) as total")->whereNull('cases.deleted_at')->groupBy('area')->havingRaw('count(*) >= ?', [self::PUBLIC_MINIMUM])->orderByDesc('total')->get();

        return Inertia::render('PublicDashboard', ['status' => $status, 'areas' => $areas, 'generatedAt' => now()->toIso8601String()]);
    }

    public function public()
    {
        return response()->json(['case_status' => DB::table('cases')->select('status', DB::raw('count(*) as total'))->whereNull('deleted_at')->groupBy('status')->havingRaw('count(*) >= ?', [self::PUBLIC_MINIMUM])->get(), 'generated_at' => now()->toIso8601String()]);
    }
}
