<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Central allowlist and privacy-safe record governance operations. */
class RecordsGovernance
{
    public const TYPES = ['case', 'document'];

    public const DISPOSAL_EXECUTION_ENABLED = false;

    public function assertType(string $type): void
    {
        abort_unless(in_array($type, self::TYPES, true), 422, 'Unsupported governance record type.');
    }

    public function document(int $caseId, int $documentId): object
    {
        return DB::table('documents')->where('case_id', $caseId)->where('id', $documentId)->whereNull('deleted_at')->firstOrFail();
    }

    public function ensureDocument(int $caseId, int $documentId, bool $legacy = true): object
    {
        $this->assertType('document');
        $document = $this->document($caseId, $documentId);
        $existing = DB::table('record_governance')->where('record_type', 'document')->where('record_id', $documentId)->first();
        if ($existing) {
            return $existing;
        }
        $id = DB::table('record_governance')->insertGetId(['case_id' => $caseId, 'record_type' => 'document', 'record_id' => $documentId, 'lifecycle_state' => 'active', 'scan_state' => $legacy ? 'legacy_unverified' : 'pending_scan', 'legacy_record' => $legacy, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('record_governance')->find($id);
    }

    public function activeHold(int $caseId, string $type, int $recordId): bool
    {
        $caseHold = DB::table('legal_holds')->where('case_id', $caseId)->where('scope', 'case')->whereNull('released_at')->exists();
        if ($caseHold) {
            return true;
        }

        return DB::table('legal_holds')->join('legal_hold_records', 'legal_hold_records.legal_hold_id', '=', 'legal_holds.id')->where('legal_holds.case_id', $caseId)->where('legal_holds.scope', 'record')->whereNull('legal_holds.released_at')->where('legal_hold_records.record_type', $type)->where('legal_hold_records.record_id', $recordId)->exists();
    }

    public function canViewDocument(User $user, object $governance): bool
    {
        if (!$governance->record_classification_id) {
            return true;
        }
        $classification = DB::table('record_classifications')->find($governance->record_classification_id);

        return !$classification || !$classification->restricted || $user->hasPermission('records.restricted_view');
    }

    public function safeDocument(object $document, ?object $governance, User $user): array
    {
        $restricted = $governance && !$this->canViewDocument($user, $governance);

        return $restricted ? ['id' => null, 'restricted' => true, 'name' => 'Restricted document', 'version' => null, 'created_at' => $document->created_at, 'scan_state' => $governance->scan_state, 'lifecycle_state' => $governance->lifecycle_state] : [
            'id' => $document->id, 'restricted' => false, 'name' => $document->name, 'version' => $document->version, 'created_at' => $document->created_at,
            'uploaded_by_name' => isset($document->uploaded_by) ? DB::table('users')->where('id', $document->uploaded_by)->value('name') : null,
            'scan_state' => $governance?->scan_state ?? 'legacy_unverified', 'lifecycle_state' => $governance?->lifecycle_state ?? 'active',
            'classification' => $governance?->record_classification_id ? DB::table('record_classifications')->where('id', $governance->record_classification_id)->value('label') : null,
        ];
    }

    public function newLineage(): string
    {
        return (string) Str::uuid();
    }
}
