<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdt_case_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('professional_role', 120);
            $table->string('organization', 180);
            $table->string('purpose', 255);
            $table->string('status', 32)->default('assigned')->index();
            $table->json('allowed_categories');
            $table->text('remarks')->nullable();
            $table->string('lawful_basis', 180)->nullable();
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamp('assigned_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('access_starts_at')->nullable();
            $table->timestamp('access_expires_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'user_id', 'status']);
        });

        Schema::create('mdt_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mdt_access_id')->constrained('mdt_case_access')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('instructions')->nullable();
            $table->string('status', 32)->default('assigned')->index();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('mdt_case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mdt_access_id')->constrained('mdt_case_access')->cascadeOnDelete();
            $table->foreignId('previous_note_id')->nullable()->constrained('mdt_case_notes');
            $table->string('category', 100);
            $table->text('action_performed')->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('follow_up_needed')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['case_id', 'mdt_access_id']);
        });

        Schema::create('mdt_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mdt_access_id')->nullable()->constrained('mdt_case_access')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('mdt_discussions')->nullOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->text('body');
            $table->boolean('is_system_event')->default(false);
            $table->timestamps();
            $table->index(['case_id', 'created_at']);
        });

        Schema::create('mdt_discussion_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained('mdt_discussions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['discussion_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdt_discussion_reads');
        Schema::dropIfExists('mdt_discussions');
        Schema::dropIfExists('mdt_case_notes');
        Schema::dropIfExists('mdt_actions');
        Schema::dropIfExists('mdt_case_access');
    }
};
