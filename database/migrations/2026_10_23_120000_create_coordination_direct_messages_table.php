<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordination_direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained('lgu_coordination_circles')->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('coordination_direct_messages')->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_urgent')->default(false);
            $table->boolean('follow_up')->default(false);
            $table->timestamps();
            $table->index(['circle_id', 'sender_id', 'recipient_id', 'id'], 'coordination_dm_pair');
            $table->index(['circle_id', 'recipient_id', 'sender_id', 'id'], 'coordination_dm_recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_direct_messages');
    }
};
