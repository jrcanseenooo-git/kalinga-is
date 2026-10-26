<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reference_data becomes the store for editable form options (option sets):
 * display order, points (for example a risk factor's weight) and small
 * settings per option. Options are retired (active = false), never deleted,
 * so records that already use them keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_data', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('label');
            $table->decimal('weight', 6, 2)->nullable()->after('sort_order');
            $table->json('meta')->nullable()->after('source');
            $table->index(['set_name', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('reference_data', function (Blueprint $table) {
            $table->dropIndex(['set_name', 'active', 'sort_order']);
            $table->dropColumn(['sort_order', 'weight', 'meta']);
        });
    }
};
