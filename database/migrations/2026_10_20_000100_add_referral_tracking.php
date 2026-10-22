<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: referral status history and the recorded outcome/reason of a referral.
 * Existing referrals keep their timestamps; no rows are rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->text('outcome')->nullable()->after('reason');
            $table->text('status_reason')->nullable()->after('outcome');
        });
        Schema::create('referral_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('remarks')->nullable();
            $table->text('outcome')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['referral_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_events');
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'status_reason']);
        });
    }
};
