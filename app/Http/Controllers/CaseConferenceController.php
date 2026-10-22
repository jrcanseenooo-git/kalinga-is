<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Support\AuditLog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Formal multidisciplinary decision record; never a substitute for Risk or Child Participation records. */
class CaseConferenceController extends Controller
{
    private const TYPES = ['initial', 'emergency_safety', 'case_planning', 'progress_review', 'family', 'mdt', 'placement_review', 'transfer_handover', 'reintegration', 'pre_closure', 'other'];

    public function __construct(private readonly CaseAccessScope $scope) {}

    private function access(Request $request, int $case): object
    {
        $record = DB::table('cases')->whereNull('deleted_at')->find($case) ?? abort(404);
        $this->authorize('cases.view');
        $client = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
        abort_unless($client && $this->scope->allowsCase($record, $request->user(), $client->municipality, $client->province), 403);

        return $record;
    }

    private function manage(Request $r, int $case): object
    {
        $record = $this->access($r, $case);
        $this->authorize('conferences.manage');
        abort_unless($record->status !== 'CLOSED', 422, 'Reopen the case or use an approved aftercare workflow before creating a conference.');

        return $record;
    }

    private function item(Request $r, int $case, int $conference): object
    {
        $this->access($r, $case);

        return DB::table('case_conferences')->where('id', $conference)->where('case_id', $case)->first() ?? abort(404);
    }

