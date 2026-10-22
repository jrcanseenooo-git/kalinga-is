<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

class ReferralLifecycleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $lgu = $this->ensureNormalizedLguAssignment($worker);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Referral', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-REF-001', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $referral = DB::table('referrals')->insertGetId(['case_id' => $case, 'service_category' => 'Health', 'status' => 'DRAFT', 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->be($worker);

        return compact('worker', 'case', 'referral');
    }

    public function test_case_page_exposes_only_existing_authorized_referral_transitions(): void
    {
        extract($this->fixture());
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('referralTransitions.DRAFT', ['PENDING'])
            ->where('referralTransitions.IN_PROGRESS', ['COMPLETED', 'RETURNED', 'UNAVAILABLE']));
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->be($reviewer)->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('referralTransitions.IN_PROGRESS', ['RETURNED', 'UNAVAILABLE']));
    }

    public function test_stale_referral_status_cannot_advance_or_duplicate_an_audit(): void
    {
        extract($this->fixture());
        $this->patchJson(route('referrals.transition', $referral), ['expected_status' => 'DRAFT', 'status' => 'PENDING'])->assertRedirect();
        $this->patchJson(route('referrals.transition', $referral), ['expected_status' => 'DRAFT', 'status' => 'SENT'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('referrals', ['id' => $referral, 'status' => 'PENDING']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'referral.transitioned')->count());
        $this->patchJson(route('referrals.transition', $referral), ['expected_status' => 'PENDING', 'status' => 'COMPLETED'])->assertUnprocessable();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'referral.transitioned')->count());
    }

    public function test_ineligible_provider_and_other_workers_case_cannot_be_used_for_a_new_referral(): void
    {
        extract($this->fixture());
        $provider = DB::table('service_providers')->insertGetId(['name' => 'Synthetic inactive provider', 'service_type' => 'Health', 'active' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson(route('referrals.store'), ['case_id' => $case, 'service_provider_id' => $provider, 'service_category' => 'Health', 'lawful_basis' => 'legal_obligation'])->assertUnprocessable()->assertJsonValidationErrors('service_provider_id');
        $other = User::factory()->create(['role' => 'social_worker']);
        $this->ensureNormalizedLguAssignment($other);
        $this->be($other)->patchJson(route('referrals.transition', $referral), ['status' => 'PENDING', 'expected_status' => 'DRAFT'])->assertForbidden();
        $this->assertDatabaseHas('referrals', ['id' => $referral, 'status' => 'DRAFT']);
    }

    public function test_status_and_audit_roll_back_together_if_audit_storage_fails(): void
    {
        extract($this->fixture());
        SyntheticTrigger::create("CREATE TRIGGER synthetic_referral_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event = 'referral.transitioned' BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END;");
        $this->patchJson(route('referrals.transition', $referral), ['status' => 'PENDING', 'expected_status' => 'DRAFT'])->assertServerError();
        $this->assertDatabaseHas('referrals', ['id' => $referral, 'status' => 'DRAFT']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'referral.transitioned')->count());
    }

    public function test_archived_case_or_revoked_jurisdiction_cannot_advance_a_referral(): void
    {
        extract($this->fixture());
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => false]);
        $this->patchJson(route('referrals.transition', $referral), ['status' => 'PENDING'])->assertForbidden();
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['active' => true]);
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->patchJson(route('referrals.transition', $referral), ['status' => 'PENDING'])->assertNotFound();
        $this->assertDatabaseHas('referrals', ['id' => $referral, 'status' => 'DRAFT']);
    }
}
