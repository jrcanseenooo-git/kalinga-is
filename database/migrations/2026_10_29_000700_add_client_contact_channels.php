<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Child Contact Details (owner request 2026-10-28): besides the child's own
 * mobile number, other ways to reach the child (for example Facebook
 * Messenger), kept as a short JSON list of {channel, handle}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('contact_channels')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('contact_channels'));
    }
};
