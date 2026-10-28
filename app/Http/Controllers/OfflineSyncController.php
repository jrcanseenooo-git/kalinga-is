<?php

namespace App\Http\Controllers;

use App\Services\Aftercare;
use App\Services\CaseForms;
use App\Services\CaseIntake;
use App\Services\IdentityIntegrity;
use App\Services\Kavat;
use App\Services\LguAssignmentScope;
use App\Services\OperationalNotification;
use App\Services\ReferenceNumberService;
use App\Services\Supervisors;
use App\Support\AuditLog;
use App\Support\PhilippineDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $data = $request->validate(['offline_id' => 'required|uuid', 'resource' => 'required|in:case_note,monitoring_visit,aftercare_visit,report_intake,case_intake,case_form,kavat', 'operation' => 'required|in:create,update', 'payload' => 'required|array', 'base_version' => 'nullable|integer|min:1']);

        return DB::transaction(function () use ($data, $request) {
            // Serialize retries from the same account before checking the UUID.
            // The existing unique offline_id constraint remains the final guard.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $this->requireActiveDevice($request);
            $document = in_array($data['resource'], ['case_form', 'kavat'], true);
            abort_unless($data['operation'] === ($document ? 'update' : 'create'), 422, $document ? 'Kalinga forms and the KaVAT are saved as updates.' : 'This offline resource supports creation only.');
            $existing = DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->first();
            if ($existing) {
                abort_unless((int) $existing->user_id === (int) $request->user()->id, 403);
                $same = $document ? (json_decode($existing->payload, true)['sha256'] ?? null) === $this->digest($data['payload']) : $this->samePayload(json_decode($existing->payload, true), $data['payload']);
                abort_unless($existing->resource === $data['resource'] && $same, 422, 'This offline identifier belongs to a different operation.');
            }
            if (in_array($data['resource'], ['case_note', 'monitoring_visit', 'aftercare_visit', 'case_form', 'kavat'], true)) {
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
            if ($document) {
                return $this->syncDocument($data, $case, $request);
            }
            if ($data['resource'] === 'aftercare_visit') {
                return $this->syncAftercareVisit($data, $case, $request);
            }
            if ($data['resource'] === 'case_intake') {
                abort_unless($data['operation'] === 'create', 422, 'Offline case intakes can only be created.');
                $intake = app(CaseIntake::class);
                $validator = validator($data['payload'], $intake->rules());
                $validator->after(fn ($v) => $intake->after($v));
                $payload = $validator->validate();
                $municipality = $payload['municipality'] ?? '';
                abort_unless($municipality !== '' && $this->lgus->matchesMunicipality($request->user(), $municipality), 403, 'Offline case intake must match your LGU assignment.');
                DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($this->storable($payload)), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
                $lgu = DB::table('lgu_units')->whereIn('id', $this->lgus->activeLguIds($request->user()) ?: [0])->whereRaw('lower(name) = ?', [mb_strtolower($municipality)])->first();
                [$caseId, $caseNumber] = $intake->create($payload, $request->user(), ['lgu_id' => $lgu?->id, 'location' => $lgu, 'source' => 'field_mode_intake']);
                DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.case_intake_synced', 'auditable_type' => 'case', 'auditable_id' => $caseId, 'changes' => json_encode(['offline_id' => $data['offline_id'], 'case_number' => $caseNumber])]);
                $this->notifications->send($request->user()->id, 'case.synced', 'Offline intake synchronized', 'An offline intake was synchronized. Open your authorized case worklist to review it.', route('cases.index'));

                return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $caseId, 'case_number' => $caseNumber]);
            }
            if ($data['resource'] === 'report_intake') {
                abort_unless($data['operation'] === 'create', 422, 'Offline intake drafts can only be created.');
                $payload = validator($data['payload'], [
                    'narrative' => 'required|string|max:5000', 'source' => 'required|string|max:100', 'source_other' => 'nullable|string|max:255',
                    'reporter_name' => 'nullable|string|max:255', 'reporter_contact' => 'nullable|string|max:100',
                    'subject_first' => 'nullable|string|max:80', 'subject_last' => 'nullable|string|max:80', 'subject_middle' => 'nullable|string|max:80',
                    'subject_birthdate' => 'nullable|date|before_or_equal:' . PhilippineDate::today(), 'subject_age' => 'nullable|integer|min:0|max:25', 'subject_sex' => 'nullable|string|max:24',
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
                AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.sync_conflict', 'auditable_type' => 'case', 'auditable_id' => $case->id, 'changes' => json_encode(['resource' => $data['resource'], 'offline_id' => $data['offline_id']]), 'outcome' => 'failure']);

                return response()->json(['message' => 'Sync conflict: refresh the case before resolving this queued item.', 'current_version' => $case->offline_version], 409);
            }
            DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($data['payload']), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
            if ($data['resource'] === 'case_note') {
                // Field mode records a full progress note: type, when it happened, action and next steps.
                // Items queued by older versions carry only the text; they keep working.
                $note = validator($data['payload'], ['case_id' => 'required|integer', 'body' => 'required|string|max:5000',
                    'note_type' => 'nullable|in:contact,monitoring,coordination,case_review,other', 'note_type_other' => 'required_if:note_type,other|nullable|string|max:120',
                    'occurred_at' => 'nullable|date|before_or_equal:' . now()->addMinutes(5)->toIso8601String(), 'action_taken' => 'nullable|string|max:5000', 'next_steps' => 'nullable|string|max:5000'])->validate();
                $type = $note['note_type'] ?? 'other';
                DB::table('case_notes')->insert(['case_id' => $case->id, 'body' => $note['body'], 'action_taken' => $note['action_taken'] ?? null, 'next_steps' => $note['next_steps'] ?? null,
                    'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note', 'note_type' => $type,
                    'note_type_other' => $type === 'other' ? (trim((string) ($note['note_type_other'] ?? '')) ?: 'Field mode note') : null,
                    'occurred_at' => isset($note['occurred_at']) ? Carbon::parse($note['occurred_at'])->utc() : now(), 'finalized_at' => now(), 'finalized_by' => $request->user()->id, 'is_current' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $visit = validator($data['payload'], ['case_id' => 'required|integer', 'visited_at' => 'required|date|before_or_equal:' . PhilippineDate::today(), 'observations' => 'required|string|max:5000',
                    'location' => 'nullable|string|max:255', 'risks' => 'nullable|string|max:5000', 'next_steps' => 'nullable|string|max:5000'])->validate();
                DB::table('monitoring_visits')->insert(['case_id' => $case->id, 'visited_at' => $visit['visited_at'], 'location' => $visit['location'] ?? null, 'observations' => $visit['observations'],
                    'risks' => $visit['risks'] ?? null, 'next_steps' => $visit['next_steps'] ?? null, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('cases')->where('id', $case->id)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.sync', 'auditable_type' => 'case', 'auditable_id' => $case->id, 'changes' => json_encode(['resource' => $data['resource']]), 'created_at' => now(), 'updated_at' => now()]);

            return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $case->id]);
        });
    }

    /**
     * An aftercare follow-up visit recorded in field mode for a closed case
     * (the case stays closed). Same rules as online; "at risk" tells the
     * supervisor and suggests reopening.
     */
    private function syncAftercareVisit(array $data, object $case, Request $request)
    {
        abort_unless($request->user()->hasPermission('cases.update'), 403);
        abort_unless($case->status === 'CLOSED', 422, 'Follow-up visits are recorded after the case is closed.');
        $plan = Aftercare::plan((int) $case->id);
        abort_unless($plan, 422, 'The case has no aftercare plan.');
        $visit = validator($data['payload'], ['case_id' => 'required|integer', 'visited_on' => 'required|date|before_or_equal:' . PhilippineDate::today(),
            'status' => ['required', Rule::in(array_keys(Aftercare::STATUSES))], 'notes' => 'required|string|min:5|max:5000'])->validate();
        DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']], ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode($data['payload']), 'status' => 'syncing', 'updated_at' => now(), 'created_at' => now()]);
        $id = DB::table('aftercare_visits')->insertGetId(['case_id' => $case->id, 'aftercare_plan_id' => $plan->id, 'visited_on' => $visit['visited_on'], 'status' => $visit['status'], 'notes' => $visit['notes'],
            'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('offline_sync_items')->where('offline_id', $data['offline_id'])->update(['status' => 'synced', 'updated_at' => now()]);
        AuditLog::record('aftercare.visit_recorded', 'case', (int) $case->id, ['case_id' => $case->id, 'visit_id' => $id, 'status' => $visit['status'], 'offline' => true], $request->user()->id, 'success',
            ['description' => 'Aftercare follow-up visit recorded in field mode: ' . Aftercare::STATUSES[$visit['status']]]);
        if ($visit['status'] === 'at_risk') {
            foreach (Supervisors::forLgu($case->lgu_id) as $supervisor) {
                $this->notifications->send($supervisor, 'aftercare_at_risk', 'Aftercare: child at risk', "{$case->case_number} - the follow-up visit found the child at risk. Consider reopening the case.", "/cases/{$case->id}?section=closure");
            }
        }

        return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $case->id]);
    }

    /**
     * A Kalinga form or KaVAT written in field mode. Saved only when the
     * device edited the latest revision; otherwise the item becomes a
     * conflict for the case manager to review (nothing is overwritten).
     */
    private function syncDocument(array $data, object $case, Request $request)
    {
        abort_unless($request->user()->hasPermission('cases.update'), 403);
        abort_if($case->status === 'CLOSED', 422, 'This case is closed. Reopen it before changing its forms.');
        $payload = $data['payload'];
        $mark = fn (string $status, ?string $reason = null) => DB::table('offline_sync_items')->updateOrInsert(['offline_id' => $data['offline_id']],
            ['user_id' => $request->user()->id, 'resource' => $data['resource'], 'payload' => json_encode(['case_id' => $case->id, 'form_type' => $payload['form_type'] ?? null, 'sha256' => $this->digest($payload)]),
                'status' => $status, 'conflict_reason' => $reason, 'updated_at' => now(), 'created_at' => now()]);
        $conflict = function (int $current) use ($mark, $case, $data, $request) {
            $mark('conflict', 'Changed on another device while this was offline.');
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'offline.sync_conflict', 'auditable_type' => 'case', 'auditable_id' => $case->id,
                'changes' => json_encode(['resource' => $data['resource'], 'offline_id' => $data['offline_id']]), 'outcome' => 'failure']);

            return response()->json(['message' => 'This was changed on another device. Review it in field mode.', 'current_revision' => $current], 409);
        };

        if ($data['resource'] === 'case_form') {
            $form = validator($payload, ['form_type' => ['required', Rule::in(array_keys(CaseForms::TYPES))], 'revision' => 'required|integer|min:0', 'content' => 'required|array'])->validate();
            $result = CaseForms::save($case->id, $form['form_type'], $form['content'], (int) $form['revision'], $request->user()->id);
            if (!$result['saved']) {
                return $conflict($result['revision']);
            }
            $mark('synced');

            return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $case->id, 'form_type' => $form['form_type'], 'revision' => $result['revision']]);
        }

        $kavat = validator($payload, ['kavat_id' => 'nullable|integer|min:1', 'revision' => 'required|integer|min:0', 'answers' => 'required|array', 'notes' => 'nullable|string|max:5000',
            'occasion' => ['required', Rule::in(array_keys(Kavat::OCCASION_LABELS))], 'administered_at' => 'required|date|before_or_equal:' . PhilippineDate::today()])->validate();
        $answers = Kavat::cleanAnswers($kavat['answers']);
        $id = $kavat['kavat_id'] ?? null;
        if (!$id) {
            // Started in field mode: becomes the case's draft unless one was started elsewhere meanwhile.
            abort_unless(isset(Kavat::OCCASIONS[$kavat['occasion']]), 422, 'Only the Midline or the Endline KaVAT can be started.');
            $draft = DB::table('kavat_assessments')->where('case_id', $case->id)->where('status', 'draft')->value('id');
            // One Midline and one Endline per case: a second one started on another device is a conflict.
            if ($draft || DB::table('kavat_assessments')->where('case_id', $case->id)->where('occasion', $kavat['occasion'])->exists()) {
                return $conflict(0);
            }
            $id = DB::table('kavat_assessments')->insertGetId(['case_id' => $case->id, 'instrument_version' => Kavat::VERSION, 'occasion' => $kavat['occasion'], 'administered_at' => $kavat['administered_at'],
                'administered_by' => $request->user()->id, 'status' => 'draft', 'answers' => json_encode($answers), 'notes' => $kavat['notes'] ?? null, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::record('kavat.created', 'case', $case->id, ['case_id' => $case->id, 'occasion' => $kavat['occasion'], 'offline' => true], $request->user()->id, 'success', ['description' => 'KaVAT started in field mode']);
            $result = ['saved' => true, 'revision' => 1];
        } else {
            $result = CaseFormController::applyKavatSave($case->id, (int) $id, $answers, (int) $kavat['revision'], ['notes' => $kavat['notes'] ?? null, 'occasion' => $kavat['occasion'], 'administered_at' => $kavat['administered_at']]);
            if (!$result['saved']) {
                return $conflict($result['revision']);
            }
        }
        $mark('synced');

        return response()->json(['status' => 'synced', 'offline_id' => $data['offline_id'], 'case_id' => $case->id, 'kavat_id' => $id, 'revision' => $result['revision']]);
    }

    /** Fingerprint of a field-mode document, so a retry is recognized without keeping the narrative twice. */
    private function digest(array $payload): string
    {
        $sort = function ($value) use (&$sort) {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return hash('sha256', json_encode($sort($payload)));
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
        $incoming = $this->storable($incoming);

        return $canonical($stored) === $canonical(array_intersect_key($incoming, $stored));
    }

    /** The queued copy keeps a fingerprint of the consent form photo, not the photo. */
    private function storable(array $payload): array
    {
        if (!empty($payload['consent_photo']) && is_string($payload['consent_photo'])) {
            $payload['consent_photo'] = 'sha256:' . hash('sha256', $payload['consent_photo']);
        }

        return $payload;
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
