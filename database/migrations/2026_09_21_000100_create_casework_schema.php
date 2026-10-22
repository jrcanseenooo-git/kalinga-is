<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birthdate')->nullable();
            $table->string('sex', 24)->nullable();
            $table->string('phone')->nullable();
            $table->string('barangay')->nullable();
            $table->string('municipality')->nullable();
            $table->string('province')->nullable();
            $table->json('identifiers')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['last_name', 'first_name']);
        });
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('NEW')->index();
            $table->string('source')->nullable();
            $table->text('narrative');
            $table->string('priority')->default('normal');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number')->unique();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('OPEN')->index();
            $table->string('stage')->default('intake');
            $table->string('priority')->default('normal');
            $table->string('category')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('retention_review_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['assigned_to', 'status']);
        });
        Schema::create('case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('visibility')->default('CASE_NOTES');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('case_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->date('review_due_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_plan_id')->constrained()->cascadeOnDelete();
            $table->string('need');
            $table->string('goal');
            $table->string('service_category');
            $table->date('target_date')->nullable();
            $table->string('progress')->default('not_started');
            $table->text('outcome')->nullable();
            $table->timestamps();
        });
        Schema::create('service_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('service_type');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('municipality')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('DRAFT')->index();
            $table->string('service_category');
            $table->text('reason')->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('monitoring_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->date('visited_at');
            $table->string('location')->nullable();
            $table->text('observations');
            $table->text('risks')->nullable();
            $table->text('next_steps')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->constrained('users');
            $table->string('title');
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('open')->index();
            $table->timestamps();
        });
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('outcome')->default('success');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });
        Schema::create('offline_sync_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('offline_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('resource');
            $table->json('payload');
            $table->string('status')->default('queued');
            $table->text('conflict_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['offline_sync_items', 'audit_logs', 'documents', 'tasks', 'monitoring_visits', 'referrals', 'service_providers', 'interventions', 'case_plans', 'case_notes', 'cases', 'reports', 'clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
