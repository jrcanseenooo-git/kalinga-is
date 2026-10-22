<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->timestamp('privacy_notice_provided_at')->nullable()->after('cefmu_context');
            $table->string('privacy_notice_method', 80)->nullable()->after('privacy_notice_provided_at');
            $table->string('data_sharing_basis', 80)->nullable()->after('privacy_notice_method');
            $table->string('consent_status', 80)->nullable()->after('data_sharing_basis');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn([
            'privacy_notice_provided_at', 'privacy_notice_method', 'data_sharing_basis', 'consent_status',
        ]));
    }
};
