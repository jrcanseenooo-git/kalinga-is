<?php

namespace App\Http\Controllers;

use App\Services\CaseAssignmentEligibility;
use App\Services\CaseRemarks;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The case manager's side of remarks: reply and mark as read. */
class CaseRemarkController extends Controller
{
    public function __construct(private readonly CaseAssignmentEligibility $eligibility, private readonly CaseRemarks $remarks) {}

    private function context(Request $request, int $case, int $remark): array
    {
        $this->authorize('cases.view');
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record && $this->eligibility->canRead($request->user(), $record), 404);
        $item = DB::table('case_remarks')->where('case_id', $case)->whereNull('parent_id')->find($remark);
        abort_unless($item, 404);

        return [$record, $item];
    }

    public function reply(Request $request, int $case, int $remark)
    {
        $this->authorize('cases.remark');
        [$record, $item] = $this->context($request, $case, $remark);
        $data = $request->validate(['body' => 'required|string|min:2|max:2000']);
        DB::transaction(function () use ($record, $item, $request, $data) {
            $this->remarks->add($record, $request->user(), $data['body'], 'reply', $item->id);
            DB::table('case_remarks')->where('id', $item->id)->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Reply sent.');
    }

    public function acknowledge(Request $request, int $case, int $remark)
    {
        [$record, $item] = $this->context($request, $case, $remark);
        if (!$item->acknowledged_at) {
            DB::table('case_remarks')->where('id', $item->id)->update(['acknowledged_at' => now(), 'updated_at' => now()]);
            AuditLog::record('case_remark.acknowledged', 'case', $record->id, ['case_id' => $record->id], $request->user()->id);
        }

        return back()->with('success', 'Remark marked as read.');
    }
}
