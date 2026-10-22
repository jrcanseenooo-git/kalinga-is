<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('is_public_submission')->default(false)->index()->after('status');
            $table->string('endorsed_municipality')->nullable()->index()->after('municipality');
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['is_public_submission']);
            $table->dropIndex(['endorsed_municipality']);
            $table->dropColumn(['is_public_submission', 'endorsed_municipality']);
        });
    }
};
