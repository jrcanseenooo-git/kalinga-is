<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_closure_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            // Slot 1 identifies the current record. NULL permits many historical versions.
            $table->unsignedTinyInteger('current_slot')->nullable();
            $table->text('summary')->nullable();
            $table->string('source', 40);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['case_id', 'version']);
            $table->unique(['case_id', 'current_slot']);
        });
        // No automatic backfill: surviving legacy text is captured only during
        // an authorized revision, review, closing or reopening transaction.
    }

    public function down(): void
    {
        Schema::dropIfExists('case_closure_records');
    }
};
