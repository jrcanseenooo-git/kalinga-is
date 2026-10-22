<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgu_reconciliation_queue', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->string('legacy_municipality', 120)->nullable();
            $table->string('legacy_province', 120)->nullable();
            $table->foreignId('proposed_lgu_id')->nullable()->constrained('lgu_units')->nullOnDelete();
            $table->string('match_status', 40); // unambiguous, ambiguous, unmatched, reviewed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['record_type', 'record_id']);
            $table->index(['match_status', 'record_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lgu_reconciliation_queue');
    }
};
