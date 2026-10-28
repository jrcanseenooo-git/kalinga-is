<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aftercare (owner, 2026-10-28): at closure the case manager writes an
 * aftercare plan for the child's return to the community; after closure the
 * follow-up visits are recorded with the child's status (stable, needs
 * support, at risk). The plan extends case_aftercare_plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_aftercare_plans', function (Blueprint $table) {
            $table->string('living_arrangement', 255)->nullable();
            $table->text('support_people')->nullable();
            $table->text('services_to_continue')->nullable();
            $table->text('risks_to_watch')->nullable();
            $table->string('follow_up_frequency', 20)->nullable();
            $table->date('follow_up_until')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('aftercare_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aftercare_plan_id')->nullable()->constrained('case_aftercare_plans')->nullOnDelete();
            $table->date('visited_on');
            $table->string('status', 20);
            $table->text('notes');
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'visited_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aftercare_visits');
        Schema::table('case_aftercare_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['living_arrangement', 'support_people', 'services_to_continue', 'risks_to_watch', 'follow_up_frequency', 'follow_up_until']);
        });
    }
};
