<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The photo of a signed consent / assent form taken at intake.
 *
 * Only a JPEG, PNG or WebP image is accepted. The picture is decoded and
 * drawn again as a new JPEG, so nothing but pixels survives: no hidden
 * content, no location or camera data. The new file is stored privately
 * (never in a public folder) and is marked "sanitized", which makes it
 * available to the authorized case team without waiting for a malware scan.
 */
final class ConsentFormImage
{
    public const TYPES = ['consent', 'assent', 'consent_and_assent'];

    private const MAX_BYTES = 6 * 1024 * 1024;

    private const MAX_SIDE = 2000;

    public function __construct(private readonly RecordsGovernance $records) {}

    /** Validation rule for the data URL sent by the browser. */
    public static function rule(): array
    {
        return ['nullable', 'string', 'max:' . (int) ceil(self::MAX_BYTES * 4 / 3 + 64), 'regex:/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/=]+$/'];
    }

    /** Decodes, re-draws and stores the image; returns the document id. */
    public function store(string $dataUrl, int $caseId, int $userId, ?string $type, string $source = 'intake'): int
    {
        $jpeg = $this->sanitize($dataUrl);
        $disk = config('filesystems.default');
        $path = "cases/{$caseId}/consent/" . Str::uuid() . '.jpg';
        if (!Storage::disk($disk)->put($path, $jpeg)) {
            AuditLog::record('document.upload_failed', 'case', $caseId, ['case_id' => $caseId, 'source' => 'intake_consent'], $userId, 'failure');
            throw ValidationException::withMessages(['consent_photo' => 'The consent form photo could not be saved. Try again.']);
        }
        $label = ['consent' => 'Consent form', 'assent' => 'Assent form', 'consent_and_assent' => 'Consent and assent form'][$type ?? 'consent'] ?? 'Consent form';
        $id = DB::table('documents')->insertGetId(['case_id' => $caseId, 'lineage_id' => $this->records->newLineage(), 'name' => $label . ' (' . $source . ').jpg', 'disk' => $disk,
            'path' => $path, 'mime_type' => 'image/jpeg', 'version' => 1, 'uploaded_by' => $userId, 'source_module' => 'intake_consent', 'created_at' => now(), 'updated_at' => now()]);
        $governance = $this->records->ensureDocument($caseId, $id, false);
        DB::table('record_governance')->where('id', $governance->id)->update(['scan_state' => 'sanitized', 'updated_at' => now()]);
        AuditLog::record('document.uploaded', 'document', $id, ['case_id' => $caseId, 'source' => 'intake_consent', 'scan_state' => 'sanitized'], $userId,
            'success', ['description' => $label . ' photo attached (' . $source . ')']);

        return $id;
    }

    /** Store another kind of form photo (for example the KaVAT baseline sheet) with the same safeguards. */
    public function storeImage(string $dataUrl, int $caseId, int $userId, string $label, string $sourceModule): int
    {
        $jpeg = $this->sanitize($dataUrl);
        $disk = config('filesystems.default');
        $path = "cases/{$caseId}/{$sourceModule}/" . Str::uuid() . '.jpg';
        if (!Storage::disk($disk)->put($path, $jpeg)) {
            AuditLog::record('document.upload_failed', 'case', $caseId, ['case_id' => $caseId, 'source' => $sourceModule], $userId, 'failure');
            throw ValidationException::withMessages(['photo' => 'The photo could not be saved. Try again.']);
        }
        $id = DB::table('documents')->insertGetId(['case_id' => $caseId, 'lineage_id' => $this->records->newLineage(), 'name' => $label . '.jpg', 'disk' => $disk,
            'path' => $path, 'mime_type' => 'image/jpeg', 'version' => 1, 'uploaded_by' => $userId, 'source_module' => $sourceModule, 'created_at' => now(), 'updated_at' => now()]);
        $governance = $this->records->ensureDocument($caseId, $id, false);
        DB::table('record_governance')->where('id', $governance->id)->update(['scan_state' => 'sanitized', 'updated_at' => now()]);
        AuditLog::record('document.uploaded', 'document', $id, ['case_id' => $caseId, 'source' => $sourceModule, 'scan_state' => 'sanitized'], $userId, 'success', ['description' => $label . ' photo attached']);

        return $id;
    }

    private function sanitize(string $dataUrl): string
    {
        $fail = fn () => throw ValidationException::withMessages(['consent_photo' => 'Use a clear photo of the signed form (JPEG, PNG or WebP, up to 6 MB).']);
        if (!preg_match('/^data:image\/(jpeg|png|webp);base64,(.+)$/', $dataUrl, $m)) {
            $fail();
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            $fail();
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 50 || $info[1] < 50 || $info[0] > 10000 || $info[1] > 10000) {
            $fail();
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $fail();
        }
        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        [$w, $h] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);
        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($canvas);

        return $jpeg;
    }
}
