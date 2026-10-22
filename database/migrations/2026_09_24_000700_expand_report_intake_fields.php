<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('source_other')->nullable()->after('source');
            $table->string('reporter_name')->nullable()->after('source_other');
            $table->string('reporter_contact')->nullable()->after('reporter_name');
            $table->string('subject_first')->nullable()->after('narrative');
            $table->string('subject_last')->nullable()->after('subject_first');
            $table->string('subject_middle')->nullable()->after('subject_last');
            $table->date('subject_birthdate')->nullable()->after('subject_middle');
            $table->unsignedTinyInteger('subject_age')->nullable()->after('subject_birthdate');
            $table->string('subject_sex', 24)->nullable()->after('subject_age');
            $table->string('category')->nullable()->after('subject_sex');
            $table->string('region')->nullable()->after('category');
            $table->string('province')->nullable()->after('region');
            $table->string('municipality')->nullable()->after('province');
            $table->string('barangay')->nullable()->after('municipality');
            $table->decimal('latitude', 10, 7)->nullable()->after('barangay');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('geo_accuracy_m', 10, 2)->nullable()->after('longitude');
            $table->string('geo_source')->nullable()->after('geo_accuracy_m');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['source_other', 'reporter_name', 'reporter_contact', 'subject_first', 'subject_last', 'subject_middle', 'subject_birthdate', 'subject_age', 'subject_sex', 'category', 'region', 'province', 'municipality', 'barangay', 'latitude', 'longitude', 'geo_accuracy_m', 'geo_source']);
        });
    }
};
