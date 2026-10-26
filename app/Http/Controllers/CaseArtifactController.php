<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Services\RecordsGovernance;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CaseArtifactController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope, private readonly RecordsGovernance $records) {}

    private function permitted(Request $r, int $case, string $permission): void
    {
        $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->first();
        abort_unless($record, 404);
        $this->authorize($permission);
        $this->authorize('cases.view');
        $location = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
        abort_unless($location && $this->scope->allowsCase($record, $r->user(), $location->municipality, $location->province), 403);
    }

    public function upload(Request $r, int $case)
    {
        $this->permitted($r, $case, 'documents.upload');
        $d = $r->validate(['document' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'lineage_id' => 'nullable|uuid', 'previous_document_id' => 'nullable|integer']);
        $file = $d['document'];
        $disk = config('filesystems.default');
        $previous = null;
        if (!empty($d['previous_document_id'])) {
            $previous = $this->records->document($case, (int) $d['previous_document_id']);
        }$lineage = $d['lineage_id'] ?? $previous?->lineage_id ?? $this->records->newLineage();
        $version = $previous ? ((int) $previous->version + 1) : 1;
        $path = $file->store("cases/$case/quarantine", $disk);
        if ($path === false) {
            AuditLog::record('document.upload_failed', 'case', $case, ['case_id' => $case, 'disk' => $disk], null, 'failure');

            return back()->withErrors(['document' => 'The file could not be saved. Try again; if it keeps failing, tell your administrator.']);
        }
        $id = DB::table('documents')->insertGetId(['case_id' => $case, 'lineage_id' => $lineage, 'name' => $file->getClientOriginalName(), 'disk' => $disk, 'path' => $path, 'mime_type' => $file->getMimeType(), 'version' => $version, 'previous_document_id' => $previous?->id, 'uploaded_by' => $r->user()->id, 'source_module' => 'case_documents', 'created_at' => now(), 'updated_at' => now()]);
        $governance = $this->records->ensureDocument($case, $id, false);
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'document.uploaded_quarantined', 'auditable_type' => 'document', 'auditable_id' => $id, 'changes' => json_encode(['scan_state' => 'pending_scan']), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Document uploaded to quarantine and awaits an approved scan.');
    }

    public function download(Request $r, int $case, int $document)
    {
        $this->permitted($r, $case, 'documents.download');
        $d = $this->records->document($case, $document);
        $g = $this->records->ensureDocument($case, $document, true);
        abort_unless($this->records->canViewDocument($r->user(), $g), 404);
        abort_unless($g->legacy_record || in_array($g->scan_state, ['cleared', 'sanitized'], true), 423, 'Document is not available until scan clearance.');
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'document.downloaded', 'auditable_type' => 'document', 'auditable_id' => $d->id, 'changes' => json_encode(['restricted' => (bool) $g->record_classification_id]), 'created_at' => now(), 'updated_at' => now()]);

        return Storage::disk($d->disk)->download($d->path, $d->name);
    }

    public function monitoring(Request $r, int $case)
    {
        $this->permitted($r, $case, 'activities.manage');
        $d = $r->validate(['visited_at' => 'required|date', 'observations' => 'required|string|max:5000', 'location' => 'nullable|string|max:255', 'risks' => 'nullable|string|max:5000', 'next_steps' => 'nullable|string|max:5000']);
        DB::transaction(function () use ($r, $case, $d) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first() ?? abort(404);
            $actor = User::query()->where('id', $r->user()->id)->lockForUpdate()->first();
            DB::table('user_lgu_assignments')->where('user_id', $r->user()->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $eligibility = app(CaseAssignmentEligibility::class);
            abort_unless($actor && $actor->can('activities.manage') && $eligibility->canRead($actor, $record), 403);
            $id = DB::table('monitoring_visits')->insertGetId($d + ['case_id' => $case, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->where('id', $case)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $actor->id, 'event' => 'monitoring_visit.recorded', 'auditable_type' => 'monitoring_visit', 'auditable_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($eligibility->canRead($actor, $record), 403);
        }, 3);

        return back()->with('success', 'Monitoring visit recorded.');
    }
}
