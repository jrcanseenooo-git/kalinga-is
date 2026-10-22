<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('region')->nullable()->after('phone');
        });
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('region')->nullable()->after('phone');
            $table->string('province')->nullable()->after('region');
            $table->string('barangay')->nullable()->after('municipality');
        });
    }

    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropColumn(['region', 'province', 'barangay']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('region');
        });
    }
};
