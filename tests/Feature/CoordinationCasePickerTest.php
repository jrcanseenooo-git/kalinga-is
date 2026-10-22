<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoordinationCasePickerTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $cpu = User::factory()->create(['role' => 'cpu_member']);
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-PICKER', 'name' => 'Synthetic picker LGU', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $circle = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic picker group', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$worker, $other, $cpu] as $actor) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $actor->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lgu_coordination_members')->insert(['circle_id' => $circle, 'user_id' => $actor->id, 'joined_at' => now()->subDay(), 'membership_role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'SYNTHETIC-PRIVATE-PICKER', 'last_name' => 'Client', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['OWN' => $worker->id, 'OTHER' => $other->id] as $suffix => $owner) {
            $ids[$suffix] = DB::table('cases')->insertGetId(['case_number' => 'SYN-PICKER-' . $suffix, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner, 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $grant = DB::table('mdt_case_access')->insertGetId(['case_id' => $ids['OTHER'], 'user_id' => $cpu->id, 'professional_role' => 'CPU', 'organization' => 'Synthetic LGU', 'purpose' => 'Synthetic picker review', 'allowed_categories' => json_encode(['case_summary']), 'status' => 'assigned', 'assigned_by' => $other->id, 'assigned_at' => now(), 'access_starts_at' => now()->subDay(), 'access_expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

        return compact('worker', 'other', 'cpu', 'lgu', 'circle', 'client', 'ids', 'grant');
    }

    public function test_worker_picker_exposes_only_own_authorized_references_and_no_client_fields(): void
    {
        extract($this->context());
        $response = $this->actingAs($worker)->postJson('/cpu/coordination/' . $circle . '/case-references', ['q' => 'SYN-PICKER'])->assertOk()->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', $ids['OWN'])->assertDontSee('SYNTHETIC-PRIVATE-PICKER')->assertDontSee('SYN-PICKER-OTHER');
        $this->assertSame(['id', 'case_number', 'status'], array_keys($response->json('cases.0')));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->postJson('/cpu/coordination/' . $circle . '/case-references', ['q' => 'NO-MATCH', 'selected_id' => $ids['OWN']])->assertOk()->assertJsonCount(0, 'cases')->assertJsonPath('selected_case.case_number', 'SYN-PICKER-OWN');
        $this->postJson('/cpu/coordination/' . $circle . '/case-references', ['selected_id' => $ids['OTHER']])->assertOk()->assertJsonPath('selected_case', null);
        DB::table('cases')->where('id', $ids['OWN'])->update(['deleted_at' => now()]);
        $this->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(0, 'cases');
    }

    public function test_cpu_picker_requires_current_grant_and_group_membership(): void
    {
        extract($this->context());
        $this->actingAs($cpu)->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', $ids['OTHER']);
        DB::table('mdt_case_access')->where('id', $grant)->update(['access_expires_at' => now()->subSecond()]);
        $this->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(0, 'cases');
        DB::table('lgu_coordination_members')->where('circle_id', $circle)->where('user_id', $cpu->id)->update(['left_at' => now()]);
        $this->postJson('/cpu/coordination/' . $circle . '/case-references')->assertForbidden();
    }

    public function test_picker_is_bounded_searchable_and_does_not_grant_administrator_case_access(): void
    {
        extract($this->context());
        for ($i = 1; $i <= 23; $i++) {
            DB::table('cases')->insert(['case_number' => 'SYN-BOUND-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($worker)->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(20, 'cases')->assertJsonPath('has_more', true);
        $this->postJson('/cpu/coordination/' . $circle . '/case-references', ['q' => 'BOUND-23'])->assertOk()->assertJsonCount(1, 'cases');
        $this->postJson('/cpu/coordination/' . $circle . '/case-references', ['q' => str_repeat('S', 101)])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'system_admin']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $admin->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lgu_coordination_members')->insert(['circle_id' => $circle, 'user_id' => $admin->id, 'joined_at' => now()->subDay(), 'membership_role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(0, 'cases');
    }

    public function test_current_case_grant_does_not_allow_reference_disclosure_in_another_circle(): void
    {
        extract($this->context());
        $second = DB::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-PICKER-OTHER-LGU', 'name' => 'Synthetic other LGU', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $cpu->id, 'lgu_id' => $second, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->where('id', $ids['OTHER'])->update(['lgu_id' => $second]);
        $this->actingAs($cpu)->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(0, 'cases')->assertDontSee('SYN-PICKER-OTHER');
    }

    public function test_legacy_owned_reference_matches_current_capture_authority_without_cross_province_inference(): void
    {
        extract($this->context());
        DB::table('clients')->where('id', $client)->update(['municipality' => 'Synthetic picker LGU']);
        DB::table('cases')->where('id', $ids['OWN'])->update(['lgu_id' => null]);
        $this->actingAs($worker)->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(1, 'cases')->assertJsonPath('cases.0.id', $ids['OWN']);
        DB::table('clients')->where('id', $client)->update(['province' => 'Other province']);
        $this->postJson('/cpu/coordination/' . $circle . '/case-references')->assertOk()->assertJsonCount(0, 'cases');
    }
}
