<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A referral conducted from a row of the Intervention Plan keeps that row's
 * key, so the plan shows the referral and its status next to the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('plan_row_key', 40)->nullable();
            $table->index(['case_id', 'plan_row_key'], 'referrals_plan_row');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex('referrals_plan_row');
            $table->dropColumn('plan_row_key');
        });
    }
};
