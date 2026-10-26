<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
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

    public function index(Request $request)
    {
        $this->authorize('view-analytics');
        $base = $this->scopedCases(
            DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at'),
            $request,
        );
        $caseIds = (clone $base)->select('cases.id');
        $trend = collect(range(5, 0))->map(function ($offset) use ($base, $request) {
            $month = now()->startOfMonth()->subMonths($offset);

            return ['label' => $month->format('M Y'), 'total' => $this->safeCount($request, (clone $base)->whereBetween('cases.opened_at', [$month, $month->copy()->endOfMonth()])->count())];
        });

        return Inertia::render('Analytics/Index', [
            'canExport' => $request->user()->can('analytics.export'),
            'canQuarterly' => $request->user()->can('analytics.quarterly'),
            'status' => (clone $base)->select('cases.status', DB::raw('count(*) as total'))->groupBy('cases.status')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'geo' => (clone $base)->selectRaw("COALESCE(clients.municipality, 'Unspecified') as area, count(*) as total")->groupBy('area')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'referrals' => DB::table('referrals')->whereIn('case_id', $caseIds)->select('status', DB::raw('count(*) as total'))->groupBy('status')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->get(),
            'trend' => $trend,
            'sex' => (clone $base)->selectRaw("COALESCE(clients.sex, 'Not recorded') as label, count(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'classification' => (clone $base)->selectRaw("COALESCE(cases.category, 'Not classified') as label, count(*) as total")->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->get(),
            'age' => (clone $base)->selectRaw($this->ageBandExpression() . ' as label, count(*) as total')->groupBy('label')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->get(),
            'services' => DB::table('interventions')->join('case_plans', 'case_plans.id', '=', 'interventions.case_plan_id')->whereIn('case_plans.case_id', (clone $base)->select('cases.id'))->select('interventions.service_category as label', DB::raw('count(*) as total'))->groupBy('interventions.service_category')->havingRaw('COUNT(*) >= ?', [$this->minimum($request)])->orderByDesc('total')->limit(8)->get(),
            'totals' => $this->safeCounts($request, ['cases' => (clone $base)->count(), 'clients' => (clone $base)->distinct('clients.id')->count('clients.id'), 'openReferrals' => DB::table('referrals')->whereIn('case_id', $caseIds)->whereNotIn('status', ['COMPLETED', 'WITHDRAWN'])->count()]),
        ]);
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
