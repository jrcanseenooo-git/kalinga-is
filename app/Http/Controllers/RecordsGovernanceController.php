<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Services\RecordsGovernance;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RecordsGovernanceController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope, private readonly RecordsGovernance $records) {}

    private function access(Request $r, int $case, string $permission): object
    {
        $this->authorize($permission);
        $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->first();
        abort_unless($record, 404);
        $location = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
        abort_unless($location, 404);
        $this->authorize('cases.view');
        abort_unless($this->scope->allowsCase($record, $r->user(), $location->municipality, $location->province), 403);

        return $record;
    }

    private function audit(Request $r, string $event, int $id, array $meta = []): void
    {
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => $event, 'auditable_type' => 'record_governance', 'auditable_id' => $id, 'changes' => json_encode($meta), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function index(Request $r, int $case)
    {
        $this->access($r, $case, 'records.retention.review');

        return Inertia::render('Cases/RecordsGovernance', ['caseId' => $case, 'classifications' => DB::table('record_classifications')->select('id', 'code', 'label', 'sensitivity_level', 'restricted', 'policy_validated', 'active')->orderBy('label')->get(), 'records' => DB::table('record_governance')->where('case_id', $case)->latest()->get(), 'holds' => DB::table('legal_holds')->where('case_id', $case)->latest()->get(['id', 'scope', 'reason_code', 'authority', 'placed_at', 'review_at', 'released_at']), 'disclosures' => DB::table('record_disclosures')->where('case_id', $case)->latest()->get(['id', 'recipient_organization', 'purpose', 'lawful_authority', 'delivery_method', 'authorized_at', 'acknowledged_at'])]);
    }

    public function classify(Request $r, int $case, int $document)
    {
        $this->access($r, $case, 'records.classify');
        $data = $r->validate(['record_classification_id' => 'nullable|exists:record_classifications,id', 'retention_rule_id' => 'nullable|exists:retention_rules,id']);
        $governance = $this->records->ensureDocument($case, $document, true);
        DB::table('record_governance')->where('id', $governance->id)->update($data + ['classified_by' => $r->user()->id, 'classified_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'record.classified', $governance->id, ['case_id' => $case, 'record_type' => 'document']);

        return back()->with('success', 'Document governance classification updated.');
    }

    public function placeHold(Request $r, int $case)
    {
        $this->access($r, $case, 'records.hold.manage');
        $data = $r->validate(['scope' => 'required|in:case,record', 'reason_code' => 'required|string|max:120', 'authority' => 'required|string|max:255', 'review_at' => 'nullable|date', 'record_type' => 'required_if:scope,record|nullable|string', 'record_id' => 'required_if:scope,record|nullable|integer']);
        if ($data['scope'] === 'record') {
            $this->records->assertType($data['record_type']);
            abort_unless(DB::table('record_governance')->where('case_id', $case)->where('record_type', $data['record_type'])->where('record_id', $data['record_id'])->exists(), 422, 'Governed record is required.');
        } $id = DB::transaction(function () use ($r, $case, $data) {
            $id = DB::table('legal_holds')->insertGetId(['case_id' => $case, 'scope' => $data['scope'], 'reason_code' => $data['reason_code'], 'authority' => $data['authority'], 'placed_by' => $r->user()->id, 'placed_at' => now(), 'review_at' => $data['review_at'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            if ($data['scope'] === 'record') {
                DB::table('legal_hold_records')->insert(['legal_hold_id' => $id, 'record_type' => $data['record_type'], 'record_id' => $data['record_id'], 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
        $this->audit($r, 'record_hold.placed', $id, ['case_id' => $case, 'scope' => $data['scope']]);

        return back()->with('success', 'Legal hold placed.');
    }

    public function releaseHold(Request $r, int $case, int $hold)
    {
        $this->access($r, $case, 'records.hold.manage');
        $data = $r->validate(['release_reason' => 'required|string|max:1000']);
        $record = DB::table('legal_holds')->where('id', $hold)->where('case_id', $case)->whereNull('released_at')->first();
        abort_unless($record, 404);
        DB::table('legal_holds')->where('id', $hold)->update(['released_by' => $r->user()->id, 'released_at' => now(), 'release_reason' => $data['release_reason'], 'updated_at' => now()]);
        $this->audit($r, 'record_hold.released', $hold, ['case_id' => $case]);

        return back()->with('success', 'Legal hold released.');
    }

    public function disposition(Request $r, int $case, int $governance)
    {
        $this->access($r, $case, 'records.disposition.propose');
        $record = DB::table('record_governance')->where('id', $governance)->where('case_id', $case)->first();
        abort_unless($record, 404);
        abort_unless(!$this->records->activeHold($case, $record->record_type, $record->record_id), 422, 'Legal hold blocks disposition.');
        $data = $r->validate(['proposal_reason' => 'required|string|max:1000']);
        $id = DB::table('record_dispositions')->insertGetId(['record_governance_id' => $governance, 'status' => 'proposed', 'proposal_reason' => $data['proposal_reason'], 'proposed_by' => $r->user()->id, 'proposed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('record_disposition_history')->insert(['record_disposition_id' => $id, 'event' => 'proposed', 'actor_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('record_governance')->where('id', $governance)->update(['lifecycle_state' => 'disposition_pending', 'updated_at' => now()]);
        $this->audit($r, 'record_disposition.proposed', $governance, ['case_id' => $case]);

        return back()->with('success', 'Disposition proposed for authorized review; execution remains disabled pending policy.');
    }

    public function reviewDisposition(Request $r, int $case, int $governance)
    {
        $this->access($r, $case, $r->input('action') === 'approved' ? 'records.disposition.approve' : 'records.disposition.review');
        $data = $r->validate(['action' => 'required|in:reviewed,approved,returned,retained', 'reason_code' => 'nullable|string|max:120']);
        $record = DB::table('record_governance')->where('id', $governance)->where('case_id', $case)->first();
        abort_unless($record, 404);
        abort_unless(!$this->records->activeHold($case, $record->record_type, $record->record_id), 422, 'Legal hold blocks disposition.');
        $disposition = DB::table('record_dispositions')->where('record_governance_id', $governance)->latest('id')->first();
        abort_unless($disposition && in_array($disposition->status, ['proposed', 'reviewed'], true), 422, 'No disposition awaiting review.');
        abort_unless((int) $disposition->proposed_by !== $r->user()->id, 403, 'A different authorized user must review or approve a proposal.');
        $change = ['status' => $data['action'], 'updated_at' => now()];
        if ($data['action'] === 'reviewed') {
            $change += ['reviewed_by' => $r->user()->id, 'reviewed_at' => now()];
        }if ($data['action'] === 'approved') {
            $change += ['approved_by' => $r->user()->id, 'approved_at' => now()];
        }DB::transaction(function () use ($r, $governance, $disposition, $data, $change) {
            DB::table('record_dispositions')->where('id', $disposition->id)->update($change);
            DB::table('record_disposition_history')->insert(['record_disposition_id' => $disposition->id, 'event' => $data['action'], 'actor_id' => $r->user()->id, 'reason_code' => $data['reason_code'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            if ($data['action'] === 'approved') {
                DB::table('record_governance')->where('id', $governance)->update(['lifecycle_state' => 'approved_for_disposition', 'updated_at' => now()]);
            }if (in_array($data['action'], ['returned', 'retained'], true)) {
                DB::table('record_governance')->where('id', $governance)->update(['lifecycle_state' => 'retained', 'updated_at' => now()]);
            }
        });
        $this->audit($r, 'record_disposition.' . $data['action'], $governance, ['case_id' => $case]);

        return back()->with('success', $data['action'] === 'approved' ? 'Approved for disposition; execution is disabled pending policy.' : 'Disposition review recorded.');
    }

    public function disclose(Request $r, int $case)
    {
        $this->access($r, $case, 'records.disclosure.record');
        $data = $r->validate(['recipient_organization' => 'required|string|max:255', 'recipient_name' => 'nullable|string|max:255', 'purpose' => 'required|string|max:500', 'lawful_authority' => 'required|string|max:255', 'delivery_method' => 'required|string|max:100', 'items' => 'required|array|min:1', 'items.*.record_type' => 'required|string', 'items.*.record_id' => 'required|integer', 'items.*.category' => 'required|string|max:120']);
        foreach ($data['items'] as $item) {
            $this->records->assertType($item['record_type']);
            abort_unless(DB::table('record_governance')->where('case_id', $case)->where('record_type', $item['record_type'])->where('record_id', $item['record_id'])->exists(), 422, 'Disclosure item is not a governed record for this case.');
        }$id = DB::transaction(function () use ($r, $case, $data) {
            $id = DB::table('record_disclosures')->insertGetId(collect($data)->except('items')->all() + ['case_id' => $case, 'authorized_by' => $r->user()->id, 'authorized_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['items'] as $item) {
                DB::table('record_disclosure_items')->insert($item + ['record_disclosure_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $id;
        });
        $this->audit($r, 'record.disclosed', $id, ['case_id' => $case, 'item_count' => count($data['items'])]);

        return back()->with('success', 'Disclosure ledger entry recorded.');
    }
}
