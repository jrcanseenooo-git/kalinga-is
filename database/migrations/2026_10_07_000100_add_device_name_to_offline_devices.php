<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_devices', function (Blueprint $table) {
            $table->string('device_name', 80)->nullable()->after('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('offline_devices', function (Blueprint $table) {
            $table->dropColumn('device_name');
        });
    }
};
