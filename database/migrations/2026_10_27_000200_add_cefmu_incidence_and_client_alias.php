<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CEFMU Incidence (owner form, 2026-10-26) and the client alias.
 *
 * cases.union_forms        marriage or union forms (civil, religious, customary marriage; cohabitation)
 * cases.union_status       married or cohabiting with a partner: yes_now, yes_before, no
 * cases.partner_current_age  partner's current age in years
 * cases.union_duration_months  length of the marriage or cohabitation
 * cases.current_residence  where the child lives now
 * clients.alias            alias or nickname shown on forms instead of the full name
 *
 * union_type and partner_age_band are kept for existing records; the old
 * union_type value is copied into union_forms so reports read one field.
 */
return new class extends Migration
{
    private const FORMS = ['civil_marriage', 'religious_marriage', 'customary_traditional_marriage', 'informal_cohabitation'];

    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->json('union_forms')->nullable()->after('union_type');
            $table->string('union_status', 20)->nullable()->after('union_forms');
            $table->unsignedSmallInteger('partner_current_age')->nullable()->after('partner_age_band');
            $table->unsignedSmallInteger('union_duration_months')->nullable()->after('partner_current_age');
            $table->string('current_residence', 40)->nullable()->after('union_duration_months');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->string('alias', 80)->nullable()->after('suffix');
        });

        DB::table('cases')->whereIn('union_type', self::FORMS)->orderBy('id')->select(['id', 'union_type'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('cases')->where('id', $row->id)->update(['union_forms' => json_encode([$row->union_type])]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $table) => $table->dropColumn(['union_forms', 'union_status', 'partner_current_age', 'union_duration_months', 'current_residence']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('alias'));
    }
};
