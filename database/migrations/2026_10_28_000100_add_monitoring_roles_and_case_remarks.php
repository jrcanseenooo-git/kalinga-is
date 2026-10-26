<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring roles (owner request 2026-10-26):
 * - users.region_scope: the regions a Project Manager monitors; ["*"] = all regions.
 *   Field Office users keep users.region; LGU Heads use their LGU assignment.
 * - case_remarks: remarks by the LGU Head, Field Office or Project Manager on a
 *   case, and replies by the case manager. Remarks are kept (no edit or delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('region_scope')->nullable()->after('region');
        });

        Schema::create('case_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->string('author_role', 40);
            $table->foreignId('parent_id')->nullable()->constrained('case_remarks')->nullOnDelete();
            $table->string('kind', 30)->default('remark');
            $table->text('body');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'created_at']);
        });

        // Field Office users monitored by province before; keep the region of that province.
        DB::table('users')->where('role', 'fo_user')->whereNull('region')->whereNotNull('province')->orderBy('id')->each(function ($user) {
            $region = DB::table('lgu_units')->where('province', $user->province)->value('region');
            if ($region) {
                DB::table('users')->where('id', $user->id)->update(['region' => $region]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_remarks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('region_scope'));
    }
};
