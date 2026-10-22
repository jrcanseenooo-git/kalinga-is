<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_lifecycle_policies', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->string('value', 40);
            $table->text('description')->nullable();
            $table->boolean('requires_owner_validation')->default(true);
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });
        foreach ([
            ['high_or_critical_risk', 'block', 'High or critical current risk requires authorized review before case closure.'],
            ['open_tasks', 'warning', 'Open operational tasks require review; local policy may upgrade this to a blocker.'],
            ['open_referrals', 'warning', 'Open referrals require review; the referral remains authoritative for service status.'],
            ['aftercare_required', 'warning', 'Required aftercare must be considered at closure; frequency and mandatory status require owner approval.'],
        ] as [$key,$value,$description]) {
            DB::table('case_lifecycle_policies')->insert(['scope' => 'closure', 'key' => $key, 'value' => $value, 'description' => $description, 'requires_owner_validation' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('case_lifecycle_policies');
    }
};
