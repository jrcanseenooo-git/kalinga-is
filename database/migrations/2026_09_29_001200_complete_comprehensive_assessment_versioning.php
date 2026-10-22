<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_assessments', function (Blueprint $table) {
            $table->string('record_type', 20)->default('full_revision')->after('version');
        });

        Schema::create('case_assessment_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_assessment_id')->constrained('case_assessments')->cascadeOnDelete();
            $table->string('event', 30);
            $table->foreignId('actor_id')->constrained('users');
            $table->text('comments')->nullable();
            $table->timestamps();
            $table->index(['case_assessment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_assessment_workflow_events');
        Schema::table('case_assessments', fn (Blueprint $table) => $table->dropColumn('record_type'));
    }
};
