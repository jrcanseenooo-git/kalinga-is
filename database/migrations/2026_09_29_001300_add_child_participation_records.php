<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_participation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_record_id')->nullable()->constrained('child_participation_records')->nullOnDelete();
            $table->uuid('lineage_key')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('record_type', 20)->default('formal_record'); // formal_record, full_revision, addendum
            $table->string('occasion_type', 40);
            $table->string('decision_title', 255);
            $table->date('participation_date');
            $table->string('participation_status', 40);
            $table->text('non_participation_reason')->nullable();
            $table->text('amendment_reason')->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('authored_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('review_comments')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->boolean('is_current')->default(false);
            // Fixed, controlled fields: this is not an arbitrary questionnaire or JSON form builder.
            $table->text('developmental_considerations')->nullable();
            $table->string('preferred_language', 120)->nullable();
            $table->string('communication_method', 255)->nullable();
            $table->text('accessibility_accommodation')->nullable();
            $table->string('interpreter_or_support', 255)->nullable();
            $table->string('trusted_adult_or_support_person', 255)->nullable();
            $table->string('location_environment', 255)->nullable();
            $table->boolean('privacy_reasonably_provided')->nullable();
            $table->boolean('comfortable_to_continue')->nullable();
            $table->text('child_views')->nullable();
            $table->text('direct_quote')->nullable();
            $table->text('worker_observation')->nullable();
            $table->text('reported_information')->nullable();
            $table->text('professional_consideration')->nullable();
            $table->text('child_preference')->nullable();
            $table->text('factors_considered')->nullable();
            $table->text('view_influence')->nullable();
            $table->string('decision_alignment', 30)->nullable();
            $table->text('best_interest_rationale')->nullable();
            $table->text('safeguards_mitigating_actions')->nullable();
            $table->boolean('outcome_explained_to_child')->nullable();
            $table->date('feedback_date')->nullable();
            $table->string('feedback_by', 255)->nullable();
            $table->string('feedback_method_language', 255)->nullable();
            $table->text('child_response_questions')->nullable();
            $table->text('follow_up_needed')->nullable();
            $table->text('sharing_preference')->nullable();
            $table->text('sharing_professional_consideration')->nullable();
            $table->text('necessary_disclosure_basis')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'status']);
            $table->index(['case_id', 'lineage_key', 'is_current']);
        });
        Schema::create('child_participation_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_participation_record_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->foreignId('actor_id')->constrained('users');
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->index(['child_participation_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_participation_workflow_events');
        Schema::dropIfExists('child_participation_records');
    }
};
