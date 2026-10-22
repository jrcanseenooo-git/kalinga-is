<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Calendar events: tasks gain a type, place and details. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('event_type', 40)->nullable()->after('title');
            $table->string('location', 255)->nullable()->after('due_at');
            $table->text('details')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['event_type', 'location', 'details']));
    }
};
