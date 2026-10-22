<?php

namespace App\Console\Commands;

use App\Services\FileScanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScanPendingUploads extends Command
{
    protected $signature = 'kalinga:scan-pending-uploads {--limit=100}';

    protected $description = 'Scan quarantined case documents and MDT attachments using the configured scanner';

    public function handle(FileScanService $scanner): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $documents = DB::table('record_governance')
            ->where('record_type', 'document')->where('scan_state', 'pending_scan')
            ->orderBy('id')->limit($limit)->pluck('record_id');

        $processed = 0;
        foreach ($documents as $id) {
            $scanner->scanDocument((int) $id);
            $processed++;
        }

        $remaining = max(0, $limit - $processed);
        if ($remaining > 0) {
            $attachments = DB::table('mdt_attachments')->where('scan_state', 'pending_scan')
                ->orderBy('id')->limit($remaining)->pluck('id');
            foreach ($attachments as $id) {
                $scanner->scanMdtAttachment((int) $id);
                $processed++;
            }
        }

        $this->info("Processed {$processed} quarantined upload(s).");

        return self::SUCCESS;
    }
}
