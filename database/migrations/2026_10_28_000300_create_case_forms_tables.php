<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kalinga forms worked on directly (App\Services\CaseForms): one working
 * copy per case and form type, and the finalized versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('form_type', 20);
            $table->json('content');
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->unique(['case_id', 'form_type']);
        });
        Schema::create('case_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained()->cascadeOnDelete();
            $table->string('form_type', 20);
            $table->unsignedInteger('version');
            $table->json('content');
            $table->foreignId('finalized_by')->constrained('users');
            $table->timestamps();
            $table->unique(['case_id', 'form_type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_form_versions');
        Schema::dropIfExists('case_forms');
    }
};
