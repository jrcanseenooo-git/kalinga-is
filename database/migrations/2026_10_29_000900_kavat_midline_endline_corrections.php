<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KaVAT (owner decisions 2026-10-28): three administrations only - Baseline
 * (score from the separate tool, kavat_baselines), Midline and Endline
 * (answered in Kalinga). A finalized KaVAT can be corrected by the case
 * manager; the previous answers and score are kept until the correction is
 * finalized (with a reason) or cancelled.
 *
 * The KaVATs recorded in Kalinga until now were test records; the owner
 * asked for them to be deleted (2026-10-28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kavat_assessments', function (Blueprint $table) {
            $table->json('previous_answers')->nullable();
            $table->decimal('previous_final_score', 5, 2)->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamp('corrected_at')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('kavat_assessments')->delete();
    }

    public function down(): void
    {
        Schema::table('kavat_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn(['previous_answers', 'previous_final_score', 'correction_reason', 'corrected_at']);
        });
    }
};
