<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->json('cefmu_classifications')->nullable()->after('cefmu_situation');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn('cefmu_classifications'));
    }
};
