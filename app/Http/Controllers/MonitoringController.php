<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAssignmentEligibility;
use App\Services\CaseClosureHistory;
use App\Services\CaseRemarks;
use App\Services\MonitoringScope;
use App\Services\RolePermissions;
use App\Support\AuditLog;
use App\Support\ClientName;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Case monitoring for the LGU Head, Field Office and Project Manager:
 * a case table and a read-only case summary with secured names (alias or
 * masked name), remarks to the case manager, and closure approval (LGU Head).
 * No narratives, contact details or addresses are shown here.
 */
class MonitoringController extends Controller
{
    private const STATUSES = ['OPEN', 'IN_PROGRESS', 'AWAITING_REFERRAL', 'FOR_FOLLOW_UP', 'FOR_CLOSURE_REVIEW', 'CLOSED', 'REOPENED'];

    public function __construct(private readonly MonitoringScope $scope, private readonly CaseRemarks $remarks) {}

    private function base(User $user)
    {
        return $this->scope->scopeCases(DB::table('cases')->whereNull('cases.deleted_at'), $user)
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->leftJoin('lgu_units', 'lgu_units.id', '=', 'cases.lgu_id')
            ->leftJoin('users as workers', 'workers.id', '=', 'cases.assigned_to');
    }

    public function index(Request $request)
    {
        $this->authorize('cases.monitor');
        $user = $request->user();
        $f = $request->validate(['search' => 'nullable|string|max:80', 'status' => 'nullable|in:' . implode(',', self::STATUSES), 'region' => 'nullable|string|max:120',
            'lgu' => 'nullable|integer', 'worker' => 'nullable|integer', 'risk' => 'nullable|in:low,moderate,high,critical', 'remarks' => 'nullable|boolean', 'sort' => 'nullable|in:recent,oldest,risk,case']);

        $query = $this->base($user)
            ->when($f['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('cases.case_number', 'like', '%' . $term . '%')->orWhere('clients.alias', 'like', '%' . $term . '%')))
            ->when($f['status'] ?? null, fn ($q, $status) => $q->where('cases.status', $status))
            ->when($f['region'] ?? null, fn ($q, $region) => $q->where('lgu_units.region', $region))
            ->when($f['lgu'] ?? null, fn ($q, $lgu) => $q->where('cases.lgu_id', $lgu))
            ->when($f['worker'] ?? null, fn ($q, $worker) => $q->where('cases.assigned_to', $worker))
            ->when($f['risk'] ?? null, fn ($q, $risk) => $q->whereRaw('coalesce(cases.current_risk_level, cases.initial_risk_level) = ?', [$risk]))
            ->when($f['remarks'] ?? false, fn ($q) => $q->whereExists(fn ($r) => $r->from('case_remarks')->whereColumn('case_remarks.case_id', 'cases.id')->whereNull('case_remarks.parent_id')->whereNull('case_remarks.acknowledged_at')));
        match ($f['sort'] ?? 'recent') {
            'oldest' => $query->orderBy('cases.opened_at'),
            'case' => $query->orderBy('cases.case_number'),
            'risk' => $query->orderByRaw("case coalesce(cases.current_risk_level, cases.initial_risk_level) when 'critical' then 0 when 'high' then 1 when 'moderate' then 2 else 3 end")->orderByDesc('cases.last_activity_at'),
            default => $query->orderByDesc(DB::raw('coalesce(cases.last_activity_at, cases.updated_at)')),
        };

        $page = $query->select(['cases.id', 'cases.case_number', 'cases.status', 'cases.stage', 'cases.priority', 'cases.opened_at', 'cases.last_activity_at', 'cases.updated_at',
            DB::raw('coalesce(cases.current_risk_level, cases.initial_risk_level) as risk_level'), 'clients.first_name', 'clients.middle_name', 'clients.last_name', 'clients.alias',
            'lgu_units.name as lgu', 'lgu_units.region', 'workers.name as case_manager',
            DB::raw('(select count(*) from case_remarks where case_remarks.case_id = cases.id and case_remarks.parent_id is null and case_remarks.acknowledged_at is null) as open_remarks')])
            ->paginate(25)->withQueryString();
        $page->getCollection()->transform(fn ($row) => [
            'id' => $row->id, 'case_number' => $row->case_number, 'name' => ClientName::secured($row), 'case_manager' => $row->case_manager, 'lgu' => $row->lgu, 'region' => $row->region,
            'status' => $row->status, 'stage' => $row->stage, 'priority' => $row->priority, 'risk_level' => $row->risk_level, 'opened_at' => $row->opened_at,
            'last_activity_at' => $row->last_activity_at ?? $row->updated_at, 'open_remarks' => (int) $row->open_remarks,
        ]);

        $all = $this->base($user);
        $lguIds = $this->scope->lguIds($user);

        return Inertia::render('Monitoring/Cases', [
            'cases' => $page,
            'filters' => $f,
            'scopeLabel' => $this->scope->label($user),
            'summary' => [
                'total' => (clone $all)->count(),
                'active' => (clone $all)->whereNotIn('cases.status', ['CLOSED'])->count(),
                'closureReview' => (clone $all)->where('cases.status', 'FOR_CLOSURE_REVIEW')->count(),
                'highRisk' => (clone $all)->whereNotIn('cases.status', ['CLOSED'])->whereRaw("coalesce(cases.current_risk_level, cases.initial_risk_level) in ('high', 'critical')")->count(),
            ],
            'options' => [
                'statuses' => self::STATUSES,
                'regions' => DB::table('lgu_units')->when($lguIds !== null, fn ($q) => $q->whereIn('id', $lguIds ?: [0]))->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
                'lgus' => DB::table('lgu_units')->when($lguIds !== null, fn ($q) => $q->whereIn('id', $lguIds ?: [0]))->orderBy('name')->get(['id', 'name', 'region']),
                'workers' => User::query()->where('role', 'social_worker')->whereIn('id', (clone $all)->whereNotNull('cases.assigned_to')->distinct()->pluck('cases.assigned_to'))->orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    public function show(Request $request, int $case)
    {
        $this->authorize('cases.monitor');
        $user = $request->user();
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record && $this->scope->allowsCase($record, $user), 404);
        $client = DB::table('clients')->find($record->client_id);
        $lgu = DB::table('lgu_units')->find($record->lgu_id);

        if (Cache::add("audit:monitored:{$user->id}:{$case}", true, now()->addMinutes(30))) {
            AuditLog::record('case.monitored', 'case', $case, [], $user->id, 'success', ['description' => 'Viewed the case monitoring summary']);
        }

        $birth = $client?->birthdate ? Carbon::parse($client->birthdate) : null;
        $notes = DB::table('case_notes')->where('case_id', $case);

        return Inertia::render('Monitoring/CaseSummary', [
            'caseRecord' => [
                'id' => $record->id, 'case_number' => $record->case_number, 'name' => ClientName::secured($client), 'age' => $birth?->age, 'sex' => $client?->sex,
                'lgu' => $lgu?->name, 'province' => $lgu?->province, 'region' => $lgu?->region, 'case_manager' => DB::table('users')->where('id', $record->assigned_to)->value('name'),
                'status' => $record->status, 'stage' => $record->stage, 'priority' => $record->priority,
                'initial_risk_level' => $record->initial_risk_level, 'current_risk_level' => $record->current_risk_level ?? null,
                'classifications' => json_decode($record->cefmu_classifications ?? '[]', true) ?: [], 'union_forms' => json_decode($record->union_forms ?? '[]', true) ?: [],
                'union_type' => $record->union_type, 'union_status' => $record->union_status ?? null, 'current_residence' => $record->current_residence ?? null,
                'opened_at' => $record->opened_at, 'last_activity_at' => $record->last_activity_at, 'closed_at' => $record->closed_at,
                'next_review_at' => $record->next_review_at,
            ],
            'progress' => [
                'notes' => (clone $notes)->count(),
                'lastNoteAt' => (clone $notes)->max(DB::raw('coalesce(occurred_at, created_at)')),
                'referralsOpen' => DB::table('referrals')->where('case_id', $case)->whereIn('status', ['PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS'])->count(),
                'referralsTotal' => DB::table('referrals')->where('case_id', $case)->count(),
                'visits' => DB::table('monitoring_visits')->where('case_id', $case)->count(),
            ],
            'activity' => DB::table('case_status_history')->where('case_id', $case)->latest('created_at')->limit(10)->get(['from_status', 'to_status', 'created_at']),
            'remarks' => $this->remarks->thread($case),
            'closure' => $record->status === 'FOR_CLOSURE_REVIEW' ? [
                'summary' => $record->closure_summary, 'ready' => !empty($record->closure_summary),
                'canApprove' => $user->hasPermission('closure.approve'),
            ] : null,
            'canRemark' => $user->hasPermission('cases.remark'),
            'canOpenRecord' => $user->hasPermission('cases.view') && app(CaseAssignmentEligibility::class)->canRead($user, $record),
            'roleLabel' => RolePermissions::ROLES[$user->role][0] ?? $user->role,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function remark(Request $request, int $case)
    {
        $this->authorize('cases.monitor');
        $this->authorize('cases.remark');
        $data = $request->validate(['body' => 'required|string|min:2|max:2000']);
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record && $this->scope->allowsCase($record, $request->user()), 404);
        $this->remarks->add($record, $request->user(), $data['body']);

        return back()->with('success', 'Remark sent to the case manager.');
    }

    /** LGU Head: approve the closure record and close the case. */
    public function approveClosure(Request $request, int $case)
    {
        $this->authorize('closure.approve');
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        DB::transaction(function () use ($request, $case, $data) {
            $record = DB::table('cases')->whereNull('deleted_at')->lockForUpdate()->find($case);
            $actor = $request->user();
            abort_unless($record && $this->scope->allowsCase($record, $actor), 404);
            abort_unless($record->status === 'FOR_CLOSURE_REVIEW' && !empty($record->closure_summary), 422, 'The case manager has not submitted a closure record yet.');
            $history = app(CaseClosureHistory::class);
            $history->review($record, $actor);
            $history->close($record, $actor);
            DB::table('cases')->where('id', $case)->update(['closure_reviewed_by' => $actor->id, 'closure_reviewed_at' => now(), 'status' => 'CLOSED', 'stage' => 'closed',
                'closed_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            DB::table('case_status_history')->insert(['case_id' => $case, 'from_status' => 'FOR_CLOSURE_REVIEW', 'to_status' => 'CLOSED', 'changed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::record('case.closure_reviewed', 'case', $case, ['decision' => 'approved'], $actor->id);
            AuditLog::record('case.transitioned', 'case', $case, ['from' => 'FOR_CLOSURE_REVIEW', 'to' => 'CLOSED'], $actor->id);
            $this->remarks->add($record, $actor, trim('Closure approved. ' . ($data['note'] ?? '')), 'closure_approved');
        });

        return back()->with('success', 'Closure approved. The case is closed.');
    }

    /** LGU Head: send the closure back to the case manager with the reason. */
    public function returnClosure(Request $request, int $case)
    {
        $this->authorize('closure.approve');
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        DB::transaction(function () use ($request, $case, $data) {
            $record = DB::table('cases')->whereNull('deleted_at')->lockForUpdate()->find($case);
            $actor = $request->user();
            abort_unless($record && $this->scope->allowsCase($record, $actor), 404);
            abort_unless($record->status === 'FOR_CLOSURE_REVIEW', 422, 'Only a case waiting for closure review can be returned.');
            DB::table('cases')->where('id', $case)->update(['status' => 'IN_PROGRESS', 'stage' => 'in progress', 'closure_reviewed_by' => null, 'closure_reviewed_at' => null,
                'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            DB::table('case_status_history')->insert(['case_id' => $case, 'from_status' => 'FOR_CLOSURE_REVIEW', 'to_status' => 'IN_PROGRESS', 'changed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::record('case.closure_reviewed', 'case', $case, ['decision' => 'returned'], $actor->id, 'success', ['description' => 'Case closure returned to the case manager']);
            AuditLog::record('case.transitioned', 'case', $case, ['from' => 'FOR_CLOSURE_REVIEW', 'to' => 'IN_PROGRESS'], $actor->id);
            $this->remarks->add($record, $actor, 'Closure returned: ' . $data['reason'], 'closure_returned');
        });

        return back()->with('success', 'Closure returned to the case manager.');
    }
}
