<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('active')->default(true)->index()->after('role');
            $table->timestamp('access_revoked_at')->nullable()->after('active');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('closure_reviewed_by')->nullable()->after('closure_summary')->constrained('users')->nullOnDelete();
            $table->timestamp('closure_reviewed_at')->nullable()->after('closure_reviewed_by');
            $table->unique('report_id');
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->string('lawful_basis', 40)->nullable()->after('reason');
            $table->foreignId('sharing_authorized_by')->nullable()->after('lawful_basis')->constrained('users')->nullOnDelete();
            $table->timestamp('sharing_authorized_at')->nullable()->after('sharing_authorized_by');
        });

        Schema::create('offline_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->timestamp('last_seen_at');
            $table->timestamp('pack_expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_devices');

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['sharing_authorized_by']);
            $table->dropColumn(['lawful_basis', 'sharing_authorized_by', 'sharing_authorized_at']);
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->dropUnique(['report_id']);
            $table->dropForeign(['closure_reviewed_by']);
            $table->dropColumn(['closure_reviewed_by', 'closure_reviewed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['active', 'access_revoked_at']);
        });
    }
};
