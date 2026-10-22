<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_assessments', function (Blueprint $t) {
            $t->text('review_comments')->nullable();
            $t->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('returned_at')->nullable();
            $t->string('amendment_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('case_assessments', fn (Blueprint $t) => $t->dropColumn(['review_comments', 'returned_by', 'returned_at', 'amendment_reason']));
    }
};
