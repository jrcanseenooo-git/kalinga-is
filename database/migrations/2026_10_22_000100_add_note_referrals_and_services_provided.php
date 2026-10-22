<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals and services recorded from progress notes (earlier Kalinga design).
 * - referrals: who it went to, why, the note it came from, and the CPU member
 *   whose case access it opened.
 * - services_provided: services the child or family actually received,
 *   with the amount for financial assistance.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('referred_to', 160)->nullable()->after('service_category');
            $table->string('purpose', 160)->nullable()->after('referred_to');
            $table->foreignId('case_note_id')->nullable()->after('purpose')->constrained('case_notes')->nullOnDelete();
            $table->foreignId('lgu_cpu_member_id')->nullable()->after('case_note_id')->constrained('lgu_cpu_members')->nullOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->after('lgu_cpu_member_id')->constrained('users')->nullOnDelete();
            $table->foreignId('mdt_case_access_id')->nullable()->after('recipient_user_id')->constrained('mdt_case_access')->nullOnDelete();
            $table->date('target_date')->nullable()->after('mdt_case_access_id');
        });

        Schema::create('services_provided', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 60);
            $table->string('specific_detail', 255)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('date_provided');
            $table->string('provided_by', 160)->nullable();
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->foreignId('case_note_id')->nullable()->constrained('case_notes')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'date_provided']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services_provided');
        Schema::table('referrals', function (Blueprint $table) {
            foreach (['mdt_case_access_id', 'recipient_user_id', 'lgu_cpu_member_id', 'case_note_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['referred_to', 'purpose', 'target_date']);
        });
    }
};
