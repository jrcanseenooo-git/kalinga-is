<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved intakes: an intake the case manager paused (for example the child is
 * not ready to continue). Only the case manager who started it can see it.
 * The answers are encrypted with the application key; the list shows only
 * the masked name. Deleted when the case folder is created, when discarded,
 * or automatically after the expiry date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lgu_id')->nullable()->constrained('lgu_units')->nullOnDelete();
            $table->string('label', 80)->nullable();
            $table->unsignedTinyInteger('step')->default(1);
            $table->longText('payload');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_drafts');
    }
};
