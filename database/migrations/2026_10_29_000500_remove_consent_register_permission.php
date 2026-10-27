<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-28: the separate Consent register on the case page
 * was removed because the Kalinga Intake privacy notice and the signed
 * consent / assent form already record consent. Its permission is dropped.
 * The consents table and its rows are kept for history: an active sharing
 * consent recorded there still counts for referrals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $id = DB::table('permissions')->where('code', 'consents.record')->value('id');
        if ($id) {
            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $id)->delete();
            }
            DB::table('permissions')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        // The register and its permission are not restored.
    }
};
