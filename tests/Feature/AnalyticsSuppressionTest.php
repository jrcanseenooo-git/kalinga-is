<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private function cases(int $count): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        for ($index = 0; $index < $count; $index++) {
            $client = DB::table('clients')->insertGetId([
                'first_name' => 'SyntheticPrivate', 'last_name' => 'Client', 'municipality' => 'SyntheticArea',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('cases')->insert([
                'case_number' => 'SYNTHETIC-AGG-' . $client, 'client_id' => $client, 'assigned_to' => $worker->id,
                'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal',
                'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public static function smallCounts(): array
    {
        return array_map(fn (int $count) => [$count], range(0, 4));
    }

    #[DataProvider('smallCounts')]
    public function test_aggregate_only_summary_suppresses_small_totals_and_monthly_counts_in_payload_and_csv(int $count): void
    {
        $this->cases($count);
        $this->be(User::factory()->create(['role' => 'system_admin']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.cases', 'Suppressed')->where('totals.clients', 'Suppressed')
            ->where('totals.openReferrals', 'Suppressed')
            ->where('trend.5.total', 'Suppressed')->where('trend.0.total', 'Suppressed')
            ->has('status', 0)->has('geo', 0));
        $this->get(route('analytics.export'))->assertOk()
            ->assertSee('"Total cases","Suppressed"', false)
            ->assertSee('"Active cases","Suppressed"', false)
            ->assertDontSee('SyntheticPrivate')->assertDontSee('SyntheticArea');
        $this->assertDatabaseHas('audit_logs', ['event' => 'analytics.exported']);
    }

    public function test_age_sex_by_exact_age_services_and_plan_submission_time(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'AGE-1', 'name' => 'Synthetic Age Town', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->be($worker);
        $make = function (string $sex, int $age, int $intakeDaysAgo, ?int $planAfterDays) use ($worker, $lgu) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'S', 'last_name' => 'C', 'sex' => $sex, 'birthdate' => now('Asia/Manila')->subYears($age)->subDays(10)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            $case = DB::table('cases')->insertGetId(['case_number' => 'AGE-' . $client, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'IN_PROGRESS', 'priority' => 'normal',
                'opened_at' => now()->subDays($intakeDaysAgo), 'intake_completed_at' => now()->subDays($intakeDaysAgo), 'created_at' => now(), 'updated_at' => now()]);
            if ($planAfterDays !== null) {
                DB::table('case_form_versions')->insert(['case_id' => $case, 'form_type' => 'plan', 'version' => 1, 'content' => '{}', 'finalized_by' => $worker->id,
                    'created_at' => now()->subDays($intakeDaysAgo - $planAfterDays), 'updated_at' => now()]);
            }

            return $case;
        };
        $first = $make('Female', 15, 20, 4);
        $make('Female', 15, 20, 10);
        $make('Male', 16, 20, null);
        $make('Female', 17, 2, null);
        DB::table('services_provided')->insert(['case_id' => $first, 'service_type' => 'Transportation', 'amount' => 300, 'date_provided' => now()->toDateString(), 'recorded_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('age', [['label' => '15', 'total' => 2], ['label' => '16', 'total' => 1], ['label' => '17', 'total' => 1]])
            ->where('ageSex.0', ['age' => '15', 'female' => 2, 'male' => 0])->where('ageSex.1', ['age' => '16', 'female' => 0, 'male' => 1])
            ->where('services.0.label', 'Transportation')->where('services.0.cases', 1)->where('services.0.amount', 300)
            ->where('planSubmission.averageDays', 7)->where('planSubmission.onTime', 1)->where('planSubmission.late', 1)->where('planSubmission.overdue', 1));
    }

    public function test_cefmu_classification_counts_only_the_four_cefmu_classifications_on_both_pages(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'CLS-1', 'name' => 'Synthetic Class Town', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['CEFMU', ['early_union', 'forced_union']], ['CEFMU', ['early_union']], ['Child protection', []], ['Education support', ['education_support']]] as $index => [$category, $codes]) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'S', 'last_name' => 'C' . $index, 'sex' => 'Female', 'created_at' => now(), 'updated_at' => now()]);
            $case = DB::table('cases')->insertGetId(['case_number' => 'CLS-' . $index, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'AWAITING_REFERRAL', 'priority' => 'normal',
                'category' => $category, 'cefmu_classifications' => json_encode($codes), 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('services_provided')->insert(['case_id' => $case, 'service_type' => 'Medical', 'date_provided' => now()->toDateString(), 'recorded_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $expected = [['label' => 'Early union', 'total' => 2], ['label' => 'Forced union', 'total' => 1]];
        $this->actingAs($worker)->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('classification', $expected));
        $this->get(route('analytics.quarterly'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('classification', $expected)
            ->where('status.0.label', 'Awaiting referral')->where('services.0.label', 'Medical')->where('services.0.total', 1));
    }

    public function test_quarterly_payload_and_export_apply_the_same_small_count_rule(): void
    {
        $this->cases(4);
        $this->be(User::factory()->create(['role' => 'system_admin']));
        $this->get(route('analytics.quarterly'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.newCases', 'Suppressed')->where('totals.closedCases', 'Suppressed')
            ->where('totals.reportsReceived', 'Suppressed')->where('totals.referralsCompleted', 'Suppressed'));
        $this->get(route('analytics.quarterly.export'))->assertOk()
            ->assertSee('"new Cases","Suppressed"', false)->assertDontSee('SyntheticArea');
        $this->assertDatabaseHas('audit_logs', ['event' => 'analytics.quarterly_exported']);
    }

    public function test_five_case_boundary_remains_publishable_without_exposing_identities(): void
    {
        $this->cases(5);
        $this->be(User::factory()->create(['role' => 'system_admin']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.cases', 5)->where('trend.5.total', 5)->where('totals.openReferrals', 'Suppressed'));
        $this->get(route('analytics.export'))->assertOk()->assertSee('"Total cases","5"', false)
            ->assertSee('SyntheticArea')->assertDontSee('SyntheticPrivate');
        $this->get(route('analytics.quarterly'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.newCases', 5));
    }

    public function test_export_and_quarterly_permissions_are_checked_independently_of_analytics_view(): void
    {
        $this->be(User::factory()->create(['role' => 'system_admin']));
        Gate::define('analytics.export', fn () => false);
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canExport', false)->where('canQuarterly', true));
        $this->get(route('analytics.export'))->assertForbidden();
        $this->get(route('analytics.quarterly'))->assertOk();
        $this->get(route('analytics.quarterly.export'))->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'analytics.exported']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'analytics.quarterly_exported']);

        Gate::define('analytics.export', fn () => true);
        Gate::define('analytics.quarterly', fn () => false);
        $this->get(route('analytics.export'))->assertOk();
        $this->get(route('analytics.quarterly'))->assertForbidden();
        $this->get(route('analytics.quarterly.export'))->assertForbidden();
    }

    public function test_visible_total_does_not_bypass_suppression_of_smaller_active_or_monthly_counts(): void
    {
        $this->cases(5);
        DB::table('cases')->where('id', DB::table('cases')->min('id'))->update([
            'status' => 'CLOSED', 'opened_at' => now()->subMonth(),
        ]);
        $this->be(User::factory()->create(['role' => 'system_admin']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.cases', 5)->where('trend.5.total', 'Suppressed')
            ->where('trend.4.total', 'Suppressed')->has('status', 0));
        $this->get(route('analytics.export'))->assertOk()->assertSee('"Total cases","5"', false)
            ->assertSee('"Active cases","Suppressed"', false);
    }

    public function test_field_office_sees_only_its_region_with_small_numbers_suppressed(): void
    {
        $car = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-CAR', 'name' => 'Synthetic Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-R8', 'name' => 'Synthetic Tacloban', 'province' => 'Leyte', 'region' => 'Region VIII', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->cases(6);
        $ids = DB::table('cases')->orderBy('id')->pluck('id');
        DB::table('cases')->whereIn('id', $ids->take(5))->update(['lgu_id' => $car]);
        DB::table('cases')->where('id', $ids->last())->update(['lgu_id' => $other]);

        $this->be(User::factory()->create(['role' => 'fo_user', 'region' => 'CAR']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('totals.cases', 5));
        $this->be(User::factory()->create(['role' => 'fo_user', 'region' => 'Region VIII']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('totals.cases', 'Suppressed'));
        $this->be(User::factory()->create(['role' => 'project_manager', 'region_scope' => ['*']]));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('totals.cases', 6));
    }
}
