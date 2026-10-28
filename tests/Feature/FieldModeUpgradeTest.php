<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Field mode upgrade (owner, 2026-10-28): full progress notes and monitoring
 * visits offline, aftercare follow-up visits for closed cases, and a pack
 * that carries only what the case manager still needs.
 */
class FieldModeUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $head;

    private int $lgu;

    private array $headers = ['X-Kalinga-Device-ID' => 'cbb90eda-653b-4c39-8120-5511cb2ea0bb'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'FLD-1', 'name' => 'Synthetic Field Town', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->head = User::factory()->create(['role' => 'lgu_head', 'active' => true]);
        foreach ([$this->worker, $this->head] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function makeCase(string $number, string $status): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => $number, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $client, 'lgu_id' => $this->lgu, 'assigned_to' => $this->worker->id, 'status' => $status,
            'priority' => 'normal', 'opened_at' => now()->subDays(20), 'intake_completed_at' => now()->subDays(20), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function send(string $resource, array $payload, ?int $base = null)
    {
        return $this->postJson('/sync', ['offline_id' => (string) Str::uuid(), 'resource' => $resource, 'operation' => 'create', 'payload' => $payload] + ($base ? ['base_version' => $base] : []), $this->headers);
    }

    private function aftercarePlan(int $case): void
    {
        DB::table('case_aftercare_plans')->insert(['case_id' => $case, 'required' => true, 'status' => 'follow_up', 'living_arrangement' => 'With the mother', 'follow_up_frequency' => 'monthly',
            'initial_follow_up_at' => now()->subDays(3)->toDateString(), 'follow_up_until' => now()->addMonths(3)->toDateString(), 'created_by' => $this->worker->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_pack_carries_open_cases_and_closed_cases_only_while_aftercare_visits_remain(): void
    {
        $open = $this->makeCase('FLD-OPEN', 'IN_PROGRESS');
        $aftercare = $this->makeCase('FLD-AFTER', 'CLOSED');
        $this->makeCase('FLD-DONE', 'CLOSED');
        $this->aftercarePlan($aftercare);
        $this->actingAs($this->worker)->withSession(['two_factor_confirmed' => true]);
        $cases = collect($this->getJson(route('offline.workspace.pack'), $this->headers)->assertOk()->json('cases'));
        $this->assertEqualsCanonicalizing(['FLD-OPEN', 'FLD-AFTER'], $cases->pluck('case_number')->all());
        $this->assertSame(now()->subDays(3)->toDateString(), $cases->firstWhere('id', $aftercare)['aftercare']['next_due']);
        $this->assertTrue($cases->firstWhere('id', $open)['plan_deadline']['overdue']);
        $this->assertArrayNotHasKey('intake_completed_at', $cases->firstWhere('id', $open));
    }

    public function test_field_notes_keep_type_time_action_and_next_steps_and_old_items_still_sync(): void
    {
        $case = $this->makeCase('FLD-NOTE', 'IN_PROGRESS');
        $this->actingAs($this->worker)->withSession(['two_factor_confirmed' => true]);
        $this->getJson(route('offline.workspace.pack'), $this->headers)->assertOk();
        $when = now()->subDay()->startOfMinute();
        $this->send('case_note', ['case_id' => $case, 'body' => 'Met the child at school.', 'note_type' => 'contact', 'occurred_at' => $when->toIso8601String(), 'action_taken' => 'Talked with the adviser.', 'next_steps' => 'Visit again next week.'], 1)->assertOk();
        $note = DB::table('case_notes')->where('case_id', $case)->first();
        $this->assertSame('contact', $note->note_type);
        $this->assertSame('Talked with the adviser.', $note->action_taken);
        $this->assertEquals($when->utc()->format('Y-m-d H:i'), substr((string) $note->occurred_at, 0, 16));

        $this->send('case_note', ['case_id' => $case, 'body' => 'Other record.', 'note_type' => 'other'], 2)->assertStatus(422);
        $this->send('case_note', ['case_id' => $case, 'body' => 'Queued by an older version.'], 2)->assertOk();
        $this->assertDatabaseHas('case_notes', ['case_id' => $case, 'body' => 'Queued by an older version.', 'note_type' => 'other', 'note_type_other' => 'Field mode note']);
        $this->send('monitoring_visit', ['case_id' => $case, 'visited_at' => now()->toDateString(), 'observations' => 'Home visit.', 'risks' => 'Partner nearby.', 'next_steps' => 'Safety plan.'], 3)->assertOk();
        $this->assertDatabaseHas('monitoring_visits', ['case_id' => $case, 'risks' => 'Partner nearby.', 'next_steps' => 'Safety plan.']);
    }

    public function test_aftercare_visit_syncs_for_a_closed_case_and_at_risk_tells_the_supervisor(): void
    {
        $case = $this->makeCase('FLD-AFT', 'CLOSED');
        $open = $this->makeCase('FLD-STILL-OPEN', 'IN_PROGRESS');
        $this->actingAs($this->worker)->withSession(['two_factor_confirmed' => true]);
        $this->getJson(route('offline.workspace.pack'), $this->headers)->assertOk();
        $visit = ['case_id' => $case, 'visited_on' => now()->toDateString(), 'status' => 'at_risk', 'notes' => 'Back with the partner.'];
        $this->send('aftercare_visit', $visit)->assertStatus(422);
        $this->aftercarePlan($case);
        $this->send('aftercare_visit', ['status' => 'bogus'] + $visit)->assertStatus(422);
        $this->send('aftercare_visit', $visit)->assertOk()->assertJsonPath('status', 'synced');
        $this->assertDatabaseHas('aftercare_visits', ['case_id' => $case, 'status' => 'at_risk']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->head->id, 'type' => 'aftercare_at_risk']);
        $this->assertSame('CLOSED', DB::table('cases')->where('id', $case)->value('status'));
        $this->send('aftercare_visit', ['case_id' => $open] + $visit)->assertStatus(422);
        $other = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($other)->withSession(['two_factor_confirmed' => true])->getJson(route('offline.workspace.pack'), $this->headers);
        $this->send('aftercare_visit', $visit)->assertForbidden();
    }
}
