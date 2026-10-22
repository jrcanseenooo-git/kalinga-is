<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdt_case_access', function (Blueprint $table) {
            $table->boolean('expire_on_completion')->default(true)->after('access_expires_at');
            $table->boolean('aftercare_allowed')->default(false)->after('expire_on_completion');
        });
        Schema::table('mdt_case_notes', function (Blueprint $table) {
            $table->string('record_type', 24)->default('note')->after('previous_note_id');
            $table->text('addendum_reason')->nullable()->after('record_type');
        });
        Schema::create('mdt_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mdt_access_id')->constrained('mdt_case_access')->cascadeOnDelete();
            $table->foreignId('mdt_action_id')->nullable()->constrained('mdt_actions')->nullOnDelete();
            $table->foreignId('mdt_note_id')->nullable()->constrained('mdt_case_notes')->nullOnDelete();
            $table->string('purpose_category', 100);
            $table->string('name');
            $table->string('disk')->default('private');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedInteger('size_bytes');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'mdt_access_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdt_attachments');
        Schema::table('mdt_case_notes', fn (Blueprint $t) => $t->dropColumn(['record_type', 'addendum_reason']));
        Schema::table('mdt_case_access', fn (Blueprint $t) => $t->dropColumn(['expire_on_completion', 'aftercare_allowed']));
    }
};
