<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('cefmu_situation', 60)->nullable()->after('case_type');
            $table->string('union_type', 60)->nullable()->after('cefmu_situation');
            $table->string('partner_age_band', 60)->nullable()->after('union_type');
            $table->string('education_status_at_intake', 100)->nullable()->after('partner_age_band');
            $table->string('pregnancy_parenting_status', 100)->nullable()->after('education_status_at_intake');
            $table->text('cefmu_context')->nullable()->after('pregnancy_parenting_status');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['cefmu_situation', 'union_type', 'partner_age_band', 'education_status_at_intake', 'pregnancy_parenting_status', 'cefmu_context']));
    }
};
