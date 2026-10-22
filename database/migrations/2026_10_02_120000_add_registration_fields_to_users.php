<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('region', 120)->nullable()->after('role');
            $table->string('account_status', 20)->default('approved')->index()->after('access_revoked_at');
            $table->string('requested_role', 60)->nullable()->after('account_status');
            $table->text('registration_note')->nullable()->after('requested_role');
            $table->timestamp('reviewed_at')->nullable()->after('registration_note');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['region', 'account_status', 'requested_role', 'registration_note', 'reviewed_at', 'reviewed_by']);
        });
    }
};
