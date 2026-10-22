<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->string('case_type')->nullable()->after('category');
            $table->string('circumstance')->nullable()->after('case_type');
            $table->string('pregnancy_status')->nullable()->after('circumstance');
            $table->string('disability_evidence')->nullable()->after('pregnancy_status');
            $table->string('functional_difficulty')->nullable()->after('disability_evidence');
            $table->boolean('immediate_safety_concern')->default(false)->after('functional_difficulty');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn(['case_type', 'circumstance', 'pregnancy_status', 'disability_evidence', 'functional_difficulty', 'immediate_safety_concern']);
        });
    }
};
