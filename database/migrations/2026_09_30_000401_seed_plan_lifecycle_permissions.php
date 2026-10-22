<?php

use App\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['plans.review', 'transfers.manage'] as $code) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                ['name' => str_replace('.', ' ', ucwords($code, '.')), 'module' => strtok($code, '.'), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $ids = DB::table('permissions')->whereIn('code', ['plans.review', 'transfers.manage'])->pluck('id', 'code');
        foreach (RolePermissions::ROLES as $role => $_) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');
            if (!$roleId) {
                continue;
            }
            foreach ((new RolePermissions)->rolePermissions($role) as $permission) {
                if (isset($ids[$permission])) {
                    DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $ids[$permission]], []);
                }
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', ['plans.review', 'transfers.manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('code', ['plans.review', 'transfers.manage'])->delete();
    }
};
