<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progress documentation is a formal case record. Existing notes are
     * preserved and corrected only through linked addenda, never overwrites.
     */
    public function up(): void
    {
        Schema::table('case_notes', function (Blueprint $table): void {
            $table->string('record_type', 30)->default('progress_note')->after('visibility');
            $table->string('note_type', 80)->default('general_progress')->after('record_type');
            $table->timestamp('occurred_at')->nullable()->after('note_type');
            $table->foreignId('previous_note_id')->nullable()->after('source_id')->constrained('case_notes')->nullOnDelete();
            $table->text('addendum_reason')->nullable()->after('previous_note_id');
            $table->timestamp('finalized_at')->nullable()->after('captured_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            $table->boolean('is_current')->default(true)->after('finalized_by');
            $table->index(['case_id', 'occurred_at']);
            $table->index(['case_id', 'record_type']);
        });

        DB::table('case_notes')->whereNull('occurred_at')->update([
            'occurred_at' => DB::raw('created_at'),
            'finalized_at' => DB::raw('created_at'),
            'finalized_by' => DB::raw('created_by'),
            'is_current' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('case_notes', function (Blueprint $table): void {
            $table->dropIndex(['case_id', 'occurred_at']);
            $table->dropIndex(['case_id', 'record_type']);
            $table->dropConstrainedForeignId('previous_note_id');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['record_type', 'note_type', 'occurred_at', 'addendum_reason', 'finalized_at', 'is_current']);
        });
    }
};
