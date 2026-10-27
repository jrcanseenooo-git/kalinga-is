<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grants log per case (App\Services\CaseGrants): each grant released to the
 * child or family, with amount, date and fund source. A wrong entry is
 * voided with a reason, never deleted, so the totals stay auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('grant_type', 60);
            $table->decimal('amount', 12, 2);
            $table->date('released_at');
            $table->string('fund_source', 160)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->string('received_by', 160)->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['case_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_grants');
    }
};
