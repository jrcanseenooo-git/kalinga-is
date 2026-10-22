<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_identity_merge_identifier_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merge_id')->constrained('client_identity_merges')->cascadeOnDelete();
            $table->foreignId('source_identifier_id')->constrained('client_identifiers')->cascadeOnDelete();
            $table->foreignId('survivor_identifier_id')->nullable()->constrained('client_identifiers')->nullOnDelete();
            $table->string('identifier_type', 80);
            $table->string('resolution', 48)->default('preserve_source_provenance');
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['merge_id', 'source_identifier_id', 'survivor_identifier_id'], 'identity_merge_identifier_conflict_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_identity_merge_identifier_conflicts');
    }
};
