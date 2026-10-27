<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8: Kalinga Connect across regions for Project Managers. Separate
 * from the LGU coordination circles, so LGU isolation is unchanged:
 * - connect_threads: a direct conversation or a named group (for example
 *   "Region VIII"), started by a Project Manager.
 * - connect_thread_members: who is in it, and what they have read.
 * - connect_messages: the messages (operational coordination only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_threads', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('title', 120)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
        Schema::create('connect_thread_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('connect_threads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });
        Schema::create('connect_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('connect_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body');
            $table->boolean('is_urgent')->default(false);
            $table->timestamps();
            $table->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_messages');
        Schema::dropIfExists('connect_thread_members');
        Schema::dropIfExists('connect_threads');
    }
};