    private function history(int $conference, string $event, Request $r, ?string $comments = null, ?string $prior = null, ?string $next = null): void
    {
        DB::table('case_conference_history')->insert(['case_conference_id' => $conference, 'event' => $event, 'actor_id' => $r->user()->id, 'comments' => $comments, 'prior_scheduled_at' => $prior, 'new_scheduled_at' => $next, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function index(Request $r, int $case)
    {
        $record = $this->access($r, $case);
        $conferences = DB::table('case_conferences')->where('case_id', $case)->latest('scheduled_at')->orderByDesc('id')->get(['id', 'conference_type', 'status', 'scheduled_at', 'finalized_at', 'next_review_at', 'record_type', 'previous_conference_id']);
        $base = ['caseId' => $case, 'caseNumber' => $record->case_number, 'caseStatus' => $record->status, 'conferences' => $conferences];
        if (!$r->expectsJson() || $r->headers->has('X-Inertia')) {
            return Inertia::render('Conferences/Index', $base);
        }
        // Minimum metadata from this authorized case only; no referenced narratives.
        $tasks = DB::table('tasks')->where('case_id', $case)->get(['id', 'title']);
        $sources = [
            'task' => $tasks,
            'risk_reassessment_request' => $tasks,
            'referral' => DB::table('referrals')->where('case_id', $case)->get(['id', 'service_category as title']),
            'intervention' => DB::table('interventions')->join('case_plans', 'case_plans.id', '=', 'interventions.case_plan_id')->where('case_plans.case_id', $case)->get(['interventions.id', 'interventions.activity as title', 'interventions.service_category']),
            'mdt_action' => DB::table('mdt_actions')->where('case_id', $case)->get(['id', 'title']),
        ];

        return response()->json($base + ['sources' => $sources,
            'riskReferences' => DB::table('risk_assessments')->where('case_id', $case)->where('status', 'final')->orderByDesc('id')->get(['id', 'assessed_at']),
            'participationReferences' => DB::table('child_participation_records')->where('case_id', $case)->where('status', 'final')->orderByDesc('id')->get(['id', 'version']),
        ]);
    }

    public function show(Request $r, int $case, int $conference)
    {
        $item = $this->item($r, $case, $conference);
        $actions = DB::table('case_conference_actions')->where('case_conference_id', $conference)->get()->map(fn ($action) => $this->withOperationalStatus($action, $case));
        $author = (int) $item->created_by === (int) $r->user()->id;
        $manage = $r->user()->hasPermission('conferences.manage');
        $reviewer = $r->user()->hasPermission('conferences.review') && !$author;

        return response()->json([
            'conference' => $item,
            'participants' => DB::table('case_conference_participants')->where('case_conference_id', $conference)->get(),
            'decisions' => DB::table('case_conference_decisions')->where('case_conference_id', $conference)->get(),
            'actions' => $actions,
            'history' => DB::table('case_conference_history')->leftJoin('users', 'users.id', '=', 'case_conference_history.actor_id')->where('case_conference_id', $conference)->orderBy('case_conference_history.created_at')->orderBy('case_conference_history.id')->get(['case_conference_history.*', 'users.name as actor_name', 'users.role as actor_role']),
            'allowed_actions' => [
                'edit_draft' => $manage && $author && $item->status === 'draft',
                'submit_for_review' => $manage && $author && in_array($item->status, ['draft', 'scheduled', 'in_progress'], true),
                'mark_scheduled' => $manage && $author && $item->status === 'draft',
                'start' => $manage && $author && in_array($item->status, ['draft', 'scheduled'], true),
                'return_for_revision' => $reviewer && $item->status === 'for_review',
                'finalize' => $reviewer && $item->status === 'for_review',
                'reschedule' => $manage && $author && in_array($item->status, ['draft', 'scheduled'], true),
                'addendum' => $manage && $item->status === 'final',
            ],
        ]);
    }

    public function store(Request $r, int $case)
    {
        $this->manage($r, $case);
        $d = $r->validate(['conference_type' => 'required|in:initial,emergency_safety,case_planning,progress_review,family,mdt,placement_review,transfer_handover,reintegration,pre_closure,other', 'other_type_reason' => 'nullable|string|max:500', 'scheduled_at' => 'nullable|date', 'venue_mode' => 'nullable|string|max:100', 'facilitator_name' => 'nullable|string|max:180', 'recorder_name' => 'nullable|string|max:180', 'purpose' => 'nullable|string|max:5000']);
        if ($d['conference_type'] === 'other' && empty($d['other_type_reason'])) {
            throw ValidationException::withMessages(['other_type_reason' => 'Explain an Other conference type.']);
        }
        if (!empty($d['scheduled_at'])) {
            $d['scheduled_at'] = Carbon::parse($d['scheduled_at'])->utc()->format('Y-m-d H:i:s');
        }
        $id = DB::transaction(function () use ($r, $case, $d) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $actor = User::query()->where('id', $r->user()->id)->lockForUpdate()->first();
            DB::table('user_lgu_assignments')->where('user_id', $r->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $eligibility = app(CaseAssignmentEligibility::class);
            abort_unless($actor && $actor->can('conferences.manage') && $eligibility->canRead($actor, $record), 403);
            abort_if($record->status === 'CLOSED', 422, 'Reopen the case or use an approved aftercare workflow before creating a conference.');
            $id = DB::table('case_conferences')->insertGetId($d + ['case_id' => $case, 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->history($id, 'created', $r);
            $this->audit($r, $case, 'conference.created', $id);
            abort_unless($eligibility->canRead($actor, $record), 403);

            return $id;
        }, 3);

        return response()->json(['id' => $id], 201);
    }

    /** Serialize this case's conference mutations and commit their history/audit together. */
    private function lockWriteAccess(Request $r, int $case): void
    {
        $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
        $actor = User::query()->where('id', $r->user()->id)->lockForUpdate()->first();
        DB::table('user_lgu_assignments')->where('user_id', $r->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
        abort_unless($actor && app(CaseAssignmentEligibility::class)->canRead($actor, $record), 403);
        // Gate and request reads must share the fresh locked account, including role changes.
        Auth::setUser($actor);
        $r->setUserResolver(fn () => $actor);
    }

    public function update(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless($item->status === 'draft' && (int) $item->created_by === $r->user()->id, 403, 'Only the author may edit a draft conference.');
            $d = $r->validate(['case_updates' => 'nullable|string|max:5000', 'key_issues' => 'nullable|string|max:5000', 'family_perspective' => 'nullable|string|max:5000', 'professional_observations' => 'nullable|string|max:5000', 'child_participation_status' => 'nullable|in:direct,previous_views_presented,deferred,not_appropriate,not_participating', 'child_participation_record_id' => 'nullable|integer', 'risk_assessment_id' => 'nullable|integer', 'next_review_at' => 'nullable|date', 'confidentiality' => 'nullable|in:casework,restricted']);
            if (!empty($d['next_review_at'])) {
                $d['next_review_at'] = Carbon::parse($d['next_review_at'])->utc()->format('Y-m-d H:i:s');
            } if (!empty($d['child_participation_record_id'])) {
                abort_unless(DB::table('child_participation_records')->where('id', $d['child_participation_record_id'])->where('case_id', $case)->exists(), 422);
            } if (!empty($d['risk_assessment_id'])) {
                abort_unless(DB::table('risk_assessments')->where('id', $d['risk_assessment_id'])->where('case_id', $case)->where('status', 'final')->exists(), 422);
            } DB::table('case_conferences')->where('id', $conference)->update($d + ['updated_at' => now()]);
            $this->audit($r, $case, 'conference.updated', $conference, ['fields' => array_keys($d)]);

            return response()->noContent();
        }, 3);
    }

    public function participants(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless((int) $item->created_by === (int) $r->user()->id, 403, 'Only the author may edit a draft conference.');
            abort_unless($item->status === 'draft', 422);
            $d = $r->validate(['name' => 'required|string|max:180', 'user_id' => 'nullable|exists:users,id', 'professional_role' => 'nullable|string|max:140', 'organization' => 'nullable|string|max:180', 'attendance_status' => 'required|in:invited,attended,declined,absent', 'participation_mode' => 'nullable|string|max:60', 'conference_role' => 'nullable|string|max:80']);
            $id = DB::table('case_conference_participants')->insertGetId($d + ['case_conference_id' => $conference, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $case, 'conference.participant_recorded', $conference, ['participant_id' => $id]);

            return response()->json(['id' => $id], 201);
        }, 3);
    }

    public function decision(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless((int) $item->created_by === (int) $r->user()->id, 403, 'Only the author may edit a draft conference.');
            abort_unless($item->status === 'draft', 422);
            $d = $r->validate(['decision_type' => 'required|in:decision,recommendation', 'statement' => 'required|string|max:5000']);
            $id = DB::table('case_conference_decisions')->insertGetId($d + ['case_conference_id' => $conference, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $case, 'conference.decision_recorded', $conference, ['decision_id' => $id]);

            return response()->json(['id' => $id], 201);
        }, 3);
    }

    public function action(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless((int) $item->created_by === (int) $r->user()->id, 403, 'Only the author may edit a draft conference.');
            abort_unless($item->status === 'draft', 422);
            $d = $r->validate(['action' => 'required|string|max:5000', 'category' => 'nullable|string|max:100', 'responsible_user_id' => 'nullable|exists:users,id', 'responsible_office' => 'nullable|string|max:180', 'target_at' => 'nullable|date', 'priority' => 'required|in:low,normal,high,urgent', 'source_type' => 'nullable|in:task,referral,intervention,mdt_action,risk_reassessment_request', 'source_id' => 'nullable|integer']);
            if (!empty($d['target_at'])) {
                $d['target_at'] = Carbon::parse($d['target_at'])->utc()->format('Y-m-d H:i:s');
            } if (!empty($d['source_type'])) {
                $this->assertSource($case, $d['source_type'], $d['source_id'] ?? 0);
            } $id = DB::table('case_conference_actions')->insertGetId($d + ['case_conference_id' => $conference, 'local_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($r, $case, 'conference.action_recorded', $conference, ['action_id' => $id]);

            return response()->json(['id' => $id], 201);
        }, 3);
    }

    public function transition(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $to = $r->validate(['status' => 'required|in:scheduled,in_progress,for_review,returned_for_revision,final', 'comments' => 'nullable|string|max:5000'])['status'];
            $comments = $r->input('comments');
            if (in_array($to, ['scheduled', 'in_progress', 'for_review'], true)) {
                $this->authorize('conferences.manage');
                abort_unless((int) $item->created_by === $r->user()->id && in_array($item->status, ['draft', 'scheduled', 'in_progress'], true), 403);
            } elseif ($to === 'returned_for_revision') {
                $this->authorize('conferences.review');
                abort_unless($item->status === 'for_review' && (int) $item->created_by !== $r->user()->id && !empty($comments), 422, 'Independent reviewer comments are required.');
                DB::table('case_conferences')->where('id', $conference)->update(['status' => 'draft', 'returned_by' => $r->user()->id, 'returned_at' => now(), 'review_comments' => $comments, 'updated_at' => now()]);
                $this->history($conference, 'returned_for_revision', $r, $comments);
                $this->audit($r, $case, 'conference.returned_for_revision', $conference);

                return response()->noContent();
            } else {
                $this->authorize('conferences.review');
                abort_unless($item->status === 'for_review' && (int) $item->created_by !== $r->user()->id, 403);
                DB::table('case_conferences')->where('id', $conference)->update(['status' => 'final', 'reviewed_by' => $r->user()->id, 'finalized_by' => $r->user()->id, 'finalized_at' => now(), 'updated_at' => now()]);
                $this->history($conference, 'finalized', $r);
                $this->audit($r, $case, 'conference.finalized', $conference);

                return response()->noContent();
            } DB::table('case_conferences')->where('id', $conference)->update(['status' => $to, 'started_at' => $to === 'in_progress' ? now() : $item->started_at, 'completed_at' => $to === 'for_review' ? now() : $item->completed_at, 'updated_at' => now()]);
            $this->history($conference, $to, $r);
            $this->audit($r, $case, 'conference.status_changed', $conference, ['status' => $to]);

            return response()->noContent();
        }, 3);
    }

