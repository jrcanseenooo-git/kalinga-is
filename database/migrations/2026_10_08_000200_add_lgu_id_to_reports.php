<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', fn (Blueprint $table) => $table->foreignId('lgu_id')->nullable()->after('client_id')->constrained('lgu_units')->nullOnDelete()->index());
    }

    public function down(): void
    {
        Schema::table('reports', fn (Blueprint $table) => $table->dropConstrainedForeignId('lgu_id'));
    }
};
