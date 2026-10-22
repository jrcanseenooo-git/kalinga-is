<?php

namespace App\Services;

use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class FileScanService
{
    public function scanDocument(int $documentId): string
    {
        $governance = DB::table('record_governance')->where('record_type', 'document')->where('record_id', $documentId)->first();
        if (!$governance || $governance->scan_state !== 'pending_scan') {
            return $governance?->scan_state ?? 'unknown';
        }

        $document = DB::table('documents')->find($documentId);
        if (!$document) {
            return 'unknown';
        }

        $result = $this->scan($document->disk, $document->path);

        DB::table('record_governance')->where('id', $governance->id)->update([
            'scan_state' => $result,
            'updated_at' => now(),
        ]);
        if ($result !== 'pending_scan') {
            $this->recordOutcome('document', $documentId, $result);
        }

        return $result;
    }

    public function scanMdtAttachment(int $attachmentId): string
    {
        $attachment = DB::table('mdt_attachments')->find($attachmentId);
        if (!$attachment || $attachment->scan_state !== 'pending_scan') {
            return $attachment?->scan_state ?? 'unknown';
        }

        $result = $this->scan($attachment->disk, $attachment->path);

        DB::table('mdt_attachments')->where('id', $attachmentId)->update([
            'scan_state' => $result,
            'updated_at' => now(),
        ]);
        if ($result !== 'pending_scan') {
            $this->recordOutcome('mdt_attachment', $attachmentId, $result);
        }

        return $result;
    }

    private function scan(string $disk, string $path): string
    {
        if (!Storage::disk($disk)->exists($path)) {
            return 'file_missing';
        }

        $driver = config('kalinga.scan_driver', 'none');

        if ($driver === 'none') {
            return 'pending_scan';
        }

        if ($driver !== 'clamav') {
            return 'scan_error';
        }

        $stream = Storage::disk($disk)->readStream($path);
        if ($stream === false) {
            return 'scan_error';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'kalinga-scan-');
        if ($temporary === false) {
            fclose($stream);

            return 'scan_error';
        }

        try {
            $target = fopen($temporary, 'wb');
            if ($target === false) {
                return 'scan_error';
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);

            $process = new Process([
                (string) config('kalinga.clamav_binary', 'clamscan'),
                '--no-summary', '--stdout', $temporary,
            ]);
            $process->setTimeout((float) config('kalinga.scan_timeout_seconds', 120));
            $process->run();

            return match ($process->getExitCode()) {
                0 => 'cleared',
                1 => 'rejected_malware',
                default => 'scan_error',
            };
        } catch (\Throwable) {
            return 'scan_error';
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($temporary);
        }
    }

    private function recordOutcome(string $recordType, int $recordId, string $outcome): void
    {
        AuditLog::insert([
            'user_id' => null,
            'event' => "{$recordType}.scan_completed",
            'auditable_type' => $recordType,
            'auditable_id' => $recordId,
            'outcome' => $outcome,
            'changes' => json_encode(['scan_state' => $outcome, 'actor' => 'scheduled_scanner']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