    public function reschedule(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless((int) $item->created_by === (int) $r->user()->id, 403, 'Only the author may reschedule this conference.');
            abort_unless(in_array($item->status, ['draft', 'scheduled'], true), 422);
            $d = $r->validate(['scheduled_at' => 'required|date', 'reason' => 'required|string|max:2000']);
            $d['scheduled_at'] = Carbon::parse($d['scheduled_at'])->utc()->format('Y-m-d H:i:s');
            DB::table('case_conferences')->where('id', $conference)->update(['scheduled_at' => $d['scheduled_at'], 'status' => 'scheduled', 'updated_at' => now()]);
            $this->history($conference, 'rescheduled', $r, $d['reason'], $item->scheduled_at, $d['scheduled_at']);
            $this->audit($r, $case, 'conference.rescheduled', $conference);

            return response()->noContent();
        }, 3);
    }

    public function addendum(Request $r, int $case, int $conference)
    {
        return DB::transaction(function () use ($r, $case, $conference) {
            $this->lockWriteAccess($r, $case);
            $item = $this->item($r, $case, $conference);
            $this->authorize('conferences.manage');
            abort_unless($item->status === 'final', 422);
            $d = $r->validate(['reason' => 'required|string|max:2000', 'purpose' => 'nullable|string|max:5000']);
            $id = DB::table('case_conferences')->insertGetId(['case_id' => $case, 'conference_type' => $item->conference_type, 'status' => 'draft', 'purpose' => $d['purpose'] ?? null, 'previous_conference_id' => $conference, 'record_type' => 'addendum', 'addendum_reason' => $d['reason'], 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->history($id, 'addendum_created', $r, $d['reason']);
            $this->audit($r, $case, 'conference.addendum_created', $id, ['original_id' => $conference]);

            return response()->json(['id' => $id], 201);
        }, 3);
    }

    private function sourceQuery(int $case, string $type, int $id): Builder
    {
        $table = match ($type) {
            'task','risk_reassessment_request' => 'tasks','referral' => 'referrals','intervention' => 'interventions','mdt_action' => 'mdt_actions',default => null
        };
        if ($table === null) {
            return DB::table('tasks')->whereRaw('1=0');
        }
        $query = DB::table($table)->where($table . '.id', $id);
        if ($type === 'intervention') {
            return $query->join('case_plans', 'case_plans.id', '=', 'interventions.case_plan_id')->where('case_plans.case_id', $case);
        }

        return $query->where($table . '.case_id', $case);
    }

    private function assertSource(int $case, string $type, int $id): void
    {
        abort_unless($this->sourceQuery($case, $type, $id)->exists(), 422, 'The linked operational record must belong to this case.');
    }

    private function withOperationalStatus(object $action, int $case): object
    {
        if (!$action->source_type) {
            return $action;
        }
        $column = $action->source_type === 'intervention' ? 'interventions.progress' : 'status';
        $action->operational_status = $this->sourceQuery($case, $action->source_type, (int) $action->source_id)->value($column);

        return $action;
    }

    private function audit(Request $r, int $case, string $event, int $id, array $changes = []): void
    {
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => $event, 'auditable_type' => 'case_conference', 'auditable_id' => $id, 'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);
    }
}
