<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deactivate users that were assigned the viewer role (no valid system role)
        DB::table('users')->where('role', 'viewer')->update([
            'active' => false,
            'updated_at' => now(),
        ]);

        // Remove viewer from roles table
        DB::table('roles')->where('code', 'viewer')->delete();

        // Clear any pending registrations requesting the viewer role
        DB::table('users')
            ->where('requested_role', 'viewer')
            ->update(['requested_role' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('roles')->insert([
            'code' => 'viewer',
            'name' => 'Viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
