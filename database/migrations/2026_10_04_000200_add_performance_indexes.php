<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->index(['status', 'lgu_id'], 'cases_status_lgu_id_index');
            $table->index('category', 'cases_category_index');
        });

        Schema::table('legal_holds', function (Blueprint $table) {
            $table->index(['case_id', 'released_at'], 'legal_holds_case_id_released_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropIndex('cases_status_lgu_id_index');
            $table->dropIndex('cases_category_index');
        });

        Schema::table('legal_holds', function (Blueprint $table) {
            $table->dropIndex('legal_holds_case_id_released_at_index');
        });
    }
};
