<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['identity_status', 'birthdate'], 'clients_identity_status_birthdate_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX clients_identity_normalized_surname_index ON clients ((LOWER(last_name))) WHERE deleted_at IS NULL');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('CREATE INDEX clients_identity_normalized_surname_index ON clients (LOWER(last_name))');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS clients_identity_normalized_surname_index');
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_identity_status_birthdate_index');
        });
    }
};
