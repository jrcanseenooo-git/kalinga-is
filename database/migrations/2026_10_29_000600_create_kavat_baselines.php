<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-28: the baseline KaVAT is administered before intake
 * in the project's separate tool (a Google Sheet with formulas). Kalinga keeps
 * only its result, one per case, so the quarterly KaVATs done in Kalinga can
 * be compared with it. The answers themselves are not re-entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavat_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->unique()->constrained('cases')->cascadeOnDelete();
            $table->date('administered_at');
            $table->decimal('empowerment_rate', 5, 2);
            $table->decimal('safety_rate', 5, 2);
            $table->decimal('final_score', 5, 2);
            $table->string('priority', 20);
            $table->text('notes')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavat_baselines');
    }
};
