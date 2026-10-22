<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('module');
            $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('case_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('relationship_type');
            $table->string('phone')->nullable();
            $table->boolean('is_guardian')->default(false);
            $table->timestamps();
        });
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('lawful_basis');
            $table->json('purposes');
            $table->boolean('sharing_allowed')->default(false);
            $table->timestamp('granted_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->json('responses')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('reference_data', function (Blueprint $table) {
            $table->id();
            $table->string('set_name');
            $table->string('code');
            $table->string('label');
            $table->text('definition')->nullable();
            $table->text('source')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['set_name', 'code']);
        });
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->string('form');
            $table->string('key');
            $table->string('label');
            $table->string('type');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['form', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['form_fields', 'reference_data', 'notifications', 'assessments', 'consents', 'relationships', 'case_status_history', 'permission_role', 'permissions', 'roles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
