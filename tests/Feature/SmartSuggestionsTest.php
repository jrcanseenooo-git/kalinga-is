<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmartSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_suggests_rule_based_next_actions_only_for_own_cases(): void
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-SMART', 'name' => 'Synthetic City', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $make = function (User $owner, string $number, string $risk) use ($lgu) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Child', 'municipality' => 'Synthetic City', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'IN_PROGRESS', 'stage' => 'intervention',
                'priority' => 'normal', 'initial_risk_level' => $risk, 'opened_at' => now()->subDays(20), 'created_at' => now(), 'updated_at' => now()]);
        };
        $own = $make($worker, 'KLG-SMART-1', 'high');
        $hidden = $make($other, 'KLG-SMART-2', 'critical');
        DB::table('referrals')->insert(['case_id' => $own, 'service_category' => 'Legal assistance', 'referred_to' => 'PAO', 'status' => 'SENT', 'referred_at' => now()->subDays(10), 'created_by' => $worker->id, 'created_at' => now()->subDays(10), 'updated_at' => now()]);

        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('suggestions', function ($items) use ($own) {
                $items = collect($items);
                $kinds = $items->pluck('kind')->all();

                return $items->every(fn ($item) => (int) $item['case_id'] === $own && $item['case_number'] === 'KLG-SMART-1')
                    && in_array('referral', $kinds, true) && in_array('safety', $kinds, true) && in_array('assessment', $kinds, true) && in_array('stale', $kinds, true)
                    && $items->first()['tone'] === 'urgent'
                    && $items->every(fn ($item) => !str_contains(json_encode($item), 'Synthetic'));
            }));

        // Recording a safety review and a note clears those suggestions.
        DB::table('risk_assessments')->insert(['case_id' => $own, 'assessed_by' => $worker->id, 'assessment_type' => 'review', 'assessed_at' => now(), 'overall_risk_level' => 'high', 'immediate_protection_required' => false, 'status' => 'finalized', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_notes')->insert(['case_id' => $own, 'body' => 'Synthetic', 'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note', 'note_type' => 'contact', 'occurred_at' => now(), 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('suggestions', fn ($items) => !collect($items)->pluck('kind')->intersect(['safety', 'stale'])->count()));
        $this->assertNotNull($hidden);
    }

    public function test_case_list_shows_next_step_and_needs_action_view_within_scope(): void
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-SMART-LIST', 'name' => 'Synthetic Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $make = function (User $owner, string $number, int $openedDaysAgo) use ($lgu) {
            $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Listed', 'municipality' => 'Synthetic Town', 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'IN_PROGRESS', 'stage' => 'intervention',
                'priority' => 'normal', 'initial_risk_level' => 'low', 'opened_at' => now()->subDays($openedDaysAgo), 'created_at' => now(), 'updated_at' => now()]);
        };
        $due = $make($worker, 'KLG-LIST-DUE', 20);
        $fresh = $make($worker, 'KLG-LIST-FRESH', 1);
        DB::table('case_notes')->insert(['case_id' => $fresh, 'body' => 'Synthetic', 'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note', 'note_type' => 'contact', 'occurred_at' => now(), 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $make($other, 'KLG-LIST-OTHER', 20);

        $this->actingAs($worker)->get(route('cases.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts.needs_action', 1)
            ->where('cases.data', fn ($rows) => collect($rows)->firstWhere('id', $due)['next_step']['kind'] === 'assessment'
                && collect($rows)->firstWhere('id', $fresh)['next_step'] === null
                && !collect($rows)->contains('case_number', 'KLG-LIST-OTHER')));

        $this->get(route('cases.index', ['attention' => 1]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.attention', true)
            ->where('cases.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$due]));
    }
}
