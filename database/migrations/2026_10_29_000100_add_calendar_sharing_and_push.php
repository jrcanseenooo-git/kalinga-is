<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: shared calendar activities, their change history and phone (Web
 * Push) subscriptions.
 * - task_participants: users invited to an activity and their answer.
 * - task_history: every change to an activity (created, rescheduled with the
 *   old and new time, cancelled, invited, answered, completed) with the reason.
 * - push_subscriptions: one row per browser that turned on phone
 *   notifications. Pushes carry no content; the phone asks the server for the
 *   latest notifications when one arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('response', 12)->default('pending');
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'response']);
        });
        Schema::create('task_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('event', 20);
            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('from_due_at')->nullable();
            $table->timestamp('to_due_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['task_id', 'created_at']);
        });
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('device_label', 80)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('task_history');
        Schema::dropIfExists('task_participants');
    }
};
