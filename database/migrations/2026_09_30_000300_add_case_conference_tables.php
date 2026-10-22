<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('source_type', 80)->nullable()->index()->after('status');
            $table->unsignedBigInteger('source_id')->nullable()->index()->after('source_type');
        });
        Schema::create('case_conferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('conference_type', 80);
            $table->string('other_type_reason', 500)->nullable();
            $table->string('status', 40)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('venue_mode', 100)->nullable();
            $table->string('facilitator_name', 180)->nullable();
            $table->string('recorder_name', 180)->nullable();
            $table->text('purpose')->nullable();
            $table->text('case_updates')->nullable();
            $table->text('key_issues')->nullable();
            $table->string('child_participation_status', 60)->nullable();
            $table->foreignId('child_participation_record_id')->nullable()->constrained('child_participation_records')->nullOnDelete();
            $table->foreignId('risk_assessment_id')->nullable()->constrained('risk_assessments')->nullOnDelete();
            $table->text('family_perspective')->nullable();
            $table->text('professional_observations')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->string('confidentiality', 40)->default('casework');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->text('review_comments')->nullable();
            $table->foreignId('previous_conference_id')->nullable()->constrained('case_conferences')->nullOnDelete();
            $table->string('record_type', 30)->default('conference');
            $table->text('addendum_reason')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'status']);
        });
        Schema::create('case_conference_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_conference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 180);
            $table->string('professional_role', 140)->nullable();
            $table->string('organization', 180)->nullable();
            $table->string('attendance_status', 40)->default('invited');
            $table->string('participation_mode', 60)->nullable();
            $table->string('conference_role', 80)->nullable();
            $table->timestamps();
        });
        Schema::create('case_conference_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_conference_id')->constrained()->cascadeOnDelete();
            $table->string('decision_type', 20);
            $table->text('statement');
            $table->string('status', 40)->default('recorded');
            $table->timestamps();
        });
        Schema::create('case_conference_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_conference_id')->constrained()->cascadeOnDelete();
            $table->text('action');
            $table->string('category', 100)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_office', 180)->nullable();
            $table->timestamp('target_at')->nullable();
            $table->string('priority', 30)->default('normal');
            $table->string('local_status', 40)->default('pending');
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('outcome_remarks')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });
        Schema::create('case_conference_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_conference_id')->constrained()->cascadeOnDelete();
            $table->string('event', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('prior_scheduled_at')->nullable();
            $table->timestamp('new_scheduled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_conference_history');
        Schema::dropIfExists('case_conference_actions');
        Schema::dropIfExists('case_conference_decisions');
        Schema::dropIfExists('case_conference_participants');
        Schema::dropIfExists('case_conferences');
        Schema::table('tasks', fn (Blueprint $table) => [$table->dropIndex(['source_type']), $table->dropIndex(['source_id']), $table->dropColumn(['source_type', 'source_id'])]);
    }
};
