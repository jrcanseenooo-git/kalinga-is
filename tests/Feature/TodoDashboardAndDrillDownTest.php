<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Phase 6: the dashboard is a to-do list per role; analytics drill down by area and period. */
class TodoDashboardAndDrillDownTest extends TestCase
{
    use RefreshDatabase;

    private int $lgu;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TODO-1', 'name' => 'City of Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function case(string $number, array $client, array $case = []): int
    {
        $clientId = DB::table('clients')->insertGetId($client + ['first_name' => 'Synthetic', 'last_name' => 'Child', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId($case + ['case_number' => $number, 'client_id' => $clientId, 'lgu_id' => $this->lgu, 'assigned_to' => $this->worker->id, 'status' => 'IN_PROGRESS',
            'priority' => 'normal', 'opened_at' => now()->subDays(3), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_each_role_gets_its_own_to_do_items(): void
    {
        $closing = $this->case('TODO-CASE-1', ['region' => 'Cordillera Administrative Region (CAR)'], ['status' => 'FOR_CLOSURE_REVIEW']);
        $head = User::factory()->create(['role' => 'lgu_head', 'active' => true]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $head->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($head)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard')
            ->where('todos', fn ($groups) => collect($groups)->firstWhere('key', 'closures')['items'][0]['title'] === 'Review closure of TODO-CASE-1'));

        User::factory()->create(['role' => 'social_worker', 'account_status' => 'pending', 'region' => 'CAR', 'active' => false]);
        User::factory()->create(['role' => 'social_worker', 'account_status' => 'pending', 'region' => 'Region VIII', 'active' => false]);
        $pm = User::factory()->create(['role' => 'project_manager', 'region_scope' => ['CAR'], 'active' => true]);
        $this->actingAs($pm)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('todos', fn ($groups) => collect($groups)->firstWhere('key', 'accounts')['items'][0]['title'] === '1 account is waiting for approval'));

        $this->actingAs($this->worker)->post(route('tasks.store'), ['title' => 'Synthetic case conference', 'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'participants' => [$head->id]])->assertSessionHasNoErrors();
        $this->actingAs($head)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('todos', fn ($groups) => collect($groups)->firstWhere('key', 'invitations')['items'][0]['title'] === 'Synthetic case conference'));
        $this->actingAs($this->worker)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('suggestions', fn ($items) => collect($items)->contains('kind', 'kavat'))->missing('charts')->missing('regionHeatmap'));
        $this->assertNotNull($closing);
    }

    public function test_analytics_drill_down_follows_area_and_period_with_grants_and_audited_pdf(): void
    {
        $tabuk = $this->case('DD-1', ['region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'City of Tabuk', 'barangay' => 'Bulanao'], ['cefmu_classifications' => json_encode(['early_union', 'forced_union'])]);
        $this->case('DD-2', ['region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'Rizal', 'barangay' => 'Liwan'], ['status' => 'CLOSED', 'opened_at' => now()->subYear()]);
        $this->case('DD-3', ['region' => 'National Capital Region (NCR)', 'municipality' => 'City of Manila', 'barangay' => 'Barangay 1']);
        DB::table('case_grants')->insert(['case_id' => $tabuk, 'grant_type' => 'educational', 'amount' => 2500, 'released_at' => now()->toDateString(), 'recorded_by' => $this->worker->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_grants')->insert(['case_id' => $tabuk, 'grant_type' => 'financial', 'amount' => 999, 'released_at' => now()->toDateString(), 'recorded_by' => $this->worker->id, 'voided_at' => now(), 'void_reason' => 'Duplicate', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->worker);

        $this->get(route('analytics.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('level', 'region')->has('children', 2)->where('totals.cases', 3)->where('grantsTotal', 2500)
            ->where('classification', fn ($rows) => collect($rows)->firstWhere('label', 'Early union')['total'] === 1));
        $this->get(route('analytics.index', ['region' => 'Cordillera Administrative Region (CAR)']))->assertInertia(fn (Assert $page) => $page->where('level', 'province')->where('children.0.name', 'Kalinga')->where('totals.cases', 2));
        $this->get(route('analytics.index', ['region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga']))->assertInertia(fn (Assert $page) => $page->where('level', 'municipality')->has('children', 2));
        $this->get(route('analytics.index', ['region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => 'City of Tabuk']))
            ->assertInertia(fn (Assert $page) => $page->where('level', 'barangay')->where('children.0.name', 'Bulanao')->where('totals.cases', 1)->where('totals.grantsCount', 1));
        // A region without provinces goes straight to its cities.
        $this->get(route('analytics.index', ['region' => 'National Capital Region (NCR)']))->assertInertia(fn (Assert $page) => $page->where('level', 'municipality')->where('children.0.name', 'City of Manila'));
        // Period: only cases opened in the chosen year.
        $this->get(route('analytics.index', ['year' => now()->year]))->assertInertia(fn (Assert $page) => $page->where('filters.year', now()->year)->where('totals.cases', now()->month === 1 && now()->day < 4 ? 3 : 2));
        $this->get(route('analytics.index', ['province' => 'Kalinga']))->assertInertia(fn (Assert $page) => $page->where('filters.province', '')->where('totals.cases', 3));
        $this->get(route('analytics.index', ['year' => 1999]))->assertSessionHasErrors('year');

        $this->postJson(route('analytics.printed'), ['region' => 'Cordillera Administrative Region (CAR)'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'analytics.pdf_exported', 'log_type' => 'report_generation', 'user_id' => $this->worker->id]);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $this->actingAs($viewer)->postJson(route('analytics.printed'))->assertForbidden();
    }
}
