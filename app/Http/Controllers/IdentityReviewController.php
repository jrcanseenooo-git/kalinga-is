<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\IdentityIntegrity;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IdentityReviewController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope, private readonly IdentityIntegrity $identity) {}

    private function canViewClient(Request $request, int $clientId): bool
    {
        $visible = function (int $id) use ($request): bool {
            $query = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->where('cases.client_id', $id)->whereNull('cases.deleted_at');
            $this->scope->scopeCases($query, $request->user());

            return $query->exists();
        };
        if ($visible($clientId)) {
            return true;
        }
        $canonical = $this->identity->canonicalClient($clientId);

        return $canonical && (int) $canonical->id !== $clientId && $visible((int) $canonical->id);
    }

    private function candidate(Request $request, int $candidate): object
    {
        $this->authorize('identity.candidates.view');
        $record = DB::table('identity_duplicate_candidates')->where('id', $candidate)->first();
        abort_unless($record, 404);
        abort_unless($this->canViewClient($request, $record->client_a_id) || $this->canViewClient($request, $record->client_b_id), 404);

        return $record;
    }

    private function audit(Request $request, string $event, int $id, array $meta = []): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'identity_duplicate_candidate', 'auditable_id' => $id, 'changes' => json_encode($meta), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function index(Request $request)
    {
        $this->authorize('identity.candidates.view');

        $accessibleQuery = DB::table('cases')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->whereNull('cases.deleted_at');
        $this->scope->scopeCases($accessibleQuery, $request->user());
        $accessibleClientIds = $accessibleQuery->pluck('cases.client_id')->unique();

        $candidates = DB::table('identity_duplicate_candidates')
            ->orderByDesc('updated_at')
            ->where(function ($q) use ($accessibleClientIds) {
                $q->whereIn('client_a_id', $accessibleClientIds)
                    ->orWhereIn('client_b_id', $accessibleClientIds);
            })
            ->get()
            ->map(fn ($item) => $this->safeCandidate($request, $item))
            ->values();

        $visibleIds = $candidates->pluck('id');
        $merges = DB::table('client_identity_merges')->leftJoin('users as proposers', 'proposers.id', '=', 'client_identity_merges.proposed_by')->whereIn('candidate_id', $visibleIds)->latest('client_identity_merges.updated_at')->get(['client_identity_merges.id', 'client_identity_merges.candidate_id', 'client_identity_merges.status', 'client_identity_merges.proposed_by', 'client_identity_merges.proposed_at', 'client_identity_merges.approved_at', 'client_identity_merges.executed_at', 'proposers.name as proposer_name']);

        return Inertia::render('Identity/ReviewQueue', ['candidates' => $candidates, 'merges' => $merges, 'currentUserId' => $request->user()->id, 'canReview' => $request->user()->hasPermission('identity.review'), 'canApprove' => $request->user()->hasPermission('identity.merge.approve'), 'canExecute' => $request->user()->hasPermission('identity.merge.execute')]);
    }

    public function show(Request $request, int $candidate)
    {
        $record = $this->candidate($request, $candidate);
        $this->audit($request, 'identity_candidate.viewed', $candidate, ['jurisdiction_context' => $record->jurisdiction_context]);

        return response()->json(['candidate' => $this->safeCandidate($request, $record, true), 'decisions' => DB::table('identity_review_decisions')->join('users', 'users.id', '=', 'identity_review_decisions.reviewed_by')->where('candidate_id', $candidate)->get(['decision', 'rationale', 'identity_review_decisions.reviewed_at', 'users.name as reviewer_name', 'users.role as reviewer_role']), 'merges' => DB::table('client_identity_merges')->where('candidate_id', $candidate)->get(['id', 'source_client_id', 'survivor_client_id', 'status', 'proposed_at', 'approved_at', 'executed_at'])]);
    }

    public function review(Request $request, int $candidate)
    {
        $record = $this->candidate($request, $candidate);
        $this->authorize('identity.review');
        $data = $request->validate(['decision' => 'required|in:same_person,related_person,different_person,insufficient_information', 'rationale' => 'required|string|max:3000']);
        if ($data['decision'] === 'same_person') {
            abort_unless($record->jurisdiction_context === 'same_scope', 422, 'Cross-jurisdiction identity consolidation is policy controlled.');
        }
        $status = match ($data['decision']) {
            'same_person' => 'confirmed_duplicate','related_person' => 'related_record','different_person' => 'not_duplicate',default => 'no_action'
        };
        DB::transaction(function () use ($request, $record, $data, $status) {
            $locked = DB::table('identity_duplicate_candidates')->where('id', $record->id)->lockForUpdate()->first();
            abort_unless($locked && !in_array($locked->status, ['merge_proposed', 'approved', 'merged'], true), 422, 'A controlled merge is pending or completed; the historical review cannot be changed.');
            DB::table('identity_duplicate_candidates')->where('id', $record->id)->update(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            DB::table('identity_review_decisions')->insert(['candidate_id' => $record->id, 'decision' => $data['decision'], 'rationale' => $data['rationale'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->audit($request, 'identity_candidate.reviewed', $record->id, ['decision' => $data['decision']]);

        return back()->with('success', 'Identity review decision recorded.');
    }

    public function link(Request $request, int $candidate)
    {
        $record = $this->candidate($request, $candidate);
        $this->authorize('identity.link');
        $data = $request->validate(['link_type' => 'required|in:related_person,possible_related_record', 'rationale' => 'required|string|max:3000']);
        abort_unless($record->status === 'related_record', 422, 'A related-person review decision is required first.');
        [$left,$right] = $record->client_a_id < $record->client_b_id ? [$record->client_a_id, $record->client_b_id] : [$record->client_b_id, $record->client_a_id];
        DB::table('client_identity_links')->updateOrInsert(['left_client_id' => $left, 'right_client_id' => $right, 'link_type' => $data['link_type']], ['status' => 'active', 'rationale' => $data['rationale'], 'created_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit($request, 'identity_link.created', $record->id, ['link_type' => $data['link_type']]);

        return back()->with('success', 'Identity records linked; neither identity was merged.');
    }

    public function proposeMerge(Request $request, int $candidate)
    {
        $record = $this->candidate($request, $candidate);
        $this->authorize('identity.review');
        $data = $request->validate(['source_client_id' => 'required|integer', 'survivor_client_id' => 'required|integer|different:source_client_id', 'reason' => 'required|string|max:3000', 'field_decisions' => 'required|array|min:1', 'field_decisions.*.field_name' => 'required|in:first_name,middle_name,last_name,suffix,birthdate,sex,municipality,barangay,primary_caregiver_name', 'field_decisions.*.resolution' => 'required|in:survivor_value,source_value,preserve_history', 'field_decisions.*.rationale' => 'nullable|string|max:1000']);
        abort_unless($record->status === 'confirmed_duplicate' && $record->jurisdiction_context === 'same_scope', 422, 'A same-jurisdiction same-person decision is required.');
        abort_unless(in_array((int) $data['source_client_id'], [(int) $record->client_a_id, (int) $record->client_b_id], true) && in_array((int) $data['survivor_client_id'], [(int) $record->client_a_id, (int) $record->client_b_id], true), 422, 'Merge identities must match the reviewed candidate.');
        $source = DB::table('clients')->where('id', $data['source_client_id'])->first();
        $survivor = DB::table('clients')->where('id', $data['survivor_client_id'])->first();
        abort_unless($source && $survivor && $source->identity_status === 'active' && $survivor->identity_status === 'active', 422, 'Only active identities may be merged.');
        $id = DB::transaction(function () use ($request, $record, $data, $source, $survivor) {
            $lockedCandidate = DB::table('identity_duplicate_candidates')->where('id', $record->id)->lockForUpdate()->first();
            abort_unless($lockedCandidate && $lockedCandidate->status === 'confirmed_duplicate' && $lockedCandidate->jurisdiction_context === 'same_scope', 409, 'Candidate review changed; refresh and review again.');
            $lockedSource = DB::table('clients')->where('id', $source->id)->lockForUpdate()->first();
            $lockedSurvivor = DB::table('clients')->where('id', $survivor->id)->lockForUpdate()->first();
            abort_unless($lockedSource && $lockedSurvivor && $lockedSource->identity_status === 'active' && $lockedSurvivor->identity_status === 'active', 409, 'Identity state changed; refresh and review again.');
            abort_if(DB::table('client_identity_merges')->where('source_client_id', $source->id)->whereIn('status', ['proposed', 'approved'])->exists(), 409, 'This source identity already has a pending controlled merge.');
            $id = DB::table('client_identity_merges')->insertGetId(['candidate_id' => $record->id, 'source_client_id' => $source->id, 'survivor_client_id' => $survivor->id, 'status' => 'proposed', 'reason' => $data['reason'], 'source_signature' => $this->identity->signature($lockedSource), 'survivor_signature' => $this->identity->signature($lockedSurvivor), 'proposed_by' => $request->user()->id, 'proposed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['field_decisions'] as $d) {
                $value = $d['resolution'] === 'source_value' ? (string) ($lockedSource->{$d['field_name']} ?? '') : (string) ($lockedSurvivor->{$d['field_name']} ?? '');
                DB::table('client_identity_merge_field_decisions')->insert(['merge_id' => $id, 'field_name' => $d['field_name'], 'resolution' => $d['resolution'], 'frozen_value' => $value, 'rationale' => $d['rationale'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }$this->identity->recordIdentifierConflicts($id, (int) $lockedSource->id, (int) $lockedSurvivor->id);
            DB::table('identity_duplicate_candidates')->where('id', $record->id)->update(['status' => 'merge_proposed', 'updated_at' => now()]);

            return $id;
        });
        $this->audit($request, 'identity_merge.proposed', $record->id, ['merge_id' => $id]);

        return back()->with('success', 'Merge proposal saved for separate approval.');
    }

    public function approveMerge(Request $request, int $merge)
    {
        $this->authorize('identity.merge.approve');
        $record = DB::table('client_identity_merges')->where('id', $merge)->first();
        abort_unless($record, 404);
        $candidate = $this->candidate($request, $record->candidate_id);
        DB::transaction(function () use ($request, $merge) {
            $record = DB::table('client_identity_merges')->where('id', $merge)->lockForUpdate()->first();
            abort_unless($record && $record->status === 'proposed' && (int) $record->proposed_by !== $request->user()->id, 403, 'A different authorized user must approve this merge proposal.');
            $source = DB::table('clients')->where('id', $record->source_client_id)->lockForUpdate()->first();
            $survivor = DB::table('clients')->where('id', $record->survivor_client_id)->lockForUpdate()->first();
            abort_unless($source && $survivor && $this->identity->signature($source) === $record->source_signature && $this->identity->signature($survivor) === $record->survivor_signature, 409, 'The proposal is stale and must be re-reviewed.');
            DB::table('client_identity_merges')->where('id', $merge)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
        });
        $this->audit($request, 'identity_merge.approved', $candidate->id, ['merge_id' => $merge]);

        return back()->with('success', 'Merge approved; execution remains a separate controlled action.');
    }

    public function historicalIdentifier(Request $request)
    {
        $this->authorize('identity.merge.history.view');
        $data = $request->validate(['identifier_type' => 'required|string|max:80', 'identifier' => 'required|string|max:255']);
        $clients = collect($this->identity->canonicalClientsForIdentifier($data['identifier_type'], $data['identifier']))
            ->filter(fn ($client) => $this->canViewClient($request, $client->id))
            ->map(fn ($client) => ['canonical_client_id' => $client->id, 'identity_status' => $client->identity_status, 'label' => 'Authorized historical identifier resolved to the canonical client.'])
            ->values();
        $this->audit($request, 'identity_identifier.resolved', 0, ['match_count' => $clients->count()]);

        return response()->json(['matches' => $clients]);
    }

    public function executeMerge(Request $request, int $merge)
    {
        $this->authorize('identity.merge.execute');
        $record = DB::table('client_identity_merges')->find($merge);
        abort_unless($record, 404);
        $this->candidate($request, $record->candidate_id);
        $result = $this->identity->execute($merge, $request->user()->id);

        return response()->json(['merge_id' => $result->id, 'status' => $result->status]);
    }

    public function reconcile(Request $request, int $legacy)
    {
        $this->authorize('assessments.legacy.reconcile');
        $record = DB::table('assessments')->find($legacy);
        abort_unless($record, 404);
        $case = DB::table('cases')->find($record->case_id);
        abort_unless($case && $this->canViewClient($request, $case->client_id), 404);
        $data = $request->validate(['classification' => 'required|in:equivalent_mappable,partial,unsupported,historical_only,requires_manual_review', 'unresolved_fields' => 'nullable|array']);
        DB::table('legacy_assessment_reconciliations')->updateOrInsert(['legacy_assessment_id' => $legacy], ['case_id' => $record->case_id, 'classification' => $data['classification'], 'validation_status' => 'reviewed', 'unresolved_fields' => json_encode($data['unresolved_fields'] ?? []), 'reviewed_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Legacy assessment reconciliation recorded; no assessment was converted automatically.');
    }

    private function safeCandidate(Request $request, object $record, bool $detail = false): array
    {
        $left = $this->canViewClient($request, $record->client_a_id) ? DB::table('clients')->find($record->client_a_id) : null;
        $right = $this->canViewClient($request, $record->client_b_id) ? DB::table('clients')->find($record->client_b_id) : null;
        $safe = function ($client) {
            if (!$client) {
                return ['restricted' => true, 'label' => 'Potential match exists in another jurisdiction'];
            }
            if ($client->identity_status === 'merged') {
                return ['merged' => true, 'label' => 'Merged identity → canonical survivor'];
            }

            return ['id' => $client->id, 'name' => trim($client->first_name . ' ' . $client->last_name), 'birthdate' => $client->birthdate, 'sex' => $client->sex, 'municipality' => $client->municipality, 'case_count' => DB::table('cases')->where('client_id', $client->id)->whereNull('deleted_at')->count()];
        };

        return ['id' => $record->id, 'status' => $record->status, 'jurisdiction_context' => $record->jurisdiction_context, 'matching_signals' => json_decode($record->matching_signals, true), 'left' => $safe($left), 'right' => $safe($right), 'reviewed_at' => $record->reviewed_at, 'detail' => $detail];
    }
}
