<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalinga Vulnerability Assessment Tool administrations (App\Services\Kavat).
 * A draft is saved as the case manager answers; a final record cannot be
 * changed (a new administration is recorded instead). Scores are computed
 * on the server from the answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kavat_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('instrument_version', 20);
            $table->string('occasion', 20)->default('baseline');
            $table->date('administered_at');
            $table->foreignId('administered_by')->constrained('users');
            $table->string('status', 10)->default('draft');
            $table->json('answers');
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('part1_score')->nullable();
            $table->decimal('part2_score', 6, 2)->nullable();
            $table->decimal('empowerment_rate', 6, 2)->nullable();
            $table->decimal('objective_risk_rate', 6, 2)->nullable();
            $table->decimal('safety_rate', 6, 2)->nullable();
            $table->decimal('final_score', 6, 2)->nullable();
            $table->string('priority', 10)->nullable();
            $table->json('red_flags')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kavat_assessments');
    }
};
