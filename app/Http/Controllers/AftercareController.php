<?php

namespace App\Http\Controllers;

use App\Services\Aftercare;
use App\Services\CaseAssignmentEligibility;
use App\Services\OperationalNotification;
use App\Services\Supervisors;
use App\Support\AuditLog;
use App\Support\PhilippineDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Aftercare plan (written at closure) and follow-up visits (after closure).
 * Only the case manager who may change the case records them; a closed case
 * stays closed while its aftercare is recorded.
 */
class AftercareController extends Controller
{
    public function __construct(private readonly CaseAssignmentEligibility $eligibility) {}

    private function caseFor(Request $request, int $case): object
    {
        $this->authorize('cases.update');
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record && $this->eligibility->canRead($request->user(), $record), 404);
        abort_if($record->status === 'ARCHIVED', 422, 'This case is archived.');

        return $record;
    }

    public function savePlan(Request $request, int $case)
    {
        $record = $this->caseFor($request, $case);
        abort_unless(in_array($record->status, ['FOR_CLOSURE_REVIEW', 'CLOSED', 'IN_PROGRESS', 'FOR_FOLLOW_UP', 'AWAITING_REFERRAL', 'REOPENED', 'OPEN'], true), 422);
        $data = $request->validate([
            'living_arrangement' => 'required|string|max:255', 'support_people' => 'nullable|string|max:3000', 'services_to_continue' => 'nullable|string|max:3000',
            'risks_to_watch' => 'nullable|string|max:3000', 'responsible_office' => 'nullable|string|max:180',
            'follow_up_frequency' => ['required', Rule::in(array_keys(Aftercare::FREQUENCIES))],
            'initial_follow_up_at' => 'required|date', 'follow_up_until' => 'required|date|after_or_equal:initial_follow_up_at', 'notes' => 'nullable|string|max:5000',
        ]);
        $values = collect($data)->except('notes')->all() + ['continuity_notes' => $data['notes'] ?? null, 'required' => true, 'status' => 'follow_up', 'updated_by' => $request->user()->id, 'updated_at' => now()];
        $existing = Aftercare::plan($case);
        if ($existing) {
            DB::table('case_aftercare_plans')->where('id', $existing->id)->update($values);
            $id = $existing->id;
        } else {
            $id = DB::table('case_aftercare_plans')->insertGetId($values + ['case_id' => $case, 'created_by' => $request->user()->id, 'created_at' => now()]);
        }
        AuditLog::record('case_aftercare.recorded', 'case', $case, ['case_id' => $case, 'aftercare_plan_id' => $id], $request->user()->id, 'success',
            ['description' => $existing ? 'Aftercare plan updated' : 'Aftercare plan written']);

        return back()->with('success', 'Aftercare plan saved.');
    }

    public function storeVisit(Request $request, int $case)
    {
        $record = $this->caseFor($request, $case);
        abort_unless($record->status === 'CLOSED', 422, 'Follow-up visits are recorded after the case is closed.');
        $plan = Aftercare::plan($case);
        abort_unless($plan, 422, 'Write the aftercare plan first.');
        $data = $request->validate([
            'visited_on' => 'required|date|before_or_equal:' . PhilippineDate::today(), 'status' => ['required', Rule::in(array_keys(Aftercare::STATUSES))], 'notes' => 'required|string|min:5|max:5000',
        ]);
        $id = DB::table('aftercare_visits')->insertGetId($data + ['case_id' => $case, 'aftercare_plan_id' => $plan->id, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('aftercare.visit_recorded', 'case', $case, ['case_id' => $case, 'visit_id' => $id, 'status' => $data['status']], $request->user()->id, 'success',
            ['description' => 'Aftercare follow-up visit recorded: ' . Aftercare::STATUSES[$data['status']], 'new_value' => $data['status']]);
        if ($data['status'] === 'at_risk') {
            foreach (Supervisors::forLgu($record->lgu_id) as $supervisor) {
                app(OperationalNotification::class)->send($supervisor, 'aftercare_at_risk', 'Aftercare: child at risk', "{$record->case_number} - the follow-up visit found the child at risk. Consider reopening the case.", "/cases/{$case}?section=closure");
            }

            return back()->with('success', 'Visit recorded. The child is at risk: consider reopening the case. Your supervisor was told.');
        }

        return back()->with('success', 'Follow-up visit recorded.');
    }
}
