<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A progress note of record type "Other" says what kind of record it is. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_notes', function (Blueprint $table) {
            $table->string('note_type_other', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('case_notes', function (Blueprint $table) {
            $table->dropColumn('note_type_other');
        });
    }
};
