<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CaseworkFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_worker_can_create_case_with_audit_and_history(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $r = $this->actingAs($user)->post(route('cases.store'), ['first_name' => 'Ana', 'last_name' => 'Dela Cruz', 'birthdate' => '2011-01-10', 'sex' => 'Female', 'priority' => 'high', 'category' => 'CEFMU', 'cefmu_classifications' => ['early_union'], 'narrative' => 'Initial concern', 'initial_risk_level' => 'moderate', 'privacy_notice_acknowledged' => true, 'privacy_notice_method' => 'explained_verbally']);
        $r->assertRedirect();
        $this->assertDatabaseHas('clients', ['first_name' => 'Ana', 'last_name' => 'Dela Cruz']);
        $this->assertDatabaseHas('cases', ['status' => 'OPEN', 'assigned_to' => $user->id, 'initial_risk_level' => 'moderate', 'category' => 'CEFMU', 'privacy_notice_method' => 'explained_verbally']);
        $this->assertDatabaseHas('case_status_history', ['to_status' => 'OPEN']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.created']);
    }

    public function test_transition_is_validated_and_audited(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Ana', 'last_name' => 'Cruz', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-00001', 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->patch(route('cases.transition', $case), ['status' => 'IN_PROGRESS'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['id' => $case, 'status' => 'IN_PROGRESS']);
        $this->assertDatabaseHas('case_status_history', ['case_id' => $case, 'from_status' => 'OPEN', 'to_status' => 'IN_PROGRESS']);
        $this->actingAs($user)->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertStatus(422);
    }

    /** The former direct match endpoint was retired for scoped identity review; it must expose no client details. */
    public function test_legacy_duplicate_lookup_is_retired_without_exposing_client_history(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Ana', 'last_name' => 'Cruz', 'birthdate' => '2015-01-01', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-00002', 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->getJson(route('cases.duplicates', ['first_name' => 'ana', 'last_name' => 'cruz', 'birthdate' => '2015-01-01']))->assertStatus(410)->assertDontSee('Ana')->assertDontSee('KLG-2026-00002');
    }

    public function test_read_only_user_cannot_view_or_change_sensitive_case(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Ana', 'last_name' => 'Cruz', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-00003', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->get(route('cases.show', $case))->assertForbidden();
        $this->actingAs($viewer)->post(route('cases.notes.store', $case), ['body' => 'No access'])->assertForbidden();
    }

    public function test_read_only_dashboard_routes_cpu_member_to_referred_case_workspace_without_case_records(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Private', 'last_name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->insert(['case_number' => 'KLG-2026-00004', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->get(route('dashboard'))->assertRedirect(route('mdt.collaboration.index'))->assertDontSee('Private Client');
    }

    public function test_case_worker_dashboard_loads_with_joined_case_records(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Dashboard', 'last_name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->insert(['case_number' => 'KLG-2026-00005', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertSee('Dashboard')->assertSee('Client');
    }

    public function test_non_social_worker_dashboard_does_not_receive_lgu_worker_workload(): void
    {
        $fieldOffice = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Scoped', 'last_name' => 'Client', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->insert(['case_number' => 'KLG-2026-00006', 'client_id' => $client, 'assigned_to' => $fieldOffice->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        // Monitoring roles start on the scoped project overview, never on a case manager's workload.
        $this->actingAs($fieldOffice)->get(route('dashboard'))->assertRedirect(route('management.dashboard'));
    }
}
