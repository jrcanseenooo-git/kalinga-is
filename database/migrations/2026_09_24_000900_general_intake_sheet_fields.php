<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->string('middle_name')->nullable()->after('first_name');
            $t->string('suffix')->nullable()->after('last_name');
            $t->string('civil_status')->nullable()->after('sex');
            $t->string('religion')->nullable()->after('civil_status');
            $t->string('ip_category')->nullable()->after('religion');
            $t->string('education_attainment')->nullable()->after('ip_category');
            $t->string('house_no_street')->nullable()->after('phone');
            $t->string('permanent_house_no_street')->nullable()->after('house_no_street');
            $t->string('permanent_region')->nullable()->after('permanent_house_no_street');
            $t->string('permanent_province')->nullable()->after('permanent_region');
            $t->string('permanent_municipality')->nullable()->after('permanent_province');
            $t->string('permanent_barangay')->nullable()->after('permanent_municipality');
        });
        Schema::table('cases', function (Blueprint $t) {
            $t->string('mode_of_admission')->nullable()->after('priority');
            $t->string('referred_by')->nullable()->after('mode_of_admission');
            $t->date('referral_date')->nullable()->after('referred_by');
            $t->text('problem_presented')->nullable()->after('immediate_safety_concern');
            $t->text('initial_assessment')->nullable()->after('problem_presented');
            $t->text('plan_recommendations')->nullable()->after('initial_assessment');
            $t->text('intake_remarks')->nullable()->after('plan_recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn(['mode_of_admission', 'referred_by', 'referral_date', 'problem_presented', 'initial_assessment', 'plan_recommendations', 'intake_remarks']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['middle_name', 'suffix', 'civil_status', 'religion', 'ip_category', 'education_attainment', 'house_no_street', 'permanent_house_no_street', 'permanent_region', 'permanent_province', 'permanent_municipality', 'permanent_barangay']));
    }
};
