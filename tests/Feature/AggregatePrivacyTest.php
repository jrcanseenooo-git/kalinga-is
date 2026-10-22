<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AggregatePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function addCases(int $count, string $municipality = 'Smalltown'): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        for ($i = 0; $i < $count; $i++) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Private' . $i, 'last_name' => 'Child', 'sex' => 'Female', 'municipality' => $municipality, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('cases')->insert(['case_number' => 'KLG-AGG-' . $i . '-' . uniqid(), 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_aggregate_only_role_cannot_see_small_totals_groups_or_client_data(): void
    {
        $this->addCases(4);
        $administrator = User::factory()->create(['role' => 'system_admin']);

        $this->actingAs($administrator)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('&quot;cases&quot;:&quot;Suppressed&quot;', false)
            ->assertDontSee('Smalltown')
            ->assertDontSee('Private0');
    }

    public function test_aggregate_only_export_releases_group_after_minimum_is_reached(): void
    {
        $this->addCases(5, 'Tabuk City');
        $administrator = User::factory()->create(['role' => 'system_admin']);

        $this->actingAs($administrator)->get(route('analytics.export'))
            ->assertOk()
            ->assertSee('Cases by municipality: Tabuk City')
            ->assertDontSee('Private0');
    }

    public function test_data_custodian_can_view_redacted_audit_metadata_but_not_administration(): void
    {
        $administrator = User::factory()->create(['role' => 'system_admin']);
        DB::table('audit_logs')->insert(['user_id' => $administrator->id, 'event' => 'case.updated', 'auditable_type' => 'case', 'auditable_id' => 9, 'changes' => json_encode(['note' => 'do not disclose this case note']), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($administrator)->get(route('admin.audits'))
            ->assertOk()
            ->assertSee('case.updated')
            ->assertDontSee('do not disclose this case note');
        // Non-admin roles cannot access the admin panel
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($worker)->get(route('admin.index'))->assertForbidden();
    }
}
