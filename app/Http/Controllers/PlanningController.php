<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Services\CaseClosureHistory;
use App\Services\CaseVersionGuard;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanningController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    private function assessmentResponse(Request $request, string $message, array $acknowledgement, int $status = 200)
    {
        if ($request->expectsJson() && !$request->headers->has('X-Inertia')) {
            return response()->json($acknowledgement, $status);
        }

        return back()->with('success', $message);
    }

    private function access(Request $request, int $caseId): void
    {
        $case = DB::table('cases')->find($caseId);
        abort_unless($case, 404);
        $this->authorize('cases.view');
        $location = DB::table('clients')->find($case->client_id, ['municipality', 'province']);
        abort_unless($location && $this->scope->allowsCase($case, $request->user(), $location->municipality, $location->province), 403);
    }

    public function assessment(Request $request, int $case)
    {
        $this->access($request, $case);
        $data = $request->validate(['summary' => 'nullable|string|max:5000', 'responses' => 'nullable|array', 'status' => 'required|in:draft,completed', 'version' => 'nullable|integer']);
        $current = DB::table('assessments')->where('case_id', $case)->latest()->first();
        if ($current && isset($data['version']) && $data['version'] != $current->version) {
            return response()->json(['message' => 'Assessment conflict. Refresh before saving.'], 409);
        }
        $id = DB::table('assessments')->insertGetId(['case_id' => $case, 'status' => $data['status'], 'summary' => $data['summary'] ?? null, 'responses' => json_encode($data['responses'] ?? []), 'completed_by' => $data['status'] === 'completed' ? $request->user()->id : null, 'completed_at' => $data['status'] === 'completed' ? now() : null, 'version' => ($current->version ?? 0) + 1, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'assessment.saved', 'auditable_type' => 'assessment', 'auditable_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Assessment saved.');
    }

    /** A repeatable professional safety assessment; it deliberately does not calculate a risk score. */
    public function riskAssessment(Request $request, int $case)
    {
        $this->access($request, $case);
        $this->authorize('risk.assess');
        $data = $request->validate([
            'assessment_type' => 'required|in:initial,reassessment,emergency,pre_closure,other',
            'assessed_at' => 'required|date|before_or_equal:now',
            'overall_risk_level' => 'required|in:low,moderate,high,critical',
            'immediate_protection_required' => 'required|boolean',
            'safety_plan_status' => 'required|in:not_required,required,current',
            'required_action' => 'nullable|string|max:5000',
            'responsible_person' => 'nullable|string|max:255',
            'target_date' => 'nullable|date',
            'next_reassessment_at' => 'nullable|date|after_or_equal:assessed_at',
            'professional_rationale' => 'nullable|string|max:5000',
            'status' => 'required|in:draft,final',
            'factors' => 'nullable|array|max:30',
            'factors.*.domain' => 'required|string|max:80',
            'factors.*.finding_level' => 'required|in:not_assessed,no_concern,concern,high_concern',
            'factors.*.is_protective_factor' => 'required|boolean',
            'factors.*.notes' => 'nullable|string|max:2000',
        ]);
        if (in_array($data['overall_risk_level'], ['high', 'critical'], true)) {
            if (!$data['immediate_protection_required'] && empty($data['required_action'])) {
                throw ValidationException::withMessages(['required_action' => 'Record immediate protection needs or an action for high or critical risk.']);
            }
        }

        $id = DB::transaction(function () use ($data, $case, $request) {
            $id = DB::table('risk_assessments')->insertGetId(collect($data)->except('factors')->merge([
                'case_id' => $case,
                'assessed_by' => $request->user()->id,
                'finalized_by' => $data['status'] === 'final' ? $request->user()->id : null,
                'finalized_at' => $data['status'] === 'final' ? now() : null,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
            foreach ($data['factors'] ?? [] as $factor) {
                DB::table('risk_assessment_factors')->insert($factor + ['risk_assessment_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($data['status'] === 'final') {
                // Current risk is derived from the latest finalized professional assessment, never a draft or insertion order.
                $current = DB::table('risk_assessments')->where('case_id', $case)->where('status', 'final')->orderByDesc('assessed_at')->orderByDesc('id')->first();
                DB::table('cases')->where('id', $case)->update(['current_risk_level' => $current->overall_risk_level, 'current_risk_assessed_at' => $current->assessed_at, 'last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            }
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'risk_assessment.' . $data['status'], 'auditable_type' => 'risk_assessment', 'auditable_id' => $id, 'changes' => json_encode(['case_id' => $case, 'assessment_type' => $data['assessment_type'], 'risk_level' => $data['overall_risk_level']]), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        return back()->with('success', $data['status'] === 'final' ? 'Safety and risk assessment finalized.' : 'Safety and risk assessment draft saved.');
    }

    public function comprehensiveAssessment(Request $request, int $case)
    {
        $this->access($request, $case);
        $this->authorize('assessments.manage');
        $data = $request->validate(['assessment_date' => 'required|date|before_or_equal:today', 'previous_assessment_id' => 'nullable|integer', 'record_type' => 'required|in:full_revision,addendum', 'amendment_reason' => 'nullable|string|max:2000']);
        $previousId = $data['previous_assessment_id'] ?? null;
        $prior = $previousId ? DB::table('case_assessments')->where('case_id', $case)->where('status', 'final')->where('id', $previousId)->first() : null;
        abort_if($previousId && !$prior, 422, 'Choose a finalized assessment to revise or supplement.');
        abort_if(!$prior && DB::table('case_assessments')->where('case_id', $case)->exists(), 422, 'Choose a finalized assessment as the source for a new version or addendum.');
        abort_if(!$prior && $data['record_type'] === 'addendum', 422, 'An addendum must be attached to a finalized assessment.');
        abort_if($prior && empty($data['amendment_reason']), 422, 'State why this finalized assessment is being revised or supplemented.');
        $id = DB::transaction(function () use ($case, $request, $data, $prior) {
            $id = DB::table('case_assessments')->insertGetId(['case_id' => $case, 'previous_assessment_id' => $prior?->id, 'version' => (DB::table('case_assessments')->where('case_id', $case)->max('version') ?? 0) + 1, 'record_type' => $data['record_type'], 'amendment_reason' => $prior ? $data['amendment_reason'] : null, 'assessment_date' => $data['assessment_date'], 'status' => 'draft', 'authored_by' => $request->user()->id, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);
            if ($prior && $data['record_type'] === 'full_revision') {
                foreach (DB::table('case_assessment_sections')->where('case_assessment_id', $prior->id)->get() as $section) {
                    DB::table('case_assessment_sections')->insert(['case_assessment_id' => $id, 'section_key' => $section->section_key, 'completion_state' => $section->completion_state, 'source_type' => $section->source_type, 'facts' => $section->facts, 'reported_information' => $section->reported_information, 'observations' => $section->observations, 'analysis' => $section->analysis, 'conflicting_information' => $section->conflicting_information, 'restricted' => $section->restricted, 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return $id;
        });
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'comprehensive_assessment.created', 'auditable_type' => 'case_assessment', 'auditable_id' => $id, 'changes' => json_encode(['case_id' => $case, 'version' => ($prior->version ?? 0) + 1]), 'created_at' => now(), 'updated_at' => now()]);

        return $this->assessmentResponse($request, 'Comprehensive assessment draft created.', ['id' => $id, 'status' => 'draft'], 201);
    }

    public function saveAssessmentSection(Request $request, int $case, int $assessment)
    {
        $this->access($request, $case);
        $this->authorize('assessments.manage');
        $record = DB::table('case_assessments')->where('id', $assessment)->where('case_id', $case)->first();
        abort_unless($record && $record->status === 'draft' && (int) $record->authored_by === $request->user()->id, 422, 'Only the author may save sections while this assessment is a draft.');
        $d = $request->validate(['section_key' => 'required|in:presenting_situation,family_relationships,cefmu_context,economic_education,health_srh,psychosocial_environment,strengths_support,perspectives,professional_assessment,case_formulation,recommendations', 'completion_state' => 'required|in:completed,partially_completed,not_applicable,deferred,information_unavailable', 'source_type' => 'nullable|in:child,parent_caregiver,household_member,school,health_provider,barangay_lgu,law_enforcement,service_provider,document_record,worker_observation,other', 'facts' => 'nullable|string|max:5000', 'reported_information' => 'nullable|string|max:5000', 'observations' => 'nullable|string|max:5000', 'analysis' => 'nullable|string|max:5000', 'conflicting_information' => 'nullable|string|max:5000', 'restricted' => 'required|boolean']);
        abort_if($record->record_type === 'addendum' && $d['section_key'] !== 'professional_assessment', 422, 'An addendum may only contain the supplementary professional assessment note.');
        abort_if($d['restricted'] && !$request->user()->hasPermission('assessments.restricted_view'), 403);
        DB::table('case_assessment_sections')->updateOrInsert(['case_assessment_id' => $assessment, 'section_key' => $d['section_key']], $d + ['updated_at' => now(), 'created_at' => now()]);

        return $this->assessmentResponse($request, 'Assessment section saved.', ['status' => 'saved']);
    }

    public function showComprehensiveAssessment(Request $request, int $case, int $assessment)
    {
        $this->access($request, $case);
        $record = DB::table('case_assessments')->leftJoin('users as authors', 'authors.id', '=', 'case_assessments.authored_by')->leftJoin('users as reviewers', 'reviewers.id', '=', 'case_assessments.reviewed_by')->leftJoin('users as returners', 'returners.id', '=', 'case_assessments.returned_by')->where('case_assessments.id', $assessment)->where('case_assessments.case_id', $case)->first(['case_assessments.*', 'authors.name as author_name', 'authors.role as author_role', 'reviewers.name as reviewer_name', 'reviewers.role as reviewer_role', 'returners.name as returned_by_name', 'returners.role as returned_by_role']);
        abort_unless($record, 404);
        $can = $request->user()->hasPermission('assessments.restricted_view');
        $sections = DB::table('case_assessment_sections')->where('case_assessment_id', $assessment)->get()->map(fn ($s) => $s->restricted && !$can ? ['section_key' => $s->section_key, 'completion_state' => $s->completion_state, 'restricted' => true, 'placeholder' => 'Restricted section'] : $s);
        $history = DB::table('case_assessment_workflow_events')->leftJoin('users', 'users.id', '=', 'case_assessment_workflow_events.actor_id')->where('case_assessment_id', $assessment)->orderBy('case_assessment_workflow_events.created_at')->get(['case_assessment_workflow_events.event', 'case_assessment_workflow_events.comments', 'case_assessment_workflow_events.created_at', 'users.name as actor_name', 'users.role as actor_role']);

        return response()->json(['assessment' => $record, 'sections' => $sections, 'history' => $history, 'allowed_actions' => ['save_sections' => $record->status === 'draft' && (int) $record->authored_by === $request->user()->id, 'submit' => $record->status === 'draft' && (int) $record->authored_by === $request->user()->id, 'review' => $record->status === 'for_review' && (int) $record->authored_by !== $request->user()->id && $request->user()->hasPermission('assessments.review'), 'return' => $record->status === 'for_review' && (int) $record->authored_by !== $request->user()->id && $request->user()->hasPermission('assessments.review'), 'finalize' => $record->status === 'reviewed' && (int) $record->authored_by !== $request->user()->id && $request->user()->hasPermission('assessments.review'), 'new_version' => $record->status === 'final' && $request->user()->hasPermission('assessments.manage')]]);
    }

    public function indexComprehensiveAssessments(Request $request, int $case)
    {
        $this->access($request, $case);

        return response()->json(['assessments' => DB::table('case_assessments')->leftJoin('users as authors', 'authors.id', '=', 'case_assessments.authored_by')->leftJoin('users as reviewers', 'reviewers.id', '=', 'case_assessments.reviewed_by')->leftJoin('users as returners', 'returners.id', '=', 'case_assessments.returned_by')->where('case_assessments.case_id', $case)->orderByDesc('case_assessments.version')->get(['case_assessments.id', 'case_assessments.previous_assessment_id', 'case_assessments.version', 'case_assessments.record_type', 'case_assessments.amendment_reason', 'case_assessments.status', 'case_assessments.assessment_date', 'case_assessments.is_current', 'case_assessments.reviewed_at', 'case_assessments.finalized_at', 'case_assessments.returned_at', 'authors.name as author_name', 'reviewers.name as reviewer_name', 'returners.name as returned_by_name'])]);
    }

    public function transitionComprehensiveAssessment(Request $request, int $case, int $assessment)
    {
        $this->access($request, $case);
        $record = DB::table('case_assessments')->where('id', $assessment)->where('case_id', $case)->first();
        abort_unless($record, 404);
        $input = $request->validate(['status' => 'required|in:for_review,reviewed,returned_for_revision,final', 'review_comments' => 'nullable|string|max:5000']);
        $to = $input['status'];
        $comment = $input['review_comments'] ?? null;
        if ($to === 'for_review') {
            $this->authorize('assessments.manage');
            abort_unless($record->status === 'draft' && (int) $record->authored_by === $request->user()->id, 422);
            DB::table('case_assessments')->where('id', $assessment)->update(['status' => 'for_review', 'updated_at' => now()]);
        } elseif ($to === 'reviewed') {
            $this->authorize('assessments.review');
            abort_unless($record->status === 'for_review' && (int) $record->authored_by !== $request->user()->id, 403, 'A different authorized reviewer is required.');
            DB::table('case_assessments')->where('id', $assessment)->update(['status' => 'reviewed', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_comments' => $comment, 'updated_at' => now()]);
        } elseif ($to === 'returned_for_revision') {
            $this->authorize('assessments.review');
            abort_unless($record->status === 'for_review' && (int) $record->authored_by !== $request->user()->id && !empty($comment), 422, 'A different reviewer and comments are required.');
            DB::table('case_assessments')->where('id', $assessment)->update(['status' => 'draft', 'review_comments' => $comment, 'returned_by' => $request->user()->id, 'returned_at' => now(), 'updated_at' => now()]);
        } else {
            $this->authorize('assessments.review');
            abort_unless($record->status === 'reviewed' && (int) $record->authored_by !== $request->user()->id, 403, 'A different authorized reviewer or supervisor must finalize this assessment.');
            DB::transaction(function () use ($case, $assessment, $request, $record) {
                $latest = DB::table('case_assessments')->where('case_id', $case)->where('status', 'final')->orderByDesc('assessment_date')->orderByDesc('id')->first();
                if (!$latest || $record->assessment_date >= $latest->assessment_date) {
                    DB::table('case_assessments')->where('case_id', $case)->update(['is_current' => false, 'updated_at' => now()]);
                }DB::table('case_assessments')->where('id', $assessment)->update(['status' => 'final', 'finalized_by' => $request->user()->id, 'finalized_at' => now(), 'is_current' => !$latest || $record->assessment_date >= $latest->assessment_date, 'updated_at' => now()]);
            });
        }DB::table('case_assessment_workflow_events')->insert(['case_assessment_id' => $assessment, 'event' => $to, 'actor_id' => $request->user()->id, 'comments' => $comment, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'comprehensive_assessment.' . $to, 'auditable_type' => 'case_assessment', 'auditable_id' => $assessment, 'changes' => json_encode(['case_id' => $case]), 'created_at' => now(), 'updated_at' => now()]);

        return $this->assessmentResponse($request, 'Assessment status updated.', ['status' => $to]);
    }

    public function plan(Request $request, int $case)
    {
        $this->access($request, $case);
        $this->authorize('plans.manage');
        $data = $request->validate(['summary' => 'nullable|string|max:5000', 'review_due_at' => 'nullable|date', 'interventions' => 'nullable|array']);

        return DB::transaction(function () use ($data, $case, $request) {
            // Deprecated compatibility route. It creates a governed draft rather
            // than silently replacing an active plan from the old one-screen form.
            $plan = DB::table('case_plans')->insertGetId(['case_id' => $case, 'summary' => $data['summary'] ?? null, 'overall_objective' => $data['summary'] ?? null, 'plan_date' => today(), 'review_due_at' => $data['review_due_at'] ?? null, 'version' => (DB::table('case_plans')->where('case_id', $case)->max('version') ?? 0) + 1, 'authored_by' => $request->user()->id, 'is_current' => false, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['interventions'] ?? [] as $intervention) {
                validator($intervention, ['need' => 'required|string|max:255', 'goal' => 'required|string|max:255', 'service_category' => 'required|string|max:255', 'target_date' => 'nullable|date'])->validate();
                $goal = DB::table('case_plan_goals')->insertGetId(['case_plan_id' => $plan, 'domain' => 'other', 'need_problem' => $intervention['need'], 'goal_statement' => $intervention['goal'], 'priority' => 'normal', 'target_date' => $intervention['target_date'] ?? null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('interventions')->insert($intervention + ['case_plan_id' => $plan, 'case_plan_goal_id' => $goal, 'activity' => $intervention['goal'], 'priority' => 'normal', 'progress' => 'not_started', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('case_plan_history')->insert(['case_plan_id' => $plan, 'event' => 'created_from_legacy_compatibility_route', 'actor_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'case_plan.created', 'auditable_type' => 'case_plan', 'auditable_id' => $plan, 'changes' => json_encode(['case_id' => $case, 'compatibility_route' => true]), 'created_at' => now(), 'updated_at' => now()]);

            return redirect()->route('cases.plans.workspace', $case)->with('success', 'Draft plan created. Complete and submit it for independent review.');
        });
    }

    /** Case visibility alone never grants consent or relationship write authority. */
    private function contextWrite(Request $request, int $case, string $permission, callable $write): void
    {
        $this->authorize($permission);
        $this->access($request, $case);
        DB::transaction(function () use ($request, $case, $permission, $write) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $actor = User::query()->where('id', $request->user()->id)->lockForUpdate()->first();
            DB::table('user_lgu_assignments')->where('user_id', $request->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $eligibility = app(CaseAssignmentEligibility::class);
            abort_unless($actor && $actor->can($permission) && $eligibility->canRead($actor, $record), 403);
            $write($record, $actor);
            abort_unless($eligibility->canRead($actor, $record), 403);
        }, 3);
    }

    public function consent(Request $request, int $case)
    {
        $this->authorize('consents.record');
        $this->access($request, $case);
        $data = $request->validate(['lawful_basis' => 'required|string|max:255', 'purposes' => 'required|array|min:1', 'purposes.*' => 'string|max:255', 'sharing_allowed' => 'required|boolean', 'granted_at' => 'required|date']);
        $this->contextWrite($request, $case, 'consents.record', function ($record, $actor) use ($data, $case) {
            $id = DB::table('consents')->insertGetId(['case_id' => $case, 'lawful_basis' => $data['lawful_basis'], 'purposes' => json_encode($data['purposes']), 'sharing_allowed' => $data['sharing_allowed'], 'granted_at' => $data['granted_at'], 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'consent.recorded', 'auditable_type' => 'consent', 'auditable_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Consent record saved.');
    }

    public function withdrawConsent(Request $request, int $case, int $consent)
    {
        $this->contextWrite($request, $case, 'consents.record', function ($record, $actor) use ($case, $consent) {
            $entry = DB::table('consents')->where('id', $consent)->where('case_id', $case)->whereNull('withdrawn_at')->lockForUpdate()->first();
            abort_unless($entry, 404);
            DB::table('consents')->where('id', $consent)->update(['withdrawn_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'consent.withdrawn', 'auditable_type' => 'consent', 'auditable_id' => $consent, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Consent withdrawn.');
    }

    public function relationship(Request $request, int $case)
    {
        $this->authorize('relationships.manage');
        $this->access($request, $case);
        $data = $request->validate(['name' => 'required|string|max:255', 'relationship_type' => 'required|string|max:100', 'phone' => 'nullable|string|max:80', 'is_guardian' => 'required|boolean']);
        $this->contextWrite($request, $case, 'relationships.manage', function ($record, $actor) use ($data) {
            $id = DB::table('relationships')->insertGetId($data + ['client_id' => $record->client_id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'relationship.recorded', 'auditable_type' => 'relationship', 'auditable_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Client relationship recorded.');
    }

    public function closure(Request $request, int $case)
    {
        $this->authorize('cases.update');
        $this->access($request, $case);
        $data = $request->validate(['closure_summary' => 'required|string|max:5000', 'expected_version' => 'sometimes|required|integer|min:1']);
        $this->contextWrite($request, $case, 'cases.update', function ($record, $actor) use ($case, $data) {
            CaseVersionGuard::check($record, $data);
            abort_unless($record->status === 'FOR_CLOSURE_REVIEW', 422, 'Closure records may be completed only during closure review.');
            if (!app(CaseClosureHistory::class)->record($record, $actor, $data['closure_summary'])) {
                return;
            }
            DB::table('cases')->where('id', $case)->update(['closure_summary' => $data['closure_summary'], 'closed_at' => null, 'closure_reviewed_by' => null, 'closure_reviewed_at' => null, 'retention_review_at' => now()->addYear(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'case.closure_recorded', 'auditable_type' => 'case', 'auditable_id' => $case, 'created_at' => now(), 'updated_at' => now()]);
        });

        return $this->assessmentResponse($request, 'Closure record saved. It now requires an independent authorized review.', ['saved' => true]);
    }

    public function reviewClosure(Request $request, int $case)
    {
        $data = $request->validate(['expected_version' => 'sometimes|required|integer|min:1']);
        $this->contextWrite($request, $case, 'cases.close', function ($record, $actor) use ($case, $data) {
            CaseVersionGuard::check($record, $data);
            abort_unless($record->status === 'FOR_CLOSURE_REVIEW' && !empty($record->closure_summary), 422, 'A closure record is required before review.');
            $author = DB::table('audit_logs')->where('event', 'case.closure_recorded')->where('auditable_type', 'case')->where('auditable_id', $case)->latest('created_at')->orderByDesc('id')->value('user_id');
            abort_unless(!$author || (int) $author !== (int) $actor->id, 422, 'The closure record must be reviewed by another authorized user.');
            app(CaseClosureHistory::class)->review($record, $actor);
            DB::table('cases')->where('id', $case)->update(['closure_reviewed_by' => $actor->id, 'closure_reviewed_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'case.closure_reviewed', 'auditable_type' => 'case', 'auditable_id' => $case, 'created_at' => now(), 'updated_at' => now()]);
        });

        return $this->assessmentResponse($request, 'Closure reviewed. The reviewer may now close the case.', ['saved' => true]);
    }
}
