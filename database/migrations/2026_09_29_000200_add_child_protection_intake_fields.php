<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('safe_contact_method', 100)->nullable()->after('phone');
            $table->text('safe_contact_notes')->nullable()->after('safe_contact_method');
            $table->string('primary_caregiver_name', 160)->nullable()->after('safe_contact_notes');
            $table->string('primary_caregiver_relationship', 100)->nullable()->after('primary_caregiver_name');
            $table->string('primary_caregiver_contact', 80)->nullable()->after('primary_caregiver_relationship');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->string('initial_risk_level', 20)->nullable()->after('immediate_safety_concern');
            $table->json('risk_indicators')->nullable()->after('initial_risk_level');
            $table->text('immediate_actions')->nullable()->after('risk_indicators');
            $table->text('safety_plan')->nullable()->after('immediate_actions');
            $table->timestamp('next_review_at')->nullable()->after('safety_plan');
            $table->timestamp('intake_completed_at')->nullable()->after('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['initial_risk_level', 'risk_indicators', 'immediate_actions', 'safety_plan', 'next_review_at', 'intake_completed_at']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['safe_contact_method', 'safe_contact_notes', 'primary_caregiver_name', 'primary_caregiver_relationship', 'primary_caregiver_contact']));
    }
};
