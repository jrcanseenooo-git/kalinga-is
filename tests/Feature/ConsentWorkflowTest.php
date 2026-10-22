<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Kim', 'last_name' => 'Garcia', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-CONSENT-01', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_owner_can_record_and_withdraw_consent(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker)->post(route('cases.consents.store', $case), ['lawful_basis' => 'Informed consent', 'purposes' => ['case management', 'referral coordination'], 'sharing_allowed' => true, 'granted_at' => '2026-09-22'])->assertRedirect();
        $record = DB::table('consents')->first();
        $this->assertSame(['case management', 'referral coordination'], json_decode($record->purposes, true));
        $this->actingAs($worker)->patch(route('cases.consents.withdraw', [$case, $record->id]))->assertRedirect();
        $this->assertNotNull(DB::table('consents')->where('id', $record->id)->value('withdrawn_at'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'consent.withdrawn', 'auditable_id' => $record->id]);
    }

    public function test_viewer_cannot_record_consent(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $viewer = User::factory()->create(['role' => 'cpu_member']);
        $case = $this->caseFor($worker);
        $this->actingAs($viewer)->post(route('cases.consents.store', $case), ['lawful_basis' => 'Consent', 'purposes' => ['casework'], 'sharing_allowed' => false, 'granted_at' => '2026-09-22'])->assertForbidden();
    }
}
