<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Services\CaseLifecyclePolicy;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Authoritative helping-plan lifecycle. Linked operational records own execution state. */
class CasePlanLifecycleController extends Controller
{
    private const DOMAINS = ['safety_protection', 'health', 'reproductive_health', 'mental_health_psychosocial', 'education', 'family_strengthening', 'economic_social_protection', 'legal_justice', 'child_development', 'community_support', 'reintegration', 'aftercare', 'other'];

    public function __construct(private readonly CaseAccessScope $scope, private readonly CaseLifecyclePolicy $policies) {}

    private function access(Request $r, int $case): object
    {
        $item = DB::table('cases')->whereNull('deleted_at')->find($case) ?? abort(404);
        $this->authorize('cases.view');
        $client = DB::table('clients')->find($item->client_id, ['municipality', 'province']);
        abort_unless($client && $this->scope->allowsCase($item, $r->user(), $client->municipality, $client->province), 403);

        return $item;
    }

    private function plan(int $case, int $plan): object
    {
        return DB::table('case_plans')->where('case_id', $case)->find($plan) ?? abort(404);
    }

    private function history(int $plan, string $event, Request $r, ?string $comments = null): void
    {
        DB::table('case_plan_history')->insert(['case_plan_id' => $plan, 'event' => $event, 'actor_id' => $r->user()->id, 'comments' => $comments, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function audit(Request $r, string $event, int $case, int $id, array $meta = []): void
    {
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => $event, 'auditable_type' => 'case_plan', 'auditable_id' => $id, 'changes' => json_encode(['case_id' => $case] + $meta), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function workspace(Request $r, int $case)
    {
        $record = $this->access($r, $case);
        $client = DB::table('clients')->find($record->client_id, ['municipality']);

        return Inertia::render('Plans/Workspace', ['caseRecord' => ['id' => $record->id, 'case_number' => $record->case_number, 'status' => $record->status], 'currentUserId' => $r->user()->id, 'canManage' => $r->user()->hasPermission('plans.manage'), 'canReview' => $r->user()->hasPermission('plans.review'), 'canTransfer' => $r->user()->hasPermission('transfers.manage'), 'serviceProviders' => $r->user()->hasPermission('plans.manage') ? DB::table('service_providers')->where('active', true)->orderBy('name')->get(['id', 'name', 'service_type']) : [], 'transferCandidates' => $r->user()->hasPermission('transfers.manage') ? User::query()->where('active', true)->where('municipality', $client?->municipality)->whereIn('role', ['social_worker', 'fo_user'])->orderBy('name')->get(['id', 'name', 'role', 'active', 'municipality', 'province'])->filter(fn ($candidate) => $this->handoverReceiverCanRead($candidate, $record))->map(fn ($candidate) => $candidate->only(['id', 'name', 'role']))->values() : []]);
    }

    public function index(Request $r, int $case)
    {
        $this->access($r, $case);
        $plans = DB::table('case_plans')->where('case_id', $case)->orderByDesc('version')->get()->map(fn ($p) => ['plan' => $p, 'goals' => DB::table('case_plan_goals')->where('case_plan_id', $p->id)->get(), 'interventions' => DB::table('interventions')->leftJoin('service_providers as providers', 'providers.id', '=', 'interventions.service_provider_id')->where('interventions.case_plan_id', $p->id)->get(['interventions.*', 'providers.name as provider_name'])->map(fn ($i) => $this->withOperationalStatus($i))]);

        return response()->json(['plans' => $plans, 'aftercare' => DB::table('case_aftercare_plans')->where('case_id', $case)->latest()->get(['id', 'case_plan_id', 'required', 'responsible_office', 'initial_follow_up_at', 'status', 'created_at']), 'reintegration' => DB::table('case_reintegrations')->where('case_id', $case)->latest()->get(['id', 'case_plan_id', 'applicable', 'status', 'responsible_office', 'target_date', 'review_at', 'created_at']), 'transfers' => DB::table('case_transfers')->leftJoin('users as receivers', 'receivers.id', '=', 'case_transfers.receiving_worker_id')->where('case_transfers.case_id', $case)->latest('case_transfers.created_at')->get(['case_transfers.id', 'case_transfers.status', 'case_transfers.originating_office', 'case_transfers.receiving_office', 'case_transfers.reason', 'case_transfers.requested_at', 'case_transfers.endorsed_at', 'case_transfers.accepted_at', 'case_transfers.completed_at', 'receivers.name as receiving_worker_name'])]);
    }

    public function store(Request $r, int $case)
    {
        $this->access($r, $case);
        $this->authorize('plans.manage');
        $d = $r->validate(['plan_date' => 'required|date', 'overall_objective' => 'required|string|max:5000', 'review_due_at' => 'nullable|date', 'previous_plan_id' => 'nullable|integer', 'comprehensive_assessment_id' => 'nullable|integer', 'risk_assessment_id' => 'nullable|integer', 'child_participation_record_id' => 'nullable|integer']);
        $prior = !empty($d['previous_plan_id']) ? DB::table('case_plans')->where('case_id', $case)->whereIn('status', ['active', 'under_review', 'completed'])->find($d['previous_plan_id']) : null;
        abort_if(!empty($d['previous_plan_id']) && !$prior, 422, 'A new version must reference a current or completed plan in this case.');
        foreach (['comprehensive_assessment_id' => ['case_assessments', 'final'], 'risk_assessment_id' => ['risk_assessments', 'final'], 'child_participation_record_id' => ['child_participation_records', 'final']] as $key => [$table,$finalStatus]) {
            if (!empty($d[$key])) {
                abort_unless(DB::table($table)->where('id', $d[$key])->where('case_id', $case)->where('status', $finalStatus)->exists(), 422, 'Plan references must be finalized records from this case.');
            }
        }
        // A new version is only a draft.  Keep the current plan authoritative until
        // an independent reviewer activates the successor.
        $id = DB::transaction(function () use ($d, $case, $r, $prior) {
            return DB::table('case_plans')->insertGetId($d + ['case_id' => $case, 'previous_plan_id' => $prior?->id, 'version' => (DB::table('case_plans')->where('case_id', $case)->max('version') ?? 0) + 1, 'summary' => $d['overall_objective'], 'status' => 'draft', 'authored_by' => $r->user()->id, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->history($id, 'created', $r);
        $this->audit($r, 'case_plan.created', $case, $id, ['version' => DB::table('case_plans')->where('id', $id)->value('version')]);

        return response()->json(['id' => $id], 201);
    }

    public function goal(Request $r, int $case, int $plan)
    {
        $item = $this->plan($case, $plan);
        $this->access($r, $case);
        $this->authorize('plans.manage');
        abort_unless(in_array($item->status, ['draft', 'returned'], true) && (int) $item->authored_by === $r->user()->id, 403);
        $d = $r->validate(['domain' => 'required|in:' . implode(',', self::DOMAINS), 'need_problem' => 'required|string|max:500', 'goal_statement' => 'required|string|max:5000', 'priority' => 'required|in:low,normal,high,urgent', 'target_date' => 'nullable|date']);
        $id = DB::table('case_plan_goals')->insertGetId($d + ['case_plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'case_plan.goal_added', $case, $id, ['plan_id' => $plan]);

        return response()->json(['id' => $id], 201);
    }

    public function intervention(Request $r, int $case, int $plan)
    {
        $item = $this->plan($case, $plan);
        $this->access($r, $case);
        $this->authorize('plans.manage');
        abort_unless(in_array($item->status, ['draft', 'returned'], true) && (int) $item->authored_by === $r->user()->id, 403);
        $d = $r->validate(['case_plan_goal_id' => 'required|integer', 'activity' => 'required|string|max:5000', 'service_category' => 'required|string|max:255', 'responsible_user_id' => 'nullable|exists:users,id', 'responsible_office' => 'nullable|string|max:180', 'service_provider_id' => ['nullable', Rule::exists('service_providers', 'id')->where('active', true)], 'start_date' => 'nullable|date', 'target_date' => 'nullable|date', 'priority' => 'required|in:low,normal,high,urgent', 'expected_outcome' => 'nullable|string|max:5000', 'origin_type' => 'nullable|in:conference_action', 'origin_id' => 'nullable|integer', 'operational_record_type' => 'nullable|in:task,referral,mdt_action,risk_reassessment_request', 'operational_record_id' => 'nullable|integer']);
        abort_unless(DB::table('case_plan_goals')->where('id', $d['case_plan_goal_id'])->where('case_plan_id', $plan)->exists(), 422);
        if (!empty($d['origin_type'])) {
            $this->assertOrigin($case, $d['origin_id'] ?? 0);
        }if (!empty($d['operational_record_type'])) {
            $this->assertOperational($case, $d['operational_record_type'], $d['operational_record_id'] ?? 0);
        }$id = DB::table('interventions')->insertGetId($d + ['case_plan_id' => $plan, 'need' => DB::table('case_plan_goals')->where('id', $d['case_plan_goal_id'])->value('need_problem'), 'goal' => DB::table('case_plan_goals')->where('id', $d['case_plan_goal_id'])->value('goal_statement'), 'progress' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'case_plan.intervention_added', $case, $id, ['plan_id' => $plan]);

        return response()->json(['id' => $id], 201);
    }

    public function updateIntervention(Request $r, int $case, int $plan, int $intervention)
    {
        $this->access($r, $case);
        $this->authorize('plans.manage');
        DB::transaction(function () use ($r, $case, $plan, $intervention) {
            $caseRecord = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $item = DB::table('case_plans')->where('case_id', $case)->where('id', $plan)->lockForUpdate()->first() ?? abort(404);
            $record = DB::table('interventions')->where('id', $intervention)->where('case_plan_id', $plan)->lockForUpdate()->first() ?? abort(404);
            $actor = User::query()->where('id', $r->user()->id)->lockForUpdate()->first();
            DB::table('user_lgu_assignments')->where('user_id', $r->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $eligibility = app(CaseAssignmentEligibility::class);
            abort_unless($actor && $actor->hasPermission('plans.manage') && $eligibility->canRead($actor, $caseRecord), 403);
            abort_unless($item->status === 'active' && (int) $item->authored_by === $actor->id, 403);
            $d = $r->validate(['progress' => 'nullable|in:not_started,in_progress,completed,on_hold,discontinued', 'actual_outcome' => 'nullable|string|max:5000', 'outcome_status' => 'nullable|in:achieved,partially_achieved,not_achieved,unable_to_determine,discontinued,service_unavailable', 'barriers' => 'nullable|string|max:5000', 'next_action' => 'nullable|string|max:5000']);
            if ($record->operational_record_type && array_key_exists('progress', $d)) {
                abort(422, 'Execution status is owned by the linked operational record. Record outcomes or barriers here instead.');
            }
            DB::table('interventions')->where('id', $intervention)->update($d + ['completed_at' => ($d['progress'] ?? null) === 'completed' ? now() : $record->completed_at, 'updated_at' => now()]);
            $this->audit($r, 'case_plan.intervention_updated', $case, $intervention, ['plan_id' => $plan, 'fields' => array_keys($d)]);
            abort_unless($eligibility->canRead($actor, $caseRecord), 403);
        }, 3);

        return response()->noContent();
    }

    public function transition(Request $r, int $case, int $plan)
    {
        $item = $this->plan($case, $plan);
        $this->access($r, $case);
        $d = $r->validate(['status' => 'required|in:for_review,returned,active,under_review,completed', 'comments' => 'nullable|string|max:5000']);
        $to = $d['status'];

        if ($to === 'for_review') {
            $this->authorize('plans.manage');
            abort_unless(in_array($item->status, ['draft', 'returned'], true) && (int) $item->authored_by === $r->user()->id, 422);
        } elseif ($to === 'returned') {
            $this->authorize('plans.review');
            abort_unless($item->status === 'for_review' && (int) $item->authored_by !== $r->user()->id && !empty($d['comments']), 422);
            DB::table('case_plans')->where('id', $plan)->update(['status' => 'returned', 'returned_by' => $r->user()->id, 'returned_at' => now(), 'review_comments' => $d['comments'], 'updated_at' => now()]);
            $this->history($plan, $to, $r, $d['comments']);

            return response()->noContent();
        } elseif ($to === 'active') {
            $this->authorize('plans.review');
            abort_unless($item->status === 'for_review' && (int) $item->authored_by !== $r->user()->id, 422);
            DB::transaction(function () use ($case, $plan, $r) {
                DB::table('case_plans')->where('case_id', $case)->where('is_current', true)->where('id', '!=', $plan)->update(['status' => 'superseded', 'is_current' => false, 'updated_at' => now()]);
                DB::table('case_plans')->where('id', $plan)->update(['status' => 'active', 'is_current' => true, 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            });
            $this->history($plan, 'activated', $r);
            $this->audit($r, 'case_plan.activated', $case, $plan);

            return response()->noContent();
        } elseif ($to === 'under_review') {
            $this->authorize('plans.manage');
            abort_unless($item->status === 'active' && (int) $item->authored_by === $r->user()->id, 422);
        } else {
            $this->authorize('plans.review');
            abort_unless($item->status === 'under_review' && (int) $item->authored_by !== $r->user()->id, 422);
        }

        DB::table('case_plans')->where('id', $plan)->update(['status' => $to, 'updated_at' => now()]);
        $this->history($plan, $to, $r, $d['comments'] ?? null);

        return response()->noContent();
    }

    public function review(Request $r, int $case, int $plan)
    {
        $item = $this->plan($case, $plan);
        $this->access($r, $case);
        $this->authorize('plans.review');
        abort_unless($item->status === 'under_review' && (int) $item->authored_by !== $r->user()->id, 422);
        $d = $r->validate(['review_date' => 'required|date', 'overall_progress' => 'required|in:on_track,delayed,blocked,completed', 'changed_needs' => 'nullable|string|max:5000', 'barriers' => 'nullable|string|max:5000', 'continuing_concerns' => 'nullable|string|max:5000', 'outcome_observations' => 'nullable|string|max:5000', 'recommendation' => 'nullable|string|max:5000', 'revision_required' => 'required|boolean', 'next_review_at' => 'nullable|date']);
        $id = DB::table('case_plan_reviews')->insertGetId($d + ['case_plan_id' => $plan, 'reviewed_by' => $r->user()->id, 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_plans')->where('id', $plan)->update(['review_due_at' => $d['next_review_at'] ?? $item->review_due_at, 'status' => $d['revision_required'] ? 'under_review' : 'active', 'updated_at' => now()]);
        $this->history($plan, 'review_finalized', $r);
        $this->audit($r, 'case_plan.review_finalized', $case, $id, ['plan_id' => $plan]);

        return response()->json(['id' => $id], 201);
    }

    public function aftercare(Request $r, int $case)
    {
        $this->access($r, $case);
        $this->authorize('plans.manage');
        $d = $r->validate(['case_plan_id' => 'nullable|integer', 'required' => 'required|boolean', 'basis' => 'nullable|string|max:5000', 'responsible_user_id' => 'nullable|exists:users,id', 'responsible_office' => 'nullable|string|max:180', 'initial_follow_up_at' => 'nullable|date', 'continuity_notes' => 'nullable|string|max:5000']);
        if (!empty($d['case_plan_id'])) {
            abort_unless(DB::table('case_plans')->where('case_id', $case)->where('id', $d['case_plan_id'])->exists(), 422);
        }$id = DB::table('case_aftercare_plans')->insertGetId($d + ['case_id' => $case, 'status' => $d['required'] ? 'planned' : 'not_required', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'case_aftercare.recorded', $case, $id);

        return response()->json(['id' => $id], 201);
    }

    public function reintegration(Request $r, int $case)
    {
        $this->access($r, $case);
        $this->authorize('plans.manage');
        $d = $r->validate(['case_plan_id' => 'nullable|integer', 'applicable' => 'required|boolean', 'basis' => 'nullable|string|max:5000', 'child_participation_record_id' => 'nullable|integer', 'risk_assessment_id' => 'nullable|integer', 'continuity_plan' => 'nullable|string|max:5000', 'safeguards' => 'nullable|string|max:5000', 'responsible_office' => 'nullable|string|max:180', 'target_date' => 'nullable|date', 'review_at' => 'nullable|date']);
        foreach (['case_plan_id' => 'case_plans', 'child_participation_record_id' => 'child_participation_records', 'risk_assessment_id' => 'risk_assessments'] as $key => $table) {
            if (!empty($d[$key])) {
                abort_unless(DB::table($table)->where('id', $d[$key])->where('case_id', $case)->exists(), 422, 'Referenced record must belong to this case.');
            }
        }$id = DB::table('case_reintegrations')->insertGetId($d + ['case_id' => $case, 'status' => $d['applicable'] ? 'planned' : 'not_applicable', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'case_reintegration.recorded', $case, $id);

        return response()->json(['id' => $id], 201);
    }

    public function closureReadiness(Request $r, int $case)
    {
        $this->access($r, $case);
        $plan = DB::table('case_plans')->where('case_id', $case)->where('is_current', true)->first();
        $aftercare = DB::table('case_aftercare_plans')->where('case_id', $case)->latest()->first(['required', 'status']);
        $readiness = $this->policies->readiness($case);
        if (!$plan) {
            $readiness['warnings'][] = ['code' => 'no_active_plan'];
        }

        return response()->json($readiness + ['aftercare' => $aftercare, 'active_plan' => $plan ? collect($plan)->only(['id', 'version', 'status', 'review_due_at']) : null]);
    }

    public function transfer(Request $r, int $case)
    {
        $record = $this->access($r, $case);
        $this->authorize('transfers.manage');
        $d = $r->validate(['originating_office' => 'required|string|max:180', 'receiving_office' => 'required|string|max:180', 'receiving_worker_id' => 'nullable|exists:users,id', 'reason' => 'required|string|max:5000', 'new_locality' => 'nullable|string|max:255', 'continuity_notes' => 'nullable|string|max:5000']);
        if (!empty($d['receiving_worker_id'])) {
            $receiver = User::query()->where('id', $d['receiving_worker_id'])->where('active', true)->first();
            abort_unless($receiver && in_array($receiver->role, ['social_worker', 'fo_user'], true), 422, 'Receiving worker must be an active casework user.');
            $client = DB::table('clients')->find($record->client_id, ['municipality']);
            abort_unless($receiver->municipality === $client?->municipality, 422, 'Cross-LGU transfer remains disabled until the owner approves an atomic jurisdiction-handoff policy.');
            if (!$this->handoverReceiverCanRead($receiver, $record)) {
                throw ValidationException::withMessages(['receiving_worker_id' => 'Choose a receiving worker with current access to this case.']);
            }
        }$id = DB::table('case_transfers')->insertGetId($d + ['case_id' => $case, 'originating_worker_id' => $record->assigned_to, 'created_by' => $r->user()->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_transfer_history')->insert(['case_transfer_id' => $id, 'event' => 'created', 'actor_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'case_transfer.created', $case, $id);

        return response()->json(['id' => $id], 201);
    }

    public function transferTransition(Request $r, int $case, int $transfer)
    {
        $this->access($r, $case);
        $this->authorize('transfers.manage');
        $d = $r->validate(['status' => 'required|in:requested,endorsed,accepted,completed,returned_for_clarification,cancelled', 'comments' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($r, $case, $transfer, $d) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $item = DB::table('case_transfers')->where('case_id', $case)->where('id', $transfer)->lockForUpdate()->first() ?? abort(404);
            $ids = array_filter([$r->user()->id, $item->receiving_worker_id]);
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            DB::table('user_lgu_assignments')->whereIn('user_id', $ids)->orderBy('user_id')->orderBy('id')->lockForUpdate()->get(['id']);
            $eligibility = app(CaseAssignmentEligibility::class);
            $actor = $users->get($r->user()->id);
            abort_unless($actor && $actor->hasPermission('transfers.manage') && $eligibility->canRead($actor, $record), 403);
            $allowed = ['draft' => ['requested', 'cancelled'], 'requested' => ['endorsed', 'returned_for_clarification', 'cancelled'], 'endorsed' => ['accepted', 'returned_for_clarification', 'cancelled'], 'accepted' => ['completed', 'cancelled'], 'returned_for_clarification' => ['requested', 'cancelled']];
            abort_unless(in_array($d['status'], $allowed[$item->status] ?? [], true), 422, 'Invalid transfer transition.');
            $changes = ['status' => $d['status'], 'updated_at' => now()];
            $timestamp = ['requested' => 'requested_at', 'endorsed' => 'endorsed_at', 'accepted' => 'accepted_at', 'completed' => 'completed_at'][$d['status']] ?? null;
            if ($timestamp) {
                $changes[$timestamp] = now();
            }
            $receiver = null;
            if ($d['status'] === 'completed') {
                $receiver = $users->get($item->receiving_worker_id);
                if (!$receiver || !$this->handoverReceiverCanRead($receiver, $record)) {
                    throw ValidationException::withMessages(['receiving_worker_id' => 'The receiving worker no longer has current case access. Review the handover before retrying.']);
                }
                DB::table('cases')->where('id', $case)->update(['assigned_to' => $receiver->id, 'updated_at' => now(), 'offline_version' => DB::raw('offline_version + 1')]);
            }
            if ($d['status'] === 'returned_for_clarification') {
                $changes['clarification_comments'] = $d['comments'] ?? null;
            }
            DB::table('case_transfers')->where('id', $transfer)->update($changes);
            DB::table('case_transfer_history')->insert(['case_transfer_id' => $transfer, 'event' => $d['status'], 'actor_id' => $actor->id, 'comments' => $d['comments'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, 'case_transfer.' . $d['status'], $case, $transfer);
            abort_unless($eligibility->canRead($actor, $record), 403);
            if ($receiver && !$this->handoverReceiverCanRead($receiver, $record)) {
                throw ValidationException::withMessages(['receiving_worker_id' => 'The receiving worker no longer has current case access. Review the handover before retrying.']);
            }
        }, 3);

        return response()->noContent();
    }

    private function handoverReceiverCanRead(User $receiver, object $record): bool
    {
        // Preserve existing handover roles and the cross-jurisdiction policy;
        // a matching residence label alone does not establish current access.
        if (!in_array($receiver->role, ['social_worker', 'fo_user'], true)) {
            return false;
        }
        $client = DB::table('clients')->where('id', $record->client_id)->first(['municipality']);
        if ($receiver->municipality !== $client?->municipality) {
            return false;
        }
        $proposed = clone $record;
        $proposed->assigned_to = $receiver->id;

        return app(CaseAssignmentEligibility::class)->canRead($receiver, $proposed);
    }

    private function assertOrigin(int $case, int $id): void
    {
        abort_unless(DB::table('case_conference_actions')->join('case_conferences', 'case_conferences.id', '=', 'case_conference_actions.case_conference_id')->where('case_conferences.case_id', $case)->where('case_conference_actions.id', $id)->exists(), 422, 'Conference action origin must belong to this case.');
    }

    private function assertOperational(int $case, string $type, int $id): void
    {
        $table = match ($type) {
            'task','risk_reassessment_request' => 'tasks','referral' => 'referrals','mdt_action' => 'mdt_actions'
        };
        abort_unless(DB::table($table)->where('id', $id)->where('case_id', $case)->exists(), 422, 'Linked operational record must belong to this case.');
    }

    private function withOperationalStatus(object $item): object
    {
        if (!$item->operational_record_type) {
            return $item;
        }$table = match ($item->operational_record_type) {
            'task','risk_reassessment_request' => 'tasks','referral' => 'referrals','mdt_action' => 'mdt_actions'
        };
        $item->operational_status = DB::table($table)->where('id', $item->operational_record_id)->value('status');

        return $item;
    }
}
