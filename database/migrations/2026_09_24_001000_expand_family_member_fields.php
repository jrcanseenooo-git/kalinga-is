<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationships', function (Blueprint $t) {
            $t->string('sex', 24)->nullable()->after('relationship_type');
            $t->date('birthdate')->nullable()->after('sex');
            $t->string('education')->nullable()->after('birthdate');
            $t->string('occupation')->nullable()->after('education');
        });
    }

    public function down(): void
    {
        Schema::table('relationships', fn (Blueprint $t) => $t->dropColumn(['sex', 'birthdate', 'education', 'occupation']));
    }
};
