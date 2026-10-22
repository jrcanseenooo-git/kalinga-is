<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->default('email')->after('two_factor_recovery_codes');
            $table->text('two_factor_email_code')->nullable()->after('two_factor_method');
            $table->timestamp('two_factor_email_code_expires_at')->nullable()->after('two_factor_email_code');
        });

        DB::table('users')
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->update(['two_factor_method' => 'totp']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'two_factor_email_code', 'two_factor_email_code_expires_at']);
        });
    }
};
