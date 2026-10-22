<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_plans', function (Blueprint $table) {
            $table->foreignId('previous_plan_id')->nullable()->after('case_id')->constrained('case_plans')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('previous_plan_id');
            $table->date('plan_date')->nullable()->after('version');
            $table->foreignId('authored_by')->nullable()->after('plan_date')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('authored_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('returned_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('returned_by');
            $table->text('review_comments')->nullable()->after('returned_at');
            $table->text('overall_objective')->nullable()->after('summary');
            $table->foreignId('comprehensive_assessment_id')->nullable()->after('overall_objective')->constrained('case_assessments')->nullOnDelete();
            $table->foreignId('risk_assessment_id')->nullable()->after('comprehensive_assessment_id')->constrained('risk_assessments')->nullOnDelete();
            $table->foreignId('child_participation_record_id')->nullable()->after('risk_assessment_id')->constrained('child_participation_records')->nullOnDelete();
            $table->boolean('is_current')->default(false)->after('status');
            $table->index(['case_id', 'version']);
        });
        Schema::create('case_plan_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_plan_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 80);
            $table->string('need_problem', 500);
            $table->text('goal_statement');
            $table->string('priority', 20)->default('normal');
            $table->date('target_date')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('outcome', 40)->nullable();
            $table->text('outcome_observations')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['case_plan_id', 'domain', 'status']);
        });
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('case_plan_goal_id')->nullable()->after('case_plan_id')->constrained('case_plan_goals')->nullOnDelete();
            $table->text('activity')->nullable()->after('service_category');
            $table->foreignId('responsible_user_id')->nullable()->after('activity')->constrained('users')->nullOnDelete();
            $table->string('responsible_office', 180)->nullable()->after('responsible_user_id');
            $table->foreignId('service_provider_id')->nullable()->after('responsible_office')->constrained('service_providers')->nullOnDelete();
            $table->date('start_date')->nullable()->after('service_provider_id');
            $table->string('priority', 20)->default('normal')->after('target_date');
            $table->text('expected_outcome')->nullable()->after('priority');
            $table->text('actual_outcome')->nullable()->after('expected_outcome');
            $table->string('outcome_status', 40)->nullable()->after('actual_outcome');
            $table->text('barriers')->nullable()->after('outcome_status');
            $table->text('next_action')->nullable()->after('barriers');
            $table->timestamp('completed_at')->nullable()->after('next_action');
            $table->string('origin_type', 80)->nullable()->after('completed_at');
            $table->unsignedBigInteger('origin_id')->nullable()->after('origin_type');
            $table->string('operational_record_type', 80)->nullable()->after('origin_id');
            $table->unsignedBigInteger('operational_record_id')->nullable()->after('operational_record_type');
            $table->index(['operational_record_type', 'operational_record_id']);
        });
        Schema::create('case_plan_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->date('review_date');
            $table->string('overall_progress', 40);
            $table->text('changed_needs')->nullable();
            $table->text('barriers')->nullable();
            $table->text('continuing_concerns')->nullable();
            $table->text('outcome_observations')->nullable();
            $table->text('recommendation')->nullable();
            $table->boolean('revision_required')->default(false);
            $table->date('next_review_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['case_plan_id', 'review_date']);
        });
        Schema::create('case_plan_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_plan_id')->constrained()->cascadeOnDelete();
            $table->string('event', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->index(['case_plan_id', 'created_at']);
        });
        Schema::create('case_reintegrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('applicable')->default(false);
            $table->text('basis')->nullable();
            $table->string('status', 30)->default('not_applicable');
            $table->foreignId('child_participation_record_id')->nullable()->constrained('child_participation_records')->nullOnDelete();
            $table->foreignId('risk_assessment_id')->nullable()->constrained('risk_assessments')->nullOnDelete();
            $table->text('continuity_plan')->nullable();
            $table->text('safeguards')->nullable();
            $table->string('responsible_office', 180)->nullable();
            $table->date('target_date')->nullable();
            $table->date('review_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'status']);
        });
        Schema::create('case_aftercare_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('required')->default(false);
            $table->text('basis')->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_office', 180)->nullable();
            $table->date('initial_follow_up_at')->nullable();
            $table->string('status', 30)->default('not_required');
            $table->text('continuity_notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'status']);
        });
        Schema::create('case_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('status', 40)->default('draft');
            $table->string('originating_office', 180);
            $table->string('receiving_office', 180);
            $table->foreignId('originating_worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receiving_worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('new_locality', 255)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('endorsed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('continuity_notes')->nullable();
            $table->text('clarification_comments')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'status']);
        });
        Schema::create('case_transfer_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_transfer_id')->constrained('case_transfers')->cascadeOnDelete();
            $table->string('event', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_transfer_history');
        Schema::dropIfExists('case_transfers');
        Schema::dropIfExists('case_aftercare_plans');
        Schema::dropIfExists('case_reintegrations');
        Schema::dropIfExists('case_plan_history');
        Schema::dropIfExists('case_plan_reviews');
        Schema::table('interventions', fn (Blueprint $t) => $t->dropColumn(['case_plan_goal_id', 'activity', 'responsible_user_id', 'responsible_office', 'service_provider_id', 'start_date', 'priority', 'expected_outcome', 'actual_outcome', 'outcome_status', 'barriers', 'next_action', 'completed_at', 'origin_type', 'origin_id', 'operational_record_type', 'operational_record_id']));
        Schema::dropIfExists('case_plan_goals');
        Schema::table('case_plans', fn (Blueprint $t) => $t->dropColumn(['previous_plan_id', 'version', 'plan_date', 'authored_by', 'reviewed_by', 'reviewed_at', 'returned_by', 'returned_at', 'review_comments', 'overall_objective', 'comprehensive_assessment_id', 'risk_assessment_id', 'child_participation_record_id', 'is_current']));
    }
};
