<?php

namespace Database\Seeders;

use App\Services\RolePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Idempotent, non-personal reference data safe to run during a production release. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RolePermissions::ROLES as $code => [$name, $description]) {
            DB::table('roles')->updateOrInsert(['code' => $code], ['name' => $name, 'description' => $description, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (RolePermissions::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(['code' => $permission], ['name' => str_replace('.', ' ', ucwords($permission, '.')), 'module' => strtok($permission, '.'), 'created_at' => now(), 'updated_at' => now()]);
        }
        $permissionIds = DB::table('permissions')->pluck('id', 'code');
        $roleIds = DB::table('roles')->pluck('id', 'code');
        foreach (RolePermissions::ROLES as $role => $_) {
            $roleId = $roleIds[$role];
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            foreach ((new RolePermissions)->rolePermissions($role) as $permission) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionIds[$permission]], []);
            }
        }
    }
}
