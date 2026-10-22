<?php

use App\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // `cefm_admin` was an unseeded, over-privileged new-system role. CPO is
        // its closest legacy functional equivalent; jurisdiction must be set by
        // an administrator before case data becomes available.
        DB::table('users')->where('role', 'cefm_admin')->update(['role' => 'cpo', 'updated_at' => now()]);
        DB::table('users')->where('role', 'admin')->update(['role' => 'system_admin', 'updated_at' => now()]);

        foreach (RolePermissions::ROLES as $code => [$name, $description]) {
            DB::table('roles')->updateOrInsert(['code' => $code], ['name' => $name, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (RolePermissions::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(['code' => $permission], ['name' => str_replace('.', ' ', ucwords($permission, '.')), 'module' => strtok($permission, '.'), 'created_at' => now(), 'updated_at' => now()]);
        }

        $roleIds = DB::table('roles')->pluck('id', 'code');
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        foreach (RolePermissions::ROLES as $role => $_) {
            $roleId = $roleIds[$role];
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            foreach ((new RolePermissions)->rolePermissions($role) as $permission) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]], []);
            }
        }

        DB::table('roles')->whereIn('code', ['cefm_admin', 'admin'])->delete();
    }

    public function down(): void
    {
        // Role remapping is intentionally not reversed: restoring an
        // over-privileged legacy alias would be unsafe.
    }
};
