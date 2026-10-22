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

    public function test_fo_operational_counts_remain_exact_and_province_scoped(): void
    {
        $this->cases(4);
        DB::table('clients')->update(['province' => 'Kalinga']);
        $this->be(User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.cases', 4)->where('trend.5.total', 4));
        $this->get(route('analytics.export'))->assertOk()->assertSee('"Total cases","4"', false);
        $this->be(User::factory()->create(['role' => 'fo_user', 'province' => 'OtherProvince']));
        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('totals.cases', 0));
    }
}
