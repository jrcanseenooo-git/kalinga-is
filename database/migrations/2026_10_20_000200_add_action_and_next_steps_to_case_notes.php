<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive: structured "action taken" and "next steps" on progress notes (as in the earlier Kalinga notes). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_notes', function (Blueprint $table) {
            $table->text('action_taken')->nullable()->after('body');
            $table->text('next_steps')->nullable()->after('action_taken');
        });
    }

    public function down(): void
    {
        Schema::table('case_notes', function (Blueprint $table) {
            $table->dropColumn(['action_taken', 'next_steps']);
        });
    }
};
