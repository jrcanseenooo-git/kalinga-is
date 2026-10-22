<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_units', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 20)->unique();
            $table->string('name', 160);
            $table->string('province', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['province', 'name']);
        });

        Schema::create('user_lgu_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lgu_id')->constrained('lgu_units')->restrictOnDelete();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'active', 'effective_from']);
            $table->index(['lgu_id', 'active']);
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('lgu_id')->nullable()->after('client_id')->constrained('lgu_units')->nullOnDelete();
            $table->index('lgu_id');
        });

        Schema::create('lgu_coordination_circles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lgu_id')->constrained('lgu_units')->cascadeOnDelete();
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique('lgu_id');
        });

        Schema::create('lgu_coordination_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained('lgu_coordination_circles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('membership_role', 80)->default('member');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['circle_id', 'user_id']);
        });

        Schema::create('lgu_coordination_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained('lgu_coordination_circles')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lgu_coordination_messages')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->string('case_reference', 80)->nullable();
            $table->text('body');
            $table->boolean('is_urgent')->default(false);
            $table->boolean('follow_up')->default(false);
            $table->timestamps();
            $table->index(['circle_id', 'created_at']);
            $table->index(['case_id', 'created_at']);
        });

        Schema::create('lgu_coordination_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('lgu_coordination_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('lgu_coordination_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('lgu_coordination_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['message_id', 'user_id']);
        });

        Schema::table('case_notes', function (Blueprint $table) {
            $table->string('source_type', 80)->nullable()->after('visibility');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->text('provenance_summary')->nullable()->after('source_id');
            $table->timestamp('captured_at')->nullable()->after('provenance_summary');
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('mdt_case_notes', function (Blueprint $table) {
            $table->string('source_type', 80)->nullable()->after('addendum_reason');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->text('provenance_summary')->nullable()->after('source_id');
            $table->timestamp('captured_at')->nullable()->after('provenance_summary');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('mdt_case_notes', fn (Blueprint $table) => $table->dropColumn(['source_type', 'source_id', 'provenance_summary', 'captured_at']));
        Schema::table('case_notes', fn (Blueprint $table) => $table->dropColumn(['source_type', 'source_id', 'provenance_summary', 'captured_at']));
        Schema::dropIfExists('lgu_coordination_message_reads');
        Schema::dropIfExists('lgu_coordination_message_mentions');
        Schema::dropIfExists('lgu_coordination_messages');
        Schema::dropIfExists('lgu_coordination_members');
        Schema::dropIfExists('lgu_coordination_circles');
        Schema::table('cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('lgu_id'));
        Schema::dropIfExists('user_lgu_assignments');
        Schema::dropIfExists('lgu_units');
    }
};
