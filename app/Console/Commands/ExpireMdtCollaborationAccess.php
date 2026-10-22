<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireMdtCollaborationAccess extends Command
{
    protected $signature = 'kalinga:expire-mdt-access {--dry-run}';

    protected $description = 'Terminates expired MDT collaboration access without deleting historical records.';

    public function handle(): int
    {
        $query = DB::table('mdt_case_access')->whereNull('revoked_at')->whereNotIn('status', ['revoked', 'completed', 'returned'])->whereNotNull('access_expires_at')->where('access_expires_at', '<=', now());
        $count = $query->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} grant(s) would expire.");

            return self::SUCCESS;
        }
        $query->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
        $this->info("{$count} grant(s) expired.");

        return self::SUCCESS;
    }
}
