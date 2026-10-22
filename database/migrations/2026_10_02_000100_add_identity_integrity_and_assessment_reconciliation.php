<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('identity_status', 32)->default('active')->index();
            $table->foreignId('canonical_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('identity_updated_at')->nullable();
        });

        Schema::create('client_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('identifier_type', 80);
            $table->text('protected_value')->nullable();
            $table->char('normalized_hash', 64)->nullable()->index();
            $table->string('display_hint', 64)->nullable();
            $table->string('verification_status', 32)->default('unverified');
            $table->string('source_type', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'identifier_type']);
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('active');
            $table->string('locality_hint', 255)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('household_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('membership_status', 32)->default('current');
            $table->string('source_type', 80)->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'membership_status']);
        });
        Schema::create('identity_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_a_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_b_id')->constrained('clients')->cascadeOnDelete();
            $table->string('candidate_key', 80)->unique();
            $table->string('status', 40)->default('potential_duplicate')->index();
            $table->json('matching_signals');
            $table->string('jurisdiction_context', 32)->default('same_scope');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_a_id', 'client_b_id']);
        });
        Schema::create('identity_review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('identity_duplicate_candidates')->cascadeOnDelete();
            $table->string('decision', 40);
            $table->text('rationale')->nullable();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
        Schema::create('client_identity_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('left_client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('right_client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('link_type', 50);
            $table->string('status', 32)->default('active');
            $table->text('rationale')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['left_client_id', 'right_client_id', 'link_type']);
        });
        Schema::create('client_identity_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->nullable()->constrained('identity_duplicate_candidates')->nullOnDelete();
            $table->foreignId('source_client_id')->constrained('clients');
            $table->foreignId('survivor_client_id')->constrained('clients');
            $table->string('status', 32)->default('proposed')->index();
            $table->text('reason');
            $table->string('source_signature', 64);
            $table->string('survivor_signature', 64);
            $table->foreignId('proposed_by')->constrained('users');
            $table->timestamp('proposed_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->text('correction_notes')->nullable();
            $table->timestamps();
            $table->unique(['source_client_id', 'status']);
            $table->index(['survivor_client_id', 'status']);
        });
        Schema::create('client_identity_merge_field_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_id')->constrained('client_identity_merges')->cascadeOnDelete();
            $table->string('field_name', 80);
            $table->string('resolution', 40);
            $table->text('frozen_value')->nullable();
            $table->text('rationale')->nullable();
            $table->timestamps();
            $table->unique(['merge_id', 'field_name']);
        });
        Schema::create('client_identity_merge_affected_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_id')->constrained('client_identity_merges')->cascadeOnDelete();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('from_client_id');
            $table->unsignedBigInteger('to_client_id');
            $table->timestamps();
            $table->unique(['merge_id', 'record_type', 'record_id']);
        });
        Schema::create('legacy_assessment_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legacy_assessment_id')->unique()->constrained('assessments')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_case_assessment_id')->nullable()->constrained('case_assessments')->nullOnDelete();
            $table->string('classification', 40);
            $table->string('validation_status', 40)->default('not_started');
            $table->json('unresolved_fields')->nullable();
            $table->string('migration_version', 40)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_assessment_reconciliations');
        Schema::dropIfExists('client_identity_merge_affected_records');
        Schema::dropIfExists('client_identity_merge_field_decisions');
        Schema::dropIfExists('client_identity_merges');
        Schema::dropIfExists('client_identity_links');
        Schema::dropIfExists('identity_review_decisions');
        Schema::dropIfExists('identity_duplicate_candidates');
        Schema::dropIfExists('household_memberships');
        Schema::dropIfExists('households');
        Schema::dropIfExists('client_identifiers');
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['identity_status', 'canonical_client_id', 'identity_updated_at']));
    }
};
