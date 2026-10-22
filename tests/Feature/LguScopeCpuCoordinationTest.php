<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LguAssignmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LguScopeCpuCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private function lgu(string $name, string $code): int
    {
        return DB::table('lgu_units')->insertGetId(['psgc_code' => $code, 'name' => $name, 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function assign(User $user, int $lgu): void
    {
        DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'effective_from' => now()->subDay(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function caseFor(User $worker, int $lgu, string $name = 'Protected'): int
    {
        $unit = DB::table('lgu_units')->find($lgu);
        $client = DB::table('clients')->insertGetId(['first_name' => $name, 'last_name' => 'Child', 'municipality' => $unit->name, 'province' => 'Kalinga', 'region' => 'CAR', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-LGU-' . uniqid(), 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'assessment', 'priority' => 'high', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function circle(int $lgu, User ...$members): int
    {
        $id = DB::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $lgu, 'name' => 'LGU CPU Circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($members as $member) {
            DB::table('lgu_coordination_members')->insert(['circle_id' => $id, 'user_id' => $member->id, 'membership_role' => 'member', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    public function test_case_manager_receives_lgu_workload_aggregate_without_other_workers_case_detail(): void
    {
        $lgu = $this->lgu('Tabuk', '1411100000');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $this->assign($other, $lgu);
        $own = $this->caseFor($worker, $lgu, 'Own');
        $otherCase = $this->caseFor($other, $lgu, 'Other');
        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertDontSee('Other Child');
        $this->assertSame(2, count(app(LguAssignmentScope::class)->dashboardCaseIds($worker)));
        $this->actingAs($worker)->get(route('cases.show', $otherCase))->assertForbidden();
        $this->assertDatabaseHas('cases', ['id' => $own]);
    }

    public function test_normalized_lgu_assignment_blocks_cross_lgu_mdt_grant(): void
    {
        $tabuk = $this->lgu('Tabuk', '1411100000');
        $otherLgu = $this->lgu('Rizal', '1411110000');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $member = User::factory()->create(['role' => 'cpu_member']);
        $this->assign($worker, $tabuk);
        $this->assign($member, $otherLgu);
        $case = $this->caseFor($worker, $tabuk);
        $this->actingAs($worker)->postJson(route('cases.mdt.access.store', $case), ['user_id' => $member->id, 'professional_role' => 'CPU counterpart', 'organization' => 'LGU', 'purpose' => 'Input', 'allowed_categories' => ['case_summary'], 'access_expires_at' => now()->addDays(3)->toIso8601String()])->assertStatus(422);
        $this->assertDatabaseCount('mdt_case_access', 0);
    }

    public function test_cpu_circle_is_lgu_isolated_and_case_tag_does_not_grant_case_access(): void
    {
        $tabuk = $this->lgu('Tabuk', '1411100000');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $cpu = User::factory()->create(['role' => 'cpu_member']);
        $outsider = User::factory()->create(['role' => 'cpu_member']);
        $this->assign($worker, $tabuk);
        $this->assign($cpu, $tabuk);
        $case = $this->caseFor($worker, $tabuk);
        $circle = $this->circle($tabuk, $worker, $cpu);
        $message = 'Please follow up on the assigned coordination action.';
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.store', $circle), ['body' => $message, 'case_id' => $case, 'mention_user_ids' => [$cpu->id], 'is_urgent' => true])->assertCreated();
        $this->actingAs($cpu)->getJson(route('cpu.coordination.messages.index', $circle))->assertOk()->assertJsonFragment(['body' => $message, 'case_reference' => DB::table('cases')->find($case)->case_number, 'can_open_case' => false]);
        $this->actingAs($cpu)->get(route('cases.show', $case))->assertForbidden();
        $this->actingAs($outsider)->getJson(route('cpu.coordination.search', [$circle, 'q' => 'follow']))->assertForbidden();
        $this->assertDatabaseHas('notifications', ['user_id' => $cpu->id, 'type' => 'cpu_chat_mention', 'body' => 'A coordination message needs your attention.']);
        $this->assertDatabaseMissing('notifications', ['body' => $message]);
    }

    public function test_chat_capture_requires_confirmation_and_records_provenance_without_copying_message_body(): void
    {
        $lgu = $this->lgu('Tabuk', '1411100000');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->assign($worker, $lgu);
        $case = $this->caseFor($worker, $lgu);
        $circle = $this->circle($lgu, $worker);
        $source = DB::table('lgu_coordination_messages')->insertGetId(['circle_id' => $circle, 'author_id' => $worker->id, 'case_id' => $case, 'case_reference' => DB::table('cases')->find($case)->case_number, 'body' => 'Original chat message must not become a note automatically.', 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['case_id' => $case, 'record_type' => 'progress_note', 'professional_summary' => 'Worker confirmed a follow-up is needed.', 'confirmed' => true];
        $this->actingAs($worker)->postJson(route('cpu.coordination.messages.capture', [$circle, $source]), $payload)->assertCreated();
        $this->assertDatabaseHas('case_notes', ['case_id' => $case, 'body' => $payload['professional_summary'], 'source_type' => 'cpu_coordination_message', 'source_id' => $source]);
        $this->assertDatabaseMissing('case_notes', ['body' => 'Original chat message must not become a note automatically.']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'cpu_chat.record_captured']);
    }

    public function test_central_dashboard_and_management_export_are_aggregate_only(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $lgu = $this->lgu('Tabuk', '1411100000');
        $this->assign($worker, $lgu);
        $this->caseFor($worker, $lgu, 'SecretNarrativeMarker');
        $this->actingAs($admin)->get(route('management.dashboard'))->assertOk()->assertDontSee('SecretNarrativeMarker');
        $this->actingAs($admin)->get(route('management.dashboard.export'))->assertOk()->assertDontSee('SecretNarrativeMarker');
    }

    public function test_social_case_management_report_is_case_scoped_audited_and_excludes_restricted_modules(): void
    {
        $lgu = $this->lgu('Tabuk', '1411100000');
        $worker = User::factory()->create(['role' => 'social_worker']);
        $cpu = User::factory()->create(['role' => 'cpu_member']);
        $this->assign($worker, $lgu);
        $this->assign($cpu, $lgu);
        $case = $this->caseFor($worker, $lgu);
        DB::table('child_participation_records')->insert(['case_id' => $case, 'lineage_key' => (string) Str::uuid(), 'version' => 1, 'status' => 'final', 'record_type' => 'formal_record', 'is_current' => true, 'authored_by' => $worker->id, 'occasion_type' => 'case_review', 'decision_title' => 'Sensitive child narrative marker', 'participation_status' => 'participated', 'participation_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->get(route('cases.social-report.show', $case))->assertOk()->assertDontSee('Sensitive child narrative marker');
        $this->actingAs($worker)->get(route('cases.social-report.download', $case))->assertOk()->assertSee('POLICY/TEMPLATE VALIDATION REQUIRED');
        $this->actingAs($cpu)->get(route('cases.social-report.show', $case))->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['event' => 'social_case_report.downloaded', 'auditable_id' => $case]);
    }

    public function test_admin_lgu_assignment_preserves_history_and_provisions_cpu_circle_membership(): void
    {
        $admin = User::factory()->create(['role' => 'system_admin']);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($admin)->post(route('admin.lgus.store'), ['psgc_code' => '1403213000', 'name' => 'City of Tabuk', 'province' => 'Kalinga', 'region' => 'Cordillera Administrative Region (CAR)'])->assertRedirect();
        $lgu = (int) DB::table('lgu_units')->value('id');
        $circle = (int) DB::table('lgu_coordination_circles')->value('id');
        $this->actingAs($admin)->post(route('admin.users.lgu-assignment.store', $worker->id), ['lgu_id' => $lgu, 'effective_from' => now()->toDateString()])->assertRedirect();
        $this->assertDatabaseHas('user_lgu_assignments', ['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true]);
        $this->assertDatabaseHas('lgu_coordination_members', ['circle_id' => $circle, 'user_id' => $worker->id, 'membership_role' => 'case_manager']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.lgu_assigned', 'auditable_id' => $worker->id]);
    }
}
