<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferralTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $user = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Tracking', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-TRACK-0001', 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $case];
    }

    private function advance(User $user, int $id, array $statuses): void
    {
        foreach ($statuses as $status) {
            $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => $status])->assertRedirect()->assertSessionHasNoErrors();
        }
    }

    public function test_consent_basis_without_active_sharing_consent_returns_a_field_error_not_an_error_page(): void
    {
        [$user, $case] = $this->context();
        $this->actingAs($user)->withHeader('X-Inertia', 'true')
            ->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Health', 'lawful_basis' => 'consent'])
            ->assertRedirect()->assertSessionHasErrors('lawful_basis');
        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_history_records_each_change_and_requires_outcome_and_reason(): void
    {
        [$user, $case] = $this->context();
        $this->actingAs($user)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Health', 'lawful_basis' => 'legal_obligation'])->assertRedirect();
        $id = (int) DB::table('referrals')->value('id');
        $this->advance($user, $id, ['PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS']);
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'COMPLETED'])->assertSessionHasErrors('outcome');
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'IN_PROGRESS']);
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'COMPLETED', 'outcome' => 'Synthetic consultation received.', 'remarks' => 'Confirmed by phone.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'COMPLETED', 'outcome' => 'Synthetic consultation received.']);
        $this->assertSame(['DRAFT', 'PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'], DB::table('referral_events')->where('referral_id', $id)->orderBy('id')->pluck('to_status')->all());
        $this->assertDatabaseHas('referral_events', ['referral_id' => $id, 'to_status' => 'COMPLETED', 'remarks' => 'Confirmed by phone.', 'actor_id' => $user->id]);
    }

    public function test_a_referral_that_does_not_proceed_needs_a_reason(): void
    {
        [$user, $case] = $this->context();
        $this->actingAs($user)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Legal', 'lawful_basis' => 'legal_obligation']);
        $id = (int) DB::table('referrals')->value('id');
        $this->advance($user, $id, ['PENDING', 'SENT']);
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'UNAVAILABLE'])->assertSessionHasErrors('reason');
        $this->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'UNAVAILABLE', 'reason' => 'No legal aid office in the municipality.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'UNAVAILABLE', 'status_reason' => 'No legal aid office in the municipality.']);
    }

    public function test_inertia_business_rule_refusal_returns_to_the_form_with_the_message(): void
    {
        [$user, $case] = $this->context();
        $this->actingAs($user)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Health', 'lawful_basis' => 'legal_obligation']);
        $id = (int) DB::table('referrals')->value('id');
        $this->actingAs($user)->from('/referrals')->withHeader('X-Inertia', 'true')
            ->patch(route('referrals.transition', $id), ['status' => 'COMPLETED'])
            ->assertRedirect('/referrals')->assertSessionHasErrors('form')->assertSessionHas('error', 'Invalid referral transition.');
        $this->flushHeaders()->actingAs($user)->patch(route('referrals.transition', $id), ['status' => 'COMPLETED'])->assertStatus(422);
    }

    public function test_progress_note_keeps_action_taken_and_next_steps(): void
    {
        [$user, $case] = $this->context();
        $this->actingAs($user)->post(route('cases.notes.store', $case), ['body' => 'Synthetic contact.', 'note_type' => 'contact', 'occurred_at' => now()->subHour()->toIso8601String(),
            'action_taken' => 'Synthetic action.', 'next_steps' => 'Synthetic next step.'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('case_notes', ['case_id' => $case, 'action_taken' => 'Synthetic action.', 'next_steps' => 'Synthetic next step.']);
    }
}
