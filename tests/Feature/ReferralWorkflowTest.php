<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferralWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_referral_requires_a_documented_lawful_basis_and_follows_allowed_path(): void
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'A', 'last_name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-99990', 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Health', 'reason' => 'Primary care'])->assertSessionHasErrors('lawful_basis');
        $this->actingAs($user)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Health', 'reason' => 'Primary care', 'lawful_basis' => 'legal_obligation'])->assertRedirect();
        $this->assertDatabaseHas('referrals', ['case_id' => $case, 'status' => 'DRAFT', 'service_category' => 'Health', 'lawful_basis' => 'legal_obligation']);
        $id = DB::table('referrals')->value('id');
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'PENDING'])->assertRedirect();
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'COMPLETED'])->assertStatus(422);
    }
}
