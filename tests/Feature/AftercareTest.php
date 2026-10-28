<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Aftercare;
use App\Services\CaseLifecyclePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Aftercare (owner, 2026-10-28): a plan at closure, then follow-up visits after closure. */
class AftercareTest extends TestCase
{
    use RefreshDatabase;

    private int $case;

    private int $lgu;

    private User $worker;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'AFT-1', 'name' => 'Synthetic Aftercare Town', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->head = User::factory()->create(['role' => 'lgu_head', 'active' => true]);
        foreach ([$this->worker, $this->head] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'S', 'last_name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $this->case = DB::table('cases')->insertGetId(['case_number' => 'KLG-AFT-1', 'client_id' => $client, 'lgu_id' => $this->lgu, 'assigned_to' => $this->worker->id, 'status' => 'FOR_CLOSURE_REVIEW',
            'priority' => 'normal', 'opened_at' => now()->subMonths(4), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plan(array $extra = []): array
    {
        return array_merge(['living_arrangement' => 'With the mother in the barangay', 'support_people' => 'Mother, aunt', 'services_to_continue' => 'ALS classes', 'risks_to_watch' => 'Contact with the partner',
            'responsible_office' => 'C/MSWDO', 'follow_up_frequency' => 'monthly', 'initial_follow_up_at' => now()->subDays(40)->toDateString(), 'follow_up_until' => now()->addMonths(4)->toDateString()], $extra);
    }

    public function test_plan_is_written_at_closure_and_counts_for_closure_readiness(): void
    {
        $this->assertContains(['code' => 'aftercare_required'], app(CaseLifecyclePolicy::class)->readiness($this->case)['warnings']);
        $this->actingAs($this->worker)->put(route('cases.aftercare.plan', $this->case), $this->plan(['follow_up_frequency' => 'weekly']))->assertSessionHasErrors('follow_up_frequency');
        $this->put(route('cases.aftercare.plan', $this->case), $this->plan(['follow_up_until' => now()->subYear()->toDateString()]))->assertSessionHasErrors('follow_up_until');
        $this->put(route('cases.aftercare.plan', $this->case), $this->plan())->assertSessionHasNoErrors();
        $this->put(route('cases.aftercare.plan', $this->case), $this->plan(['support_people' => 'Mother']))->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('case_aftercare_plans')->where('case_id', $this->case)->count(), 'Updating keeps one plan.');
        $this->assertNotContains(['code' => 'aftercare_required'], app(CaseLifecyclePolicy::class)->readiness($this->case)['warnings']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case_aftercare.recorded']);
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('aftercare.plan.support_people', 'Mother'));
        // Visits wait for the closure.
        $this->post(route('cases.aftercare.visits.store', $this->case), ['visited_on' => now()->toDateString(), 'status' => 'stable', 'notes' => 'Synthetic visit.'])->assertStatus(422);
        $stranger = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($stranger)->put(route('cases.aftercare.plan', $this->case), $this->plan())->assertNotFound();
        $this->actingAs($this->head)->put(route('cases.aftercare.plan', $this->case), $this->plan())->assertForbidden();
    }

    public function test_follow_up_visits_after_closure_set_the_next_date_and_at_risk_suggests_reopening(): void
    {
        $this->actingAs($this->worker)->put(route('cases.aftercare.plan', $this->case), $this->plan());
        DB::table('cases')->where('id', $this->case)->update(['status' => 'CLOSED', 'closed_at' => now()->subDays(45)]);
        $this->assertSame(now()->subDays(40)->toDateString(), Aftercare::forCase($this->case)['next_due'], 'The first visit is due (past due).');
        Artisan::call('kalinga:dispatch-deadline-alerts');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->worker->id, 'type' => 'aftercare_visit_due']);

        $this->post(route('cases.aftercare.visits.store', $this->case), ['visited_on' => now('Asia/Manila')->addDay()->toDateString(), 'status' => 'stable', 'notes' => 'Synthetic visit.'])->assertSessionHasErrors('visited_on');
        $this->post(route('cases.aftercare.visits.store', $this->case), ['visited_on' => now()->subDays(5)->toDateString(), 'status' => 'stable', 'notes' => 'Doing well at home.'])->assertSessionHasNoErrors();
        $this->assertSame(now()->subDays(5)->addMonthNoOverflow()->toDateString(), Aftercare::forCase($this->case)['next_due']);
        $this->get(route('calendar.index'))->assertInertia(fn ($page) => $page->where('caseEvents', fn ($events) => collect($events)->contains(fn ($event) => $event['source'] === 'Aftercare plan' && $event['case_number'] === 'KLG-AFT-1')));

        $this->post(route('cases.aftercare.visits.store', $this->case), ['visited_on' => now()->toDateString(), 'status' => 'at_risk', 'notes' => 'Back with the partner.'])->assertSessionHasNoErrors();
        $this->assertTrue(Aftercare::forCase($this->case)['suggest_reopen']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->head->id, 'type' => 'aftercare_at_risk']);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'aftercare.visit_recorded')->count());
        $this->assertSame('CLOSED', DB::table('cases')->where('id', $this->case)->value('status'), 'Recording aftercare never reopens the case by itself.');
    }

    public function test_follow_up_ends_after_the_until_date(): void
    {
        $this->actingAs($this->worker)->put(route('cases.aftercare.plan', $this->case), $this->plan(['initial_follow_up_at' => now()->subMonths(3)->toDateString(), 'follow_up_until' => now()->subMonths(2)->toDateString()]));
        DB::table('aftercare_visits')->insert(['case_id' => $this->case, 'visited_on' => now()->subMonths(2)->subDays(10)->toDateString(), 'status' => 'stable', 'notes' => 'x', 'recorded_by' => $this->worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $data = Aftercare::forCase($this->case);
        $this->assertNull($data['next_due']);
        $this->assertTrue($data['complete']);
    }
}
