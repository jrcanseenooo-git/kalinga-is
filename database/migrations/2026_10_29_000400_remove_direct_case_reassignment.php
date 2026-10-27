<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-28: the direct "Assignment" (reassign case worker)
 * feature was removed. Its permission is dropped; formal handover in the case
 * plan workspace remains the way a case moves to another case manager.
 * Earlier case.reassigned audit entries are kept unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $id = DB::table('permissions')->where('code', 'cases.assign')->value('id');
        if ($id) {
            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $id)->delete();
            }
            DB::table('permissions')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        // The feature and its permission are not restored.
    }
};
