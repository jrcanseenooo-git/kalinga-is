<?php

use App\Services\RolePermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LGU profile and signatories: the office identity, logo and the people who
 * sign reports (office head / supervisor) plus the Child Protection Unit
 * composition. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lgu_id')->unique()->constrained('lgu_units')->restrictOnDelete();
            $table->string('office_name', 180)->nullable();
            $table->string('office_short_name', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_number', 60)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('logo_path', 255)->nullable();
            $table->string('head_name', 160)->nullable();
            $table->string('head_position', 160)->nullable();
            $table->string('head_designation', 160)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lgu_cpu_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lgu_id')->constrained('lgu_units')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('position', 160)->nullable();
            $table->string('office', 160)->nullable();
            $table->string('cpu_role', 80);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['lgu_id', 'active']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('position', 160)->nullable()->after('name');
        });

        // Mirror the new permissions in the reference tables (code remains the source of truth).
        if (Schema::hasTable('permissions') && Schema::hasTable('permission_role')) {
            $codes = ['lgu.profile.view', 'lgu.profile.manage'];
            foreach ($codes as $code) {
                DB::table('permissions')->updateOrInsert(['code' => $code], ['name' => 'LGU profile ' . substr($code, 12), 'module' => 'lgu', 'created_at' => now(), 'updated_at' => now()]);
            }
            $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id', 'code');
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
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('position'));
        Schema::dropIfExists('lgu_cpu_members');
        Schema::dropIfExists('lgu_profiles');
    }
};
