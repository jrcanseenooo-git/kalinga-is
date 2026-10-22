<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('assessment_type', 30);
            $table->timestamp('assessed_at');
            $table->string('overall_risk_level', 20);
            $table->boolean('immediate_protection_required')->default(false);
            $table->string('safety_plan_status', 30)->default('not_required');
            $table->text('required_action')->nullable();
            $table->string('responsible_person', 255)->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('next_reassessment_at')->nullable();
            $table->text('professional_rationale')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('assessed_by')->constrained('users');
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['case_id', 'assessed_at']);
        });

        Schema::create('risk_assessment_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_assessment_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 80);
            $table->string('finding_level', 30)->default('not_assessed');
            $table->boolean('is_protective_factor')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['risk_assessment_id', 'domain', 'is_protective_factor']);
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->string('current_risk_level', 20)->nullable()->after('initial_risk_level');
            $table->timestamp('current_risk_assessed_at')->nullable()->after('current_risk_level');
        });

        DB::table('permissions')->updateOrInsert(['code' => 'risk.assess'], ['name' => 'Risk Assess', 'module' => 'risk', 'created_at' => now(), 'updated_at' => now()]);
        $permissionId = DB::table('permissions')->where('code', 'risk.assess')->value('id');
        foreach (['case_worker', 'social_worker', 'cpo', 'lgu_supervisor', 'fo_user'] as $role) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');
            if ($roleId) {
                DB::table('permission_role')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], []);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['current_risk_level', 'current_risk_assessed_at']));
        Schema::dropIfExists('risk_assessment_factors');
        Schema::dropIfExists('risk_assessments');
        DB::table('permission_role')->whereIn('permission_id', DB::table('permissions')->where('code', 'risk.assess')->pluck('id'))->delete();
        DB::table('permissions')->where('code', 'risk.assess')->delete();
    }
};
