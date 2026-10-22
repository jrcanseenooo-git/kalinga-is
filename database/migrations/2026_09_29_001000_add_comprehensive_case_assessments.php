<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained()->cascadeOnDelete();
            $t->foreignId('previous_assessment_id')->nullable()->constrained('case_assessments')->nullOnDelete();
            $t->unsignedInteger('version');
            $t->date('assessment_date');
            $t->string('status', 30)->default('draft');
            $t->foreignId('authored_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finalized_at')->nullable();
            $t->boolean('is_current')->default(true);
            $t->timestamps();
            $t->unique(['case_id', 'version']);
            $t->index(['case_id', 'status']);
        });
        Schema::create('case_assessment_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_assessment_id')->constrained()->cascadeOnDelete();
            $t->string('section_key', 80);
            $t->string('completion_state', 30)->default('partially_completed');
            $t->string('source_type', 50)->nullable();
            $t->text('facts')->nullable();
            $t->text('reported_information')->nullable();
            $t->text('observations')->nullable();
            $t->text('analysis')->nullable();
            $t->text('conflicting_information')->nullable();
            $t->boolean('restricted')->default(false);
            $t->timestamps();
            $t->unique(['case_assessment_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_assessment_sections');
        Schema::dropIfExists('case_assessments');
    }
};
