<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When a Kalinga Connect private message was read, for the unread badge in the menu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coordination_direct_messages', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
            $table->index(['recipient_id', 'read_at'], 'coordination_dm_unread');
        });
    }

    public function down(): void
    {
        Schema::table('coordination_direct_messages', function (Blueprint $table) {
            $table->dropIndex('coordination_dm_unread');
            $table->dropColumn('read_at');
        });
    }
};
