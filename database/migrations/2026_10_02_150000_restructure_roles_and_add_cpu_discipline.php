<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cpu_discipline', 40)->nullable()->after('role');
        });

        // Merge removed roles into the new structure
        DB::table('users')->where('role', 'case_worker')->update(['role' => 'social_worker']);
        DB::table('users')->where('role', 'cpu_monitor')->update(['role' => 'cpu_member']);
        DB::table('users')->where('role', 'mdt_member')->update(['role' => 'cpu_member']);
        DB::table('users')->where('role', 'cpo')->update(['role' => 'social_worker']);
        DB::table('users')->where('role', 'lgu_supervisor')->update(['role' => 'social_worker']);
        // Deactivate users with roles that have no equivalent in the new structure
        DB::table('users')->whereIn('role', ['referral_provider', 'health_provider', 'barangay_user', 'data_encoder', 'analytics_focal', 'data_custodian'])
            ->update(['role' => 'social_worker', 'active' => false]);

        // Remove old role definitions from roles table
        DB::table('roles')->whereNotIn('code', [
            'system_admin', 'social_worker', 'cpu_member', 'fo_user',
        ])->delete();

        // Ensure new roles exist with correct names
        DB::table('roles')->updateOrInsert(
            ['code' => 'social_worker'],
            ['name' => 'Social Worker / Case Manager', 'updated_at' => now()]
        );
        DB::table('roles')->updateOrInsert(
            ['code' => 'cpu_member'],
            ['name' => 'CPU Member', 'updated_at' => now()]
        );

        // Also update any pending registration requested_role values
        DB::table('users')->where('requested_role', 'case_worker')->update(['requested_role' => 'social_worker']);
        DB::table('users')->where('requested_role', 'cpo')->update(['requested_role' => 'social_worker']);
        DB::table('users')->where('requested_role', 'lgu_supervisor')->update(['requested_role' => 'social_worker']);
        DB::table('users')->whereIn('requested_role', ['cpu_monitor', 'mdt_member'])->update(['requested_role' => 'cpu_member']);
        DB::table('users')->whereIn('requested_role', ['barangay_user', 'data_encoder', 'referral_provider', 'health_provider', 'analytics_focal', 'data_custodian'])->update(['requested_role' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cpu_discipline');
        });
    }
};
