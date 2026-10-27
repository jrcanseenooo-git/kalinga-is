<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Services\CaseClosureHistory;
use App\Services\CaseForms;
use App\Services\CaseGrants;
use App\Services\CaseIntake;
use App\Services\CaseLifecyclePolicy;
use App\Services\CaseRemarks;
use App\Services\CaseVersionGuard;
use App\Services\DashboardTodos;
use App\Services\IdentityIntegrity;
use App\Services\IntakeDrafts;
use App\Services\Kavat;
use App\Services\LguAssignmentScope;
use App\Services\MonitoringScope;
use App\Services\NoteReferrals;
use App\Services\OperationalNotification;
use App\Services\OptionSets;
use App\Services\RecordsGovernance;
use App\Services\ReferenceNumberService;
use App\Services\ReferralTracking;
use App\Services\RolePermissions;
use App\Services\SmartSuggestions;
use App\Services\WorkerTasks;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CaseworkController extends Controller
{
    public function __construct(private readonly ReferenceNumberService $references, private readonly CaseAccessScope $scope, private readonly CaseLifecyclePolicy $lifecyclePolicies, private readonly RecordsGovernance $records, private readonly IdentityIntegrity $identity, private readonly LguAssignmentScope $lgus, private readonly OperationalNotification $notifications, private readonly CaseAssignmentEligibility $assignments) {}

    private const CASE_TRANSITIONS = [
        'OPEN' => ['IN_PROGRESS', 'AWAITING_REFERRAL', 'FOR_FOLLOW_UP'],
        'IN_PROGRESS' => ['AWAITING_REFERRAL', 'FOR_FOLLOW_UP', 'FOR_CLOSURE_REVIEW'],
        'AWAITING_REFERRAL' => ['IN_PROGRESS', 'FOR_FOLLOW_UP'],
        'FOR_FOLLOW_UP' => ['IN_PROGRESS', 'FOR_CLOSURE_REVIEW'],
        'FOR_CLOSURE_REVIEW' => ['CLOSED', 'IN_PROGRESS'],
        'CLOSED' => ['REOPENED'], 'REOPENED' => ['IN_PROGRESS'],
    ];

    private function caseAccess(Request $request, object $case, bool $write = false): void
    {
        $this->authorize('cases.view');
        $location = (isset($case->municipality) || isset($case->province)) ? $case : DB::table('clients')->find($case->client_id, ['municipality', 'province']);
        abort_unless($location && $this->scope->allowsCase($case, $request->user(), $location->municipality, $location->province), 403);
        if ($write) {
            $this->authorize('cases.update');
        }
    }

    /**
     * Dashboard: a to-do list only (owner instruction). Charts and figures
     * are in Analytics. Every role gets its own items: case managers their
     * case next steps, activities and saved intakes; LGU Heads closures to
     * approve; monitors replies and cases to look at; managers accounts to
     * approve; everyone invitations to answer.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'cpu_member') {
            return redirect()->route('mdt.collaboration.index');
        }
        $cases = DB::table('cases')->leftJoin('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at');
        $this->scope->scopeCases($cases, $user);
        $holdsCases = $user->hasPermission('cases.update');
        $upcoming = app(WorkerTasks::class)->query($user)->where('tasks.status', 'open')->where('tasks.due_at', '>=', now())
            ->where(fn ($query) => $query->whereNull('tasks.case_id')->orWhereIn('tasks.case_id', (clone $cases)->select('cases.id')))
            ->orderBy('tasks.due_at')->orderBy('tasks.id')->limit(5)->get(['tasks.id', 'tasks.case_id', 'tasks.title', 'tasks.due_at', 'cases.case_number']);

        return Inertia::render('Dashboard', [
            'asOf' => now()->toIso8601String(),
            'todos' => app(DashboardTodos::class)->groups($user),
            'upcomingActivities' => app(WorkerTasks::class)->withIsoDates($upcoming),
            'actionQueue' => $holdsCases ? app(WorkerTasks::class)->actionQueue($user, $cases) : [],
            // Rule-based next actions for the case manager's own cases (case numbers only).
            'suggestions' => $user->role === 'social_worker' ? app(SmartSuggestions::class)->forCases($cases) : [],
            'attention' => $holdsCases ? $this->attentionSummary($user->id, $user->role, $cases) : ['dueSoon' => 0, 'overdueActions' => 0],
            'metrics' => ['activeCases' => $holdsCases ? (clone $cases)->whereNotIn('cases.status', ['CLOSED'])->count() : null],
            'scopeLabel' => $user->hasPermission('cases.monitor') ? app(MonitoringScope::class)->label($user) : $this->geographicScope($user)['label'],
            'roleLabel' => RolePermissions::label($user->role),
        ]);
    }

    /**
     * Declares the maximum map detail that the current user may receive.
     * Geometry is delivered separately, per authorized LGU, once an approved
     * NAMRIA/owner-supplied boundary package is installed.
     */
    private function geographicScope(object $user): array
    {
        if ($user->role === 'social_worker') {
            $lgu = DB::table('lgu_units')->whereIn('id', $this->lgus->activeLguIds($user))->orderBy('id')->first(['psgc_code', 'name', 'province', 'region']);
            if ($lgu) {
                return ['level' => 'barangay', 'label' => $lgu->name, 'psgcCode' => $lgu->psgc_code, 'boundaryStatus' => 'awaiting_approved_boundary'];
            }

            $municipality = trim((string) $user->municipality);
            if ($municipality !== '') {
                return ['level' => 'barangay', 'label' => $municipality, 'psgcCode' => null, 'boundaryStatus' => 'assignment_requires_psgc_code'];
            }
        }

        if ($user->role === 'fo_user' && trim((string) $user->province) !== '') {
            return ['level' => 'municipality', 'label' => trim((string) $user->province), 'psgcCode' => null, 'boundaryStatus' => 'regional_boundaries_available'];
        }

        return ['level' => 'region', 'label' => 'Authorized case coverage', 'psgcCode' => null, 'boundaryStatus' => 'regional_boundaries_available'];
    }

    /**
     * Legacy operational deadlines, calculated at request time so a stale
     * dashboard can never hide a currently overdue record.
     */
    private function attentionSummary(int $userId, string $role, $accessibleCases): array
    {
        $now = now();
        $caseIds = (clone $accessibleCases)->select('cases.id');
        $clientIds = (clone $accessibleCases)->select('cases.client_id');
        $referrals = DB::table('referrals')->whereIn('case_id', $caseIds);

        return [
            'dueSoon' => DB::table('tasks')->where('assigned_to', $userId)->whereIn('case_id', $caseIds)->where('status', 'open')->whereBetween('due_at', [$now, $now->copy()->addDay()])->count(),
            'overdueActions' => DB::table('tasks')->where('assigned_to', $userId)->whereIn('case_id', $caseIds)->where('status', 'open')->where('due_at', '<', $now)->count(),
            // 24 hours from submission for reports still awaiting screening.
            'screeningOverdue' => DB::table('reports')->whereIn('client_id', $clientIds)->whereIn('status', ['NEW', 'FOR_SCREENING'])->where('submitted_at', '<', $now->copy()->subDay())->count(),
            // 72 hours from screening for reports still under validation.
            'validationOverdue' => DB::table('reports')->whereIn('client_id', $clientIds)->where('status', 'UNDER_VALIDATION')->where('screened_at', '<', $now->copy()->subDays(3))->count(),
            // 24 hours to make a first contact, represented by the first case update.
            'firstContactOverdue' => (clone $accessibleCases)->where('cases.status', 'OPEN')->where('cases.opened_at', '<', $now->copy()->subDay())->whereColumn('cases.updated_at', 'cases.opened_at')->count(),
            // A case plan must be started within seven days of opening.
            'planOverdue' => (clone $accessibleCases)->whereIn('cases.status', ['OPEN', 'IN_PROGRESS'])->where('cases.opened_at', '<', $now->copy()->subDays(7))->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('case_plans')->whereColumn('case_plans.case_id', 'cases.id')->where('case_plans.status', 'active');
            })->count(),
            'followUps' => (clone $accessibleCases)->where('cases.status', 'FOR_FOLLOW_UP')->count(),
            // 30 days is the legacy no-update threshold, replacing the old 14-day placeholder.
            'staleCases' => (clone $accessibleCases)->where('cases.last_activity_at', '<', $now->copy()->subDays(30))->whereNotIn('cases.status', ['CLOSED'])->count(),
            // Provider response is due five days after sending; completion is due 30 days after acceptance.
            'referralResponseOverdue' => (clone $referrals)->where('status', 'SENT')->where('referred_at', '<', $now->copy()->subDays(5))->count(),
            'referralCompletionOverdue' => (clone $referrals)->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])->where('accepted_at', '<', $now->copy()->subDays(30))->count(),
        ];
    }

    public function cases(Request $request)
    {
        $this->authorize('cases.view');
        $filters = $request->validate([
            'search' => 'nullable|string|max:100', 'status' => 'nullable|in:' . implode(',', array_keys(self::CASE_TRANSITIONS)),
            'active' => 'nullable|boolean', 'region' => 'nullable|string|max:120', 'priority' => 'nullable|in:low,normal,high,urgent',
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'sort' => 'nullable|in:recent,opened_asc,opened_desc,priority', 'attention' => 'nullable|boolean',
        ]);
        $query = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->leftJoin('users', 'users.id', '=', 'cases.assigned_to')->whereNull('cases.deleted_at');
        $this->scope->scopeCases($query, $request->user());
        // Counts for the registry's status tabs, computed on the same authorized scope before list filters.
        $statusCounts = (clone $query)->selectRaw("COUNT(*) as all_cases, SUM(CASE WHEN cases.status <> 'CLOSED' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN cases.status = 'FOR_FOLLOW_UP' THEN 1 ELSE 0 END) as follow_up, SUM(CASE WHEN cases.status = 'FOR_CLOSURE_REVIEW' THEN 1 ELSE 0 END) as closure_review, SUM(CASE WHEN cases.status = 'CLOSED' THEN 1 ELSE 0 END) as closed")->first();
        // Rule-based next steps (App\Services\SmartSuggestions) for every case in scope:
        // drives the "Needs action" view and the per-row hint. No names are used.
        $nextSteps = app(SmartSuggestions::class)->firstPerCase((clone $query)->select('cases.id'));
        $statusCounts->needs_action = count($nextSteps);
        if (!empty($filters['attention'])) {
            $query->whereIn('cases.id', array_keys($nextSteps) ?: [0]);
        }
        if (!empty($filters['status'])) {
            $query->where('cases.status', $filters['status']);
        }
        if (!empty($filters['active'])) {
            $query->where('cases.status', '!=', 'CLOSED');
        }
        if (!empty($filters['region'])) {
            $query->where('clients.region', $filters['region']);
        }
        if (!empty($filters['priority'])) {
            $query->where('cases.priority', $filters['priority']);
        }
        if (!empty($filters['from'])) {
            $query->whereDate('cases.opened_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('cases.opened_at', '<=', $filters['to']);
        }
        match ($filters['sort'] ?? 'recent') {
            'opened_asc' => $query->orderBy('cases.opened_at'),
            'opened_desc' => $query->orderByDesc('cases.opened_at'),
            'priority' => $query->orderByRaw("CASE cases.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END"),
            default => $query->orderByDesc('cases.updated_at'),
        };
        $query->orderBy('cases.id');
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('cases.case_number', 'like', '%' . $request->search . '%')->orWhere('clients.first_name', 'like', '%' . $request->search . '%')->orWhere('clients.last_name', 'like', '%' . $request->search . '%'));
        }

        return Inertia::render('Cases/Index', [
            'canCreate' => $request->user()->can('cases.create'),
            'statusCounts' => collect((array) $statusCounts)->map(fn ($value) => (int) $value)->all(),
            'cases' => $query->select([
                'cases.id', 'cases.case_number', 'cases.category', 'cases.priority', 'cases.status', 'cases.opened_at', 'cases.created_at',
                'clients.first_name', 'clients.last_name', 'clients.municipality', 'clients.province', 'clients.region',
                'users.name as worker_name',
            ])->paginate(12)->appends($filters)->through(fn ($row) => tap($row, fn ($row) => $row->next_step = $nextSteps[$row->id] ?? null)),
            'filters' => ['search' => $request->string('search')->toString(), 'status' => $filters['status'] ?? '', 'active' => (bool) ($filters['active'] ?? false), 'attention' => (bool) ($filters['attention'] ?? false), 'region' => $filters['region'] ?? '',
                'priority' => $filters['priority'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'sort' => $filters['sort'] ?? 'recent'],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('cases.create');
        $lguIds = $this->lgus->activeLguIds($request->user());
        $assignedLgu = $lguIds ? DB::table('lgu_units')->find($lguIds[0]) : null;

        $draftId = $request->validate(['draft' => 'nullable|uuid'])['draft'] ?? null;
        $draft = $draftId ? IntakeDrafts::open($draftId, $request->user()->id) : null;
        abort_if($draftId && !$draft, 404, 'This saved intake is no longer available.');

        return Inertia::render('Cases/Create', [
            'assignedLocation' => $assignedLgu ? [
                'municipality' => $assignedLgu->name,
                'province' => $assignedLgu->province,
                'region' => $assignedLgu->region,
            ] : null,
            'intakeOptions' => [
                'riskFactors' => OptionSets::options(OptionSets::RISK_FACTORS),
                'riskRule' => OptionSets::riskRule(),
                'customFields' => CaseIntake::customFields(),
            ],
            'draft' => $draft,
            'savedIntakes' => $draft ? [] : IntakeDrafts::list($request->user()->id),
            'keepDays' => IntakeDrafts::KEEP_DAYS,
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function store(Request $request, CaseIntake $intake)
    {
        $this->authorize('cases.create');
        $validator = validator($request->all(), $intake->rules() + ['draft_id' => 'nullable|uuid']);
        $validator->after(fn ($v) => $intake->after($v));
        $data = $validator->validate();
        $case = DB::transaction(function () use ($data, $request, $intake) {
            $lguIds = $this->lgus->activeLguIds($request->user());
            $location = null;
            if ($lguIds[0] ?? null) {
                $location = DB::table('lgu_units')->find($lguIds[0]);
                abort_unless($location, 422, 'Your LGU assignment could not be resolved. Contact your administrator.');
            }
            [$id] = $intake->create($data, $request->user(), ['lgu_id' => $lguIds[0] ?? null, 'location' => $location, 'source' => 'online_intake']);
            if (!empty($data['draft_id'])) {
                IntakeDrafts::finish($data['draft_id'], $request->user()->id, $id);
            }

            return $id;
        });

        return redirect()->route('cases.show', $case)->with('success', 'Case folder created and assigned to you.');
    }

    /** Referral statuses this user may record next, by current status. */
    private function referralTransitionsFor(Request $request): array
    {
        $out = [];
        foreach (OperationsController::REFERRAL_TRANSITIONS as $status => $targets) {
            $out[$status] = array_values(array_filter($targets, fn ($target) => $request->user()->can($target === 'COMPLETED' ? 'referrals.close' : 'referrals.respond')));
        }

        return $out;
    }

    public function show(Request $request, int $case)
    {
        $record = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->leftJoin('users', 'users.id', '=', 'cases.assigned_to')->where('cases.id', $case)->whereNull('cases.deleted_at')->first([
            'cases.id', 'cases.case_number', 'cases.client_id', 'cases.assigned_to', 'cases.status', 'cases.stage', 'cases.priority',
            'cases.category', 'cases.case_type', 'cases.cefmu_classifications', 'cases.union_type', 'cases.partner_age_band', 'cases.union_forms', 'cases.union_status', 'cases.partner_current_age', 'cases.union_duration_months', 'cases.current_residence', 'clients.alias',
            'cases.current_risk_level', 'cases.current_risk_assessed_at', 'cases.custom_fields',
            'cases.education_status_at_intake', 'cases.pregnancy_parenting_status', 'cases.circumstance', 'cases.mode_of_admission',
            'cases.referred_by', 'cases.referral_date', 'cases.immediate_safety_concern', 'cases.initial_risk_level', 'cases.risk_indicators',
            'cases.privacy_notice_provided_at', 'cases.privacy_notice_method', 'cases.intake_completed_at', 'cases.opened_at', 'cases.closed_at',
            'cases.last_activity_at', 'cases.next_review_at', 'cases.offline_version', 'cases.lgu_id', 'cases.created_at', 'cases.updated_at',
            'clients.first_name', 'clients.last_name', 'clients.birthdate', 'clients.sex', 'clients.phone',
            'clients.region', 'clients.province', 'clients.barangay', 'clients.municipality',
            'clients.civil_status', 'clients.religion', 'clients.ip_category', 'clients.education_attainment',
            'clients.safe_contact_method', 'clients.safe_contact_notes',
            'clients.primary_caregiver_name', 'clients.primary_caregiver_relationship', 'clients.primary_caregiver_contact',
            'clients.house_no_street', 'clients.middle_name', 'clients.suffix',
            'users.name as worker_name',
        ]);
        abort_unless($record, 404);
        $this->caseAccess($request, $record);
        // Access log for the PIA: one "case.viewed" row per user and case every 30 minutes.
        if (Cache::add("case-viewed:{$request->user()->id}:{$case}", true, now()->addMinutes(30))) {
            AuditLog::record('case.viewed', 'case', $case);
        }
        // Narratives and section content intentionally stay behind the permission-filtered assessment endpoint.
        $assessmentSummary = DB::table('case_assessments')->where('case_id', $case)->orderByDesc('is_current')->orderByDesc('version')->first(['id', 'version', 'record_type', 'status', 'assessment_date', 'finalized_at', 'is_current']);
        // Metadata only: no child expressions, preferences, or decision narratives enter page props.
        $childParticipationSummary = DB::table('child_participation_records')->where('case_id', $case)->where('status', 'final')->where('is_current', true)->latest('participation_date')->first(['id', 'occasion_type', 'decision_title', 'participation_date', 'status', 'finalized_at']);
        if ($childParticipationSummary && !$request->user()->hasPermission('participation.restricted_view')) {
            $childParticipationSummary = (object) ['id' => $childParticipationSummary->id, 'participation_date' => $childParticipationSummary->participation_date, 'status' => $childParticipationSummary->status, 'finalized_at' => $childParticipationSummary->finalized_at, 'restricted' => true];
        }
        // The overview intentionally exposes conference operational metadata only.
        // Narrative, decisions, participant details, and review comments remain in
        // the permission-scoped conference workspace.
        $conferenceSummary = DB::table('case_conferences')->where('case_id', $case)->where('status', 'final')->latest('finalized_at')->first(['conference_type', 'finalized_at', 'next_review_at']);
        $conferenceActions = DB::table('case_conference_actions')->join('case_conferences', 'case_conferences.id', '=', 'case_conference_actions.case_conference_id')->where('case_conferences.case_id', $case)->where('case_conferences.status', 'final');
        $conferenceActionSummary = [
            'total' => (clone $conferenceActions)->count(),
            'pending' => (clone $conferenceActions)->where('case_conference_actions.local_status', 'pending')->count(),
            'overdue' => (clone $conferenceActions)->where('case_conference_actions.local_status', 'pending')->whereNotNull('case_conference_actions.target_at')->where('case_conference_actions.target_at', '<', now())->count(),
        ];
        // Overview receives plan metadata only. Goal statements, intervention details,
        // review comments, and professional narratives remain in Plan & Continuity.
        $currentPlan = DB::table('case_plans')->where('case_id', $case)->where('is_current', true)->first(['id', 'version', 'status', 'review_due_at', 'updated_at']);
        $planSummary = $currentPlan ? [
            'version' => $currentPlan->version,
            'status' => $currentPlan->status,
            'review_due_at' => $currentPlan->review_due_at,
            'goal_count' => DB::table('case_plan_goals')->where('case_plan_id', $currentPlan->id)->count(),
            'open_intervention_count' => DB::table('interventions')->where('case_plan_id', $currentPlan->id)->whereNotIn('progress', ['completed', 'discontinued'])->count(),
        ] : null;
        $canManageMdt = $request->user()->hasPermission('mdt.assign');

        return Inertia::render('Cases/Show', [
            'caseRecord' => $record, 'assessmentSummary' => $assessmentSummary, 'childParticipationSummary' => $childParticipationSummary,
            'conferenceSummary' => $conferenceSummary, 'conferenceActionSummary' => $conferenceActionSummary, 'planSummary' => $planSummary,
            'notes' => DB::table('case_notes')->join('users', 'users.id', '=', 'case_notes.created_by')->where('case_id', $case)->latest('occurred_at')->latest('case_notes.id')->get(['case_notes.*', 'users.name as author']),
            'referrals' => app(ReferralTracking::class)->withEvents(DB::table('referrals')->leftJoin('service_providers', 'service_providers.id', '=', 'referrals.service_provider_id')->where('case_id', $case)->get(['referrals.*', 'service_providers.name as provider'])),
            'referralTargets' => app(ReferralTracking::class)->targets(),
            'referralTransitions' => $this->referralTransitionsFor($request),
            'servicesProvided' => app(NoteReferrals::class)->servicesFor($case),
            'cpuMembers' => $record->lgu_id ? DB::table('lgu_cpu_members')->where('lgu_id', $record->lgu_id)->where('active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'cpu_role', 'position', 'user_id'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'cpu_role' => $m->cpu_role, 'position' => $m->position, 'has_account' => (bool) $m->user_id]) : [],
            'hasSharingConsent' => app(ReferralTracking::class)->hasSharingConsent($case),
            'visits' => DB::table('monitoring_visits')->where('case_id', $case)->latest()->get(),
            'documents' => DB::table('documents')->leftJoin('record_governance', function ($join) {
                $join->on('record_governance.record_id', '=', 'documents.id')->where('record_governance.record_type', '=', 'document');
            })->where('documents.case_id', $case)->whereNull('documents.deleted_at')->latest('documents.created_at')->get(['documents.*', 'record_governance.id as governance_id', 'record_governance.record_classification_id', 'record_governance.lifecycle_state', 'record_governance.scan_state', 'record_governance.legacy_record'])->map(fn ($document) => $this->records->safeDocument($document, $document->governance_id ? (object) ['record_classification_id' => $document->record_classification_id, 'scan_state' => $document->scan_state, 'lifecycle_state' => $document->lifecycle_state] : null, $request->user())),
            // Generic assessments remain for compatibility and controlled
            // reconciliation, but their narratives must not travel in case props.
            'legacyAssessmentSummary' => [
                'count' => DB::table('assessments')->where('case_id', $case)->count(),
                'latest' => DB::table('assessments')->where('case_id', $case)->latest()->first(['id', 'status', 'version', 'updated_at']),
            ],
            'riskAssessments' => DB::table('risk_assessments')->join('users', 'users.id', '=', 'risk_assessments.assessed_by')->where('case_id', $case)->latest('assessed_at')->get(['risk_assessments.*', 'users.name as assessor'])->map(fn ($assessment) => ['assessment' => $assessment, 'factors' => DB::table('risk_assessment_factors')->where('risk_assessment_id', $assessment->id)->get()]),
            // Detailed plans are fetched only by the dedicated scoped workspace.
            'consentForm' => $this->consentForm($case), 'relationships' => DB::table('relationships')->where('client_id', $record->client_id)->latest()->get(),
            'remarks' => app(CaseRemarks::class)->thread($case),
            'kavatDefinition' => Kavat::definition(), 'kavat' => CaseFormController::kavatHistory($case),
            'formsDefinition' => CaseForms::definition(), 'forms' => CaseForms::forCase($case), 'formSuggestions' => CaseForms::suggestions($record, $record),
            'grants' => CaseGrants::forCase($case) + ['types' => CaseGrants::types()],
            // Labels of the extra intake fields (answers are in caseRecord.custom_fields).
            'customFieldLabels' => DB::table('form_fields')->where('form', 'intake')->pluck('label', 'key'),
            'canEditForms' => $request->user()->hasPermission('cases.update') && $record->status !== 'CLOSED',
            'timeline' => DB::table('audit_logs')->where('auditable_type', 'case')->where('auditable_id', $case)->where('event', '!=', 'case.viewed')->latest()->limit(30)->get(['id', 'event', 'changes', 'outcome', 'created_at']),
            'tasks' => app(WorkerTasks::class)->withIsoDates(DB::table('tasks')->where('case_id', $case)->orderBy('due_at')->get()),
            'canGenerateReport' => $request->user()->can('reports.social.generate'),
            'transitions' => self::CASE_TRANSITIONS[$record->status] ?? [], 'canAssessRisk' => $request->user()->hasPermission('risk.assess'), 'canArchive' => $request->user()->hasPermission('cases.archive'), 'canReviewClosure' => $request->user()->hasPermission('cases.close'), 'canGovernRecords' => $request->user()->hasPermission('records.retention.review'),
            'canManageMdt' => $canManageMdt, 'mdtAssignments' => $canManageMdt ? DB::table('mdt_case_access')->join('users', 'users.id', '=', 'mdt_case_access.user_id')->where('mdt_case_access.case_id', $case)->latest('mdt_case_access.assigned_at')->get(['mdt_case_access.*', 'users.name as member_name']) : [], 'mdtUsers' => $canManageMdt ? DB::table('users')->where('role', 'cpu_member')->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function update(Request $request, int $case)
    {
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record, 404);
        $this->authorize('cases.update');
        $this->caseAccess($request, $record);
        $data = $request->validate(['first_name' => 'required|string|max:80', 'last_name' => 'required|string|max:80', 'alias' => 'nullable|string|max:80', 'birthdate' => 'nullable|date|before_or_equal:today', 'sex' => 'nullable|in:Male,Female', 'phone' => ['nullable', 'regex:/^(?:\\+63|0)9\\d{9}$/'], 'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120', 'barangay' => 'nullable|string|max:120', 'category' => 'nullable|string|max:80', 'priority' => 'required|in:low,normal,high,urgent']);
        DB::transaction(function () use ($request, $record, $case, $data) {
            DB::table('clients')->where('id', $record->client_id)->update(collect($data)->only(['first_name', 'last_name', 'alias', 'birthdate', 'sex', 'phone', 'region', 'province', 'municipality', 'barangay'])->merge(['updated_at' => now()])->all());
            DB::table('cases')->where('id', $case)->update(['category' => $data['category'] ?? null, 'priority' => $data['priority'], 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case.updated', 'auditable_type' => 'case', 'auditable_id' => $case, 'changes' => json_encode(['fields' => array_keys($data)]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Case and client details updated.');
    }

    public function destroy(Request $request, int $case)
    {
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record, 404);
        $this->authorize('cases.archive');
        $this->caseAccess($request, $record);
        DB::transaction(function () use ($request, $case) {
            DB::table('cases')->where('id', $case)->update(['deleted_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case.archived', 'auditable_type' => 'case', 'auditable_id' => $case, 'changes' => json_encode(['method' => 'soft_delete']), 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('cases.index')->with('success', 'Case archived. The client record and audit trail were preserved.');
    }

    public function note(Request $request, int $case)
    {
        $record = DB::table('cases')->find($case);
        abort_unless($record, 404);
        $this->authorize('cases.note');
        $this->caseAccess($request, $record);
        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'action_taken' => 'nullable|string|max:3000',
            'next_steps' => 'nullable|string|max:3000',
            'note_type' => 'required|in:contact,monitoring,referral,service_delivery,coordination,case_review,other',
            'occurred_at' => 'required|date|before_or_equal:now',
            'create_follow_up' => 'sometimes|boolean',
            'follow_up_title' => 'required_if:create_follow_up,true|nullable|string|max:255',
            'follow_up_due_at' => 'nullable|date',
        ]);
        // A referral note carries the referral; a service note carries the service given.
        $noteReferrals = app(NoteReferrals::class);
        if ($data['note_type'] === 'referral') {
            $this->authorize('referrals.create');
            $data['referral'] = $request->validate($noteReferrals->referralRules())['referral'];
        }
        if ($data['note_type'] === 'service_delivery' && $request->filled('service.service_type')) {
            $data['service'] = $request->validate($noteReferrals->serviceRules())['service'];
        }
        $data['occurred_at'] = Carbon::parse($data['occurred_at'])->utc()->format('Y-m-d H:i:s');
        if (!empty($data['follow_up_due_at'])) {
            $data['follow_up_due_at'] = Carbon::parse($data['follow_up_due_at'])->utc()->format('Y-m-d H:i:s');
        }
        DB::transaction(function () use ($data, $case, $request, $record, $noteReferrals) {
            $id = DB::table('case_notes')->insertGetId([
                'case_id' => $case, 'body' => $data['body'], 'action_taken' => $data['action_taken'] ?? null, 'next_steps' => $data['next_steps'] ?? null,
                'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note',
                'note_type' => $data['note_type'], 'occurred_at' => $data['occurred_at'], 'finalized_at' => now(),
                'finalized_by' => $request->user()->id, 'is_current' => true, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (!empty($data['referral'])) {
                $referral = $noteReferrals->createReferral($request->user(), $record, $id, $data['referral'], $data['occurred_at']);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'referral.created', 'auditable_type' => 'referral', 'auditable_id' => $referral, 'changes' => json_encode(['lawful_basis' => $data['referral']['lawful_basis'], 'from_note' => $id]), 'created_at' => now(), 'updated_at' => now()]);
            }
            if (!empty($data['service'])) {
                $service = $noteReferrals->recordService($request->user(), $case, $data['service'], $id);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'service.recorded', 'auditable_type' => 'service_provided', 'auditable_id' => $service, 'changes' => json_encode(['case_id' => $case, 'service_type' => $data['service']['service_type']]), 'created_at' => now(), 'updated_at' => now()]);
            }
            if (!empty($data['create_follow_up'])) {
                $task = DB::table('tasks')->insertGetId([
                    'case_id' => $case, 'assigned_to' => $request->user()->id, 'title' => $data['follow_up_title'],
                    'due_at' => $data['follow_up_due_at'] ?? null, 'status' => 'open', 'source_type' => 'case_note', 'source_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case_note.follow_up_created', 'auditable_type' => 'task', 'auditable_id' => $task, 'changes' => json_encode(['case_id' => $case, 'source_type' => 'case_note', 'source_id' => $id]), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('cases')->where('id', $case)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case_note.finalized', 'auditable_type' => 'case_note', 'auditable_id' => $id, 'changes' => json_encode(['case_id' => $case, 'note_type' => $data['note_type']]), 'created_at' => now(), 'updated_at' => now()]);
        });
        $done = match ($data['note_type']) {
            'referral' => 'Referral recorded and sent.','service_delivery' => !empty($data['service']) ? 'Service recorded in Services provided.' : 'Progress note finalized.',default => 'Progress note finalized. Use an addendum to correct or supplement it.'
        };

        return back()->with('success', $done);
    }

    public function noteAddendum(Request $request, int $case, int $note)
    {
        $record = DB::table('cases')->find($case);
        abort_unless($record, 404);
        $this->authorize('cases.note');
        $this->caseAccess($request, $record);
        $original = DB::table('case_notes')->where('case_id', $case)->where('id', $note)->whereNotNull('finalized_at')->first();
        abort_unless($original, 422, 'Only a finalized progress note can receive an addendum.');
        $data = $request->validate(['body' => 'required|string|max:5000', 'addendum_reason' => 'required|string|max:2000', 'occurred_at' => 'required|date|before_or_equal:now']);
        $data['occurred_at'] = Carbon::parse($data['occurred_at'])->utc()->format('Y-m-d H:i:s');
        DB::transaction(function () use ($data, $case, $note, $request, $original) {
            $id = DB::table('case_notes')->insertGetId([
                'case_id' => $case, 'body' => $data['body'], 'visibility' => 'CASE_NOTES', 'record_type' => 'addendum',
                'note_type' => $original->note_type ?: 'general_progress', 'occurred_at' => $data['occurred_at'],
                'previous_note_id' => $note, 'addendum_reason' => $data['addendum_reason'], 'finalized_at' => now(),
                'finalized_by' => $request->user()->id, 'is_current' => true, 'created_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('cases')->where('id', $case)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case_note.addendum_finalized', 'auditable_type' => 'case_note', 'auditable_id' => $id, 'changes' => json_encode(['case_id' => $case, 'previous_note_id' => $note]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Progress-note addendum finalized. The original record was preserved.');
    }

    /** The consent recorded at intake: the signed form photo, if any, and its type. */
    private function consentForm(int $case): array
    {
        $row = DB::table('cases')->leftJoin('documents', 'documents.id', '=', 'cases.consent_document_id')->where('cases.id', $case)
            ->first(['cases.consent_document_id', 'cases.consent_form_type', 'documents.name', 'documents.created_at']);

        return ['document_id' => $row?->consent_document_id, 'type' => $row?->consent_form_type, 'name' => $row?->name, 'added_at' => $row?->created_at,
            'sharing' => app(ReferralTracking::class)->hasSharingConsent($case)];
    }

    public function transition(Request $request, int $case)
    {
        $initial = DB::table('cases')->whereNull('deleted_at')->find($case) ?? abort(404);
        $this->caseAccess($request, $initial);
        $data = $request->validate(['status' => 'required|string', 'expected_version' => 'nullable|integer|min:0']);
        $permission = $data['status'] === 'CLOSED' ? 'cases.close' : ($data['status'] === 'REOPENED' ? 'cases.reopen' : 'cases.update');
        $this->authorize($permission);
        DB::transaction(function () use ($data, $case, $request, $permission) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            CaseVersionGuard::check($record, $data);
            $actor = User::query()->where('id', $request->user()->id)->lockForUpdate()->first();
            DB::table('user_lgu_assignments')->where('user_id', $request->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless($actor && $actor->can($permission) && $this->assignments->canRead($actor, $record), 403);
            abort_unless(in_array($data['status'], self::CASE_TRANSITIONS[$record->status] ?? [], true), 422, 'Invalid workflow transition.');
            if ($data['status'] === 'CLOSED') {
                abort_unless(!empty($record->closure_summary) && !empty($record->closure_reviewed_at), 422, 'An independently reviewed closure record is required before closing the case.');
                abort_unless((int) $record->closure_reviewed_by === (int) $actor->id, 403, 'Only the authorized closure reviewer may close this case.');
                abort_if(count($this->lifecyclePolicies->readiness($case)['critical_blockers']) > 0, 422, 'Case closure has policy-configured blockers. Resolve or obtain the authorized policy disposition first.');
            }
            if ($data['status'] === 'CLOSED') {
                app(CaseClosureHistory::class)->close($record, $actor);
            }
            if ($data['status'] === 'REOPENED') {
                app(CaseClosureHistory::class)->reopen($record, $actor);
            }
            DB::table('cases')->where('id', $case)->update(['status' => $data['status'], 'stage' => strtolower(str_replace('_', ' ', $data['status'])), 'closed_at' => $data['status'] === 'CLOSED' ? now() : $record->closed_at, 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            if ($data['status'] === 'REOPENED') {
                // Reopening starts a new planning cycle. It never reactivates a
                // former plan or copies a prior plan's narrative as new facts.
                $prior = DB::table('case_plans')->where('case_id', $case)->orderByDesc('version')->first();
                $plan = DB::table('case_plans')->insertGetId(['case_id' => $case, 'previous_plan_id' => $prior?->id, 'version' => ($prior->version ?? 0) + 1, 'plan_date' => today(), 'summary' => 'Reopened case: planning reassessment required.', 'overall_objective' => 'Reopened case: planning reassessment required.', 'status' => 'draft', 'authored_by' => $actor->id, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('case_plan_history')->insert(['case_plan_id' => $plan, 'event' => 'created_on_case_reopening', 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('case_status_history')->insert(['case_id' => $case, 'from_status' => $record->status, 'to_status' => $data['status'], 'changed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'case.transitioned', 'auditable_type' => 'case', 'auditable_id' => $case, 'changes' => json_encode(['from' => $record->status, 'to' => $data['status']]), 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($this->assignments->canRead($actor, $record), 403);
        }, 3);
        $message = $data['status'] === 'REOPENED' ? 'Case reopened. A new draft planning cycle was created for reassessment.' : 'Case status updated.';
        if ($request->expectsJson() && !$request->header('X-Inertia')) {
            return response()->json(['saved' => true, 'status' => $data['status'], 'version' => (int) DB::table('cases')->where('id', $case)->value('offline_version'), 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
