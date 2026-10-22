<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('screened_at')->nullable()->after('submitted_at');
            $table->foreignId('screened_by')->nullable()->after('screened_at')->constrained('users')->nullOnDelete();
            $table->text('screening_remarks')->nullable()->after('screened_by');
            $table->timestamp('validated_at')->nullable()->after('screening_remarks');
            $table->foreignId('validated_by')->nullable()->after('validated_at')->constrained('users')->nullOnDelete();
            $table->text('validation_remarks')->nullable()->after('validated_by');
            $table->timestamp('decided_at')->nullable()->after('validation_remarks');
            $table->foreignId('decided_by')->nullable()->after('decided_at')->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable()->after('decided_by');
            $table->foreignId('duplicate_of_report_id')->nullable()->after('decision_reason')->constrained('reports')->nullOnDelete();
            $table->string('referred_out_to')->nullable()->after('duplicate_of_report_id');
            $table->index(['status', 'submitted_at']);
            $table->index(['status', 'screened_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropIndex(['status', 'screened_at']);
            $table->dropConstrainedForeignId('duplicate_of_report_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('validated_by');
            $table->dropConstrainedForeignId('screened_by');
            $table->dropColumn(['screened_at', 'screening_remarks', 'validated_at', 'validation_remarks', 'decided_at', 'decision_reason', 'referred_out_to']);
        });
    }
};
