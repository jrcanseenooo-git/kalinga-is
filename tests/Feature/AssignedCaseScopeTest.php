<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\MonitoringScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssignedCaseScopeTest extends TestCase
{
    use RefreshDatabase;

    private function lgu(string $name): int
    {
        return DB::table('lgu_units')->insertGetId([
            'psgc_code' => 'SYNTHETIC-' . $name, 'name' => $name, 'province' => 'Kalinga',
            'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assign(User $worker, int $lgu): void
    {
        DB::table('user_lgu_assignments')->insert([
            'user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true,
            'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function caseFor(User $worker, int $lgu, string $name): int
    {
        $client = DB::table('clients')->insertGetId([
            'first_name' => $name, 'last_name' => 'Synthetic', 'municipality' => 'Tabuk',
            'province' => 'Kalinga', 'region' => 'CAR', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cases')->insertGetId([
            'case_number' => 'SYNTHETIC-' . $client, 'client_id' => $client, 'lgu_id' => $lgu,
            'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake',
            'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_worker_payloads_exclude_other_workers_in_same_lgu_while_retaining_workload_aggregate(): void
    {
        $lgu = $this->lgu('Tabuk');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $own = $this->caseFor($worker, $lgu, 'OwnRecord');
        $hidden = $this->caseFor($other, $lgu, 'HiddenRecord');

        // Bypass the legacy actingAs fixture adapter: it can change roles/LGU data.
        $this->be($worker);
        $this->get(route('cases.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cases.data', 1)->where('cases.data.0.id', $own));
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('metrics.totalCases', 1)->where('lguWorkload.total_cases', 2)
            ->has('recentCases', 1)->where('recentCases.0.id', $own));
        $this->getJson(route('search', ['q' => 'HiddenRecord']))->assertOk()->assertJsonCount(0, 'cases');
        $this->get(route('calendar.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('availableCases', 1)->where('availableCases.0.id', $own));
        $this->get(route('cases.show', $hidden))->assertForbidden();
        $this->assertSame('social_worker', $worker->fresh()->role);
    }

    public function test_scope_requires_both_worker_assignment_and_current_lgu_assignment(): void
    {
        $lgu = $this->lgu('Tabuk');
        $otherLgu = $this->lgu('Rizal');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $own = $this->caseFor($worker, $lgu, 'Own');
        $this->caseFor($other, $lgu, 'OtherWorker');
        $this->caseFor($worker, $otherLgu, 'OtherLgu');

        $query = fn () => app(CaseAccessScope::class)->scopeCases(
            DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id'), $worker,
        );
        $this->assertSame([$own], $query()->pluck('cases.id')->all());
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['effective_to' => now()->subSecond()]);
        $this->assertSame([], $query()->pluck('cases.id')->all());
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['effective_to' => null, 'active' => false]);
        $this->assertSame([], $query()->pluck('cases.id')->all());
    }

    public function test_monitoring_roles_see_cases_only_through_their_scope_and_never_as_case_managers(): void
    {
        $lgu = $this->lgu('Tabuk');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker, $lgu, 'Scoped');
        foreach (['fo_user', 'project_manager', 'cpu_member', 'system_admin'] as $role) {
            $user = User::factory()->create(['role' => $role, 'region' => 'CAR', 'region_scope' => ['*']]);
            $query = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id');
            $this->assertSame([], app(CaseAccessScope::class)->scopeCases($query, $user)->pluck('cases.id')->all(), $role);
        }
        $scope = app(MonitoringScope::class);
        $monitored = fn (User $user) => $scope->scopeCases(DB::table('cases'), $user)->pluck('cases.id')->all();
        $this->assertSame([$case], $monitored(User::factory()->create(['role' => 'fo_user', 'region' => 'CAR'])));
        $this->assertSame([], $monitored(User::factory()->create(['role' => 'fo_user', 'region' => 'Region VIII'])));
        $this->assertSame([$case], $monitored(User::factory()->create(['role' => 'project_manager', 'region_scope' => ['*']])));
        $this->assertSame([], $monitored(User::factory()->create(['role' => 'project_manager', 'region_scope' => ['Region VIII']])));
        $head = User::factory()->create(['role' => 'lgu_head']);
        $this->assertSame([], $monitored($head));
        $this->assign($head, $lgu);
        $this->assertSame([$case], $monitored($head));
        $this->assertSame([], $monitored(User::factory()->create(['role' => 'cpu_member'])));
    }

    public function test_offline_pack_does_not_disclose_assigned_cases_outside_current_lgu(): void
    {
        $lgu = $this->lgu('Tabuk');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $own = $this->caseFor($worker, $lgu, 'OwnOffline');
        $this->caseFor($worker, $this->lgu('Rizal'), 'OutsideOffline');
        $this->be($worker);
        $headers = ['X-Kalinga-Device-ID' => 'cbb90eda-653b-4c39-8120-5511cb2ea001'];
        $this->getJson(route('offline.workspace.pack'), $headers)->assertOk()
            ->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', $own);
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => false]);
        $this->getJson(route('offline.workspace.pack'), $headers)->assertOk()->assertJsonCount(0, 'cases');
    }
}
