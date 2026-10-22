<?php

namespace App\Http\Controllers;

use App\Services\IdentityIntegrity;
use App\Services\LguAssignmentScope;
use App\Services\OperationalNotification;
use App\Services\ReferenceNumberService;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineSyncController extends Controller
{
    public function __construct(
        private readonly ReferenceNumberService $references,
        private readonly LguAssignmentScope $lgus,
        private readonly IdentityIntegrity $identity,
        private readonly OperationalNotification $notifications,
    ) {}

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('offline.use'), 403);
        $data = $request->validate(['offline_id' => 'required|uuid', 'resource' => 'required|in:case_note,monitoring_visit,report_intake,case_intake', 'operation' => 'required|in:create,update', 'payload' => 'required|array', 'base_version' => 'nullable|integer|min:1']);

        return DB::transaction(function () use ($data, $request) {
            // Serialize retries from the same account before checking the UUID.
            // The existing unique offline_id constraint remains the final guard.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $this->requireActiveDevice($request);
            abort_unless($data['operation'] === 'create', 422, 'This offline resource supports creation only.');
            $existing = DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $request->user()->id, 403);
                abort_unless($existing->resource === $data['resource'] && $this->samePayload(json_decode($existing->payload, true), $data['payload']), 422, 'This offline identifier belongs to a different operation.');
            }
            if (in_array($data['resource'], ['case_note', 'monitoring_visit'], true)) {
                // A case id must be a positive whole number; anything else (arrays, text) is refused before querying.
                $caseId = filter_var($data['payload']['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                abort_if($caseId === false, 422, 'The case identifier is not valid.');
                $case = DB::table('cases')->where('id', $caseId)->whereNull('deleted_at')->lockForUpdate()->first();
                abort_unless($case, 422, 'The case is no longer available.');
                $client = DB::table('clients')->find($case->client_id);
                abort_unless((int) $case->assigned_to === (int) $request->user()->id
                    && $this->lgus->caseMatches($request->user(), $case, $client?->municipality, $client?->province), 403);
            } else {
                abort_unless($this->lgus->matchesMunicipality($request->user(), (string) ($data['payload']['municipality'] ?? '')), 403);
            }
            if ($existing && $existing->status === 'synced') {
                return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'id' => $existing->id]);
            }
            if ($data['resource'] === 'case_intake') {
                abort_unless($data['operation'] === 'create', 422, 'Offline case intakes can only be created.');
                $payload = validator($data['payload'], [
                    'first_name' => 'required|string|max:80', 'middle_name' => 'nullable|string|max:80', 'last_name' => 'required|string|max:80', 'suffix' => 'nullable|string|max:30',
                    'birthdate' => 'required|date|before_or_equal:today', 'sex' => 'required|in:Male,Female', 'civil_status' => 'nullable|string|max:80',
                    'religion' => 'nullable|string|max:120', 'ip_category' => 'nullable|string|max:120', 'education_attainment' => 'nullable|string|max:120', 'phone' => ['nullable', 'regex:/^(?:\\+63|0)9\\d{9}$/'], 'safe_contact_method' => 'nullable|string|max:100', 'safe_contact_notes' => 'nullable|string|max:1000', 'primary_caregiver_name' => 'nullable|string|max:160', 'primary_caregiver_relationship' => 'nullable|string|max:100', 'primary_caregiver_contact' => 'nullable|string|max:80',
                    'house_no_street' => 'nullable|string|max:255', 'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120', 'barangay' => 'nullable|string|max:120',
                    'category' => 'required|in:CEFMU', 'case_type' => 'nullable|string|max:100', 'cefmu_classifications' => 'required|array|min:1', 'cefmu_classifications.*' => 'in:early_union,forced_marriage', 'union_type' => 'nullable|string|max:60', 'partner_age_band' => 'nullable|string|max:60', 'education_status_at_intake' => 'nullable|string|max:100', 'pregnancy_parenting_status' => 'nullable|string|max:100', 'cefmu_context' => 'nullable|string|max:5000', 'circumstance' => 'nullable|string|max:100', 'mode_of_admission' => 'nullable|string|max:100', 'referred_by' => 'nullable|string|max:255', 'referral_date' => 'nullable|date',
                    'problem_presented' => 'nullable|string|max:5000', 'initial_assessment' => 'nullable|string|max:5000', 'plan_recommendations' => 'nullable|string|max:5000', 'intake_remarks' => 'nullable|string|max:5000',
                    'immediate_safety_concern' => 'boolean', 'initial_risk_level' => 'required|in:low,moderate,high,critical', 'risk_indicators' => 'nullable|array', 'risk_indicators.*' => 'string|max:100', 'immediate_actions' => 'nullable|string|max:5000', 'safety_plan' => 'nullable|string|max:5000', 'next_review_at' => 'nullable|date', 'privacy_notice_acknowledged' => 'accepted', 'privacy_notice_method' => 'required|in:explained_verbally,written_or_digital_notice,trusted_adult_or_guardian,deferred_emergency_next_practicable', 'priority' => 'required|in:low,normal,high,urgent',
                ])->validate();
                $municipality = $payload['municipality'] ?? '';
                abort_unless($municipality !== '' && $this->lgus->matchesMunicipality($request->user(), $municipality), 403, 'Offline case intake must match your LGU assignment.');
                DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($payload), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
                $clientId = DB::table('clients')->insertGetId(collect($payload)->only(['first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'sex', 'civil_status', 'religion', 'ip_category', 'education_attainment', 'phone', 'safe_contact_method', 'safe_contact_notes', 'primary_caregiver_name', 'primary_caregiver_relationship', 'primary_caregiver_contact', 'house_no_street', 'region', 'province', 'municipality', 'barangay'])->merge(['created_at' => now(), 'updated_at' => now()])->all());
                $caseNumber = $this->references->nextCase();
                $caseId = DB::table('cases')->insertGetId([
                    'case_number' => $caseNumber, 'client_id' => $clientId, 'assigned_to' => $request->user()->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => $payload['priority'],
                    'category' => 'CEFMU', 'case_type' => $payload['case_type'] ?? null, 'cefmu_classifications' => json_encode($payload['cefmu_classifications']), 'union_type' => $payload['union_type'] ?? null, 'partner_age_band' => $payload['partner_age_band'] ?? null, 'education_status_at_intake' => $payload['education_status_at_intake'] ?? null, 'pregnancy_parenting_status' => $payload['pregnancy_parenting_status'] ?? null, 'cefmu_context' => $payload['cefmu_context'] ?? null, 'privacy_notice_provided_at' => now(), 'privacy_notice_method' => $payload['privacy_notice_method'], 'circumstance' => $payload['circumstance'] ?? null, 'mode_of_admission' => $payload['mode_of_admission'] ?? null,
                    'referred_by' => $payload['referred_by'] ?? null, 'referral_date' => $payload['referral_date'] ?? null, 'problem_presented' => $payload['problem_presented'] ?? null,
                    'initial_assessment' => $payload['initial_assessment'] ?? null, 'plan_recommendations' => $payload['plan_recommendations'] ?? null, 'intake_remarks' => $payload['intake_remarks'] ?? null,
                    'immediate_safety_concern' => $payload['immediate_safety_concern'] ?? false, 'initial_risk_level' => $payload['initial_risk_level'], 'risk_indicators' => json_encode($payload['risk_indicators'] ?? []), 'immediate_actions' => $payload['immediate_actions'] ?? null, 'safety_plan' => $payload['safety_plan'] ?? null, 'next_review_at' => $payload['next_review_at'] ?? null, 'intake_completed_at' => now(), 'opened_at' => now(), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('case_status_history')->insert(['case_id' => $caseId, 'from_status' => null, 'to_status' => 'OPEN', 'changed_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.case_intake_synced', 'auditable_type' => 'case', 'auditable_id' => $caseId, 'changes' => json_encode(['offline_id' => $data['offline_id'], 'case_number' => $caseNumber]), 'created_at' => now(), 'updated_at' => now()]);
                try {
                    $this->identity->generateForClient($clientId);
                } catch (\Throwable) {
                }
                $this->notifications->send($request->user()->id, 'case.synced', 'Offline intake synchronized', 'An offline intake was synchronized. Open your authorized case worklist to review it.', route('cases.index'));

                return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $caseId, 'case_number' => $caseNumber]);
            }
            if ($data['resource'] === 'report_intake') {
                abort_unless($data['operation'] === 'create', 422, 'Offline intake drafts can only be created.');
                $payload = validator($data['payload'], [
                    'narrative' => 'required|string|max:5000', 'source' => 'required|string|max:100', 'source_other' => 'nullable|string|max:255',
                    'reporter_name' => 'nullable|string|max:255', 'reporter_contact' => 'nullable|string|max:100',
                    'subject_first' => 'nullable|string|max:80', 'subject_last' => 'nullable|string|max:80', 'subject_middle' => 'nullable|string|max:80',
                    'subject_birthdate' => 'nullable|date|before_or_equal:today', 'subject_age' => 'nullable|integer|min:0|max:25', 'subject_sex' => 'nullable|string|max:24',
                    'category' => 'nullable|string|max:100', 'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120',
                    'municipality' => 'required|string|max:120', 'barangay' => 'nullable|string|max:120',
                    'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'geo_accuracy_m' => 'nullable|numeric|min:0', 'geo_source' => 'nullable|string|max:40',
                    'priority' => 'required|in:low,normal,high,urgent',
                ])->validate();
                DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($payload), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
                $reportId = DB::table('reports')->insertGetId($payload + [
                    'reference_no' => $this->references->nextReport(),
                    'status' => 'FOR_SCREENING', 'is_public_submission' => true, 'endorsed_municipality' => $payload['municipality'], 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.intake_synced', 'auditable_type' => 'report', 'auditable_id' => $reportId, 'changes' => json_encode(['offline_id' => $data['offline_id']]), 'created_at' => now(), 'updated_at' => now()]);

                return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'report_id' => $reportId]);
            }
            $case = DB::table('cases')->where('id', $data['payload']['case_id'] ?? 0)->whereNull('deleted_at')->first();
            abort_unless($case, 422, 'The case is no longer available.');
            if ($case->assigned_to !== $request->user()->id) {
                abort(403);
            }
            if (!empty($data['base_version']) && $data['base_version'] !== $case->offline_version) {
                DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($data['payload']), 'status' => 'conflict', 'conflict_reason' => 'The case changed while this item was offline.', 'updated_at' => now(), 'created_at' => now()]);

                return response()->json(['message' => 'Sync conflict: refresh the case before resolving this queued item.', 'current_version' => $case->offline_version], 409);
            }
            DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($data['payload']), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
            if ($data['resource'] === 'case_note') {
                validator($data['payload'], ['case_id' => 'required|integer', 'body' => 'required|string|max:5000'])->validate();
                DB::table('case_notes')->insert(['case_id' => $case->id, 'body' => $data['payload']['body'], 'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note', 'note_type' => 'other', 'occurred_at' => now(), 'finalized_at' => now(), 'finalized_by' => $request->user()->id, 'is_current' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                validator($data['payload'], ['case_id' => 'required|integer', 'visited_at' => 'required|date', 'observations' => 'required|string|max:5000'])->validate();
                DB::table('monitoring_visits')->insert(['case_id' => $case->id, 'visited_at' => $data['payload']['visited_at'], 'location' => $data['payload']['location'] ?? null, 'observations' => $data['payload']['observations'], 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('cases')->where('id', $case->id)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.sync', 'auditable_type' => 'case', 'auditable_id' => $case->id, 'changes' => json_encode(['resource' => $data['resource']]), 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $case->id]);
        });
    }

    private function samePayload(array $stored, array $incoming): bool
    {
        $canonical = function (array $value) use (&$canonical): array {
            ksort($value);
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $canonical($item);
                }
            }

            return $value;
        };

        // Validation may have discarded unsupported input keys on first upload.
        return $canonical($stored) === $canonical(array_intersect_key($incoming, $stored));
    }

    private function requireActiveDevice(Request $request): void
    {
        $deviceId = (string) $request->header('X-Kalinga-Device-ID');
        abort_unless((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceId), 422, 'A registered offline device is required.');
        $device = DB::table('offline_devices')->where('user_id', $request->user()->id)->where('device_id', $deviceId)->whereNull('revoked_at')->lockForUpdate()->first();
        abort_unless($device && now()->lte($device->pack_expires_at), 403, 'This offline pack has expired or the device was revoked. Download a fresh authorized pack before syncing.');
        DB::table('offline_devices')->where('id', $device->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
    }
}
