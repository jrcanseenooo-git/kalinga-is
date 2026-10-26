<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New intake (4 steps, owner request 2026-10-26):
 * - addresses for the primary caregiver and each family member, with a
 *   "same address as the child" choice, and whether the permanent address
 *   is the same as the present address;
 * - civil status of family members (Social Case Study Report table);
 * - the risk points behind the initial risk level, the level the system
 *   suggested, and the reason when the case manager chose a lower level;
 * - the photo of the signed consent / assent form (stored as a document).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('permanent_same_as_present')->nullable()->after('permanent_barangay');
            $table->boolean('caregiver_same_address')->nullable()->after('primary_caregiver_contact');
            $table->string('caregiver_house_no_street')->nullable()->after('caregiver_same_address');
            $table->string('caregiver_region', 120)->nullable()->after('caregiver_house_no_street');
            $table->string('caregiver_province', 120)->nullable()->after('caregiver_region');
            $table->string('caregiver_municipality', 120)->nullable()->after('caregiver_province');
            $table->string('caregiver_barangay', 120)->nullable()->after('caregiver_municipality');
        });
        Schema::table('relationships', function (Blueprint $table) {
            $table->string('civil_status', 80)->nullable()->after('sex');
            $table->boolean('same_address_as_client')->nullable()->after('occupation');
            $table->string('house_no_street')->nullable()->after('same_address_as_client');
            $table->string('region', 120)->nullable()->after('house_no_street');
            $table->string('province', 120)->nullable()->after('region');
            $table->string('municipality', 120)->nullable()->after('province');
            $table->string('barangay', 120)->nullable()->after('municipality');
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->decimal('risk_points', 6, 2)->nullable()->after('initial_risk_level');
            $table->string('suggested_risk_level', 20)->nullable()->after('risk_points');
            $table->string('risk_level_reason', 1000)->nullable()->after('suggested_risk_level');
            $table->string('consent_form_type', 30)->nullable()->after('privacy_notice_method');
            $table->foreignId('consent_document_id')->nullable()->after('consent_form_type')->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consent_document_id');
            $table->dropColumn(['risk_points', 'suggested_risk_level', 'risk_level_reason', 'consent_form_type']);
        });
        Schema::table('relationships', fn (Blueprint $table) => $table->dropColumn(['civil_status', 'same_address_as_client', 'house_no_street', 'region', 'province', 'municipality', 'barangay']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['permanent_same_as_present', 'caregiver_same_address', 'caregiver_house_no_street', 'caregiver_region', 'caregiver_province', 'caregiver_municipality', 'caregiver_barangay']));
    }
};
