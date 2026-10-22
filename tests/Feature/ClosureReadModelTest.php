<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClosureReadModelTest extends TestCase
{
    use RefreshDatabase;

    private function context(?User $worker = null): array
    {
        $worker ??= User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Private client marker', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-READ-' . $client, 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$worker, $case];
    }

    public function test_current_summary_and_selected_history_are_scoped_but_lists_omit_narratives(): void
    {
        [$worker, $case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic prior private summary.'])->assertRedirect();
        $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic current private summary.'])->assertRedirect();
        $response = $this->getJson(route('cases.closure-records.index', $case))->assertOk()
            ->assertJsonPath('current.summary', 'Synthetic current private summary.')
            ->assertJsonPath('current.version', 2)->assertJsonCount(2, 'history.data')
            ->assertDontSee('Private client marker');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        foreach ($response->json('history.data') as $item) {
            $this->assertArrayNotHasKey('summary', $item);
        }
        $id = DB::table('case_closure_records')->where('case_id', $case)->where('version', 1)->value('id');
        $this->getJson(route('cases.closure-records.show', [$case, $id]))->assertOk()->assertJsonPath('record.summary', 'Synthetic prior private summary.');
    }

    public function test_legacy_get_does_not_backfill_history_or_invent_authorship(): void
    {
        [$worker, $case] = $this->context();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic surviving summary.']);
        $this->actingAs($worker)->getJson(route('cases.closure-records.index', $case))->assertOk()
            ->assertJsonPath('current.summary', 'Synthetic surviving summary.')->assertJsonPath('current.source', 'legacy_case_summary')
            ->assertJsonPath('current.id', null)->assertJsonPath('current.recorded_by', null)->assertJsonPath('current.recorded_at', null);
        $this->assertDatabaseCount('case_closure_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_reopened_case_has_history_but_no_fabricated_current_record_from_old_closed_date(): void
    {
        [$worker, $case] = $this->context();
        $reviewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic completed closure.'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.closure.review', $case))->assertRedirect();
        $this->patch(route('cases.transition', $case), ['status' => 'CLOSED'])->assertRedirect();
        $this->actingAs($worker)->patch(route('cases.transition', $case), ['status' => 'REOPENED'])->assertRedirect();
        $this->getJson(route('cases.closure-records.index', $case))->assertOk()->assertJsonPath('current', null)->assertJsonCount(1, 'history.data');
        $this->assertDatabaseCount('case_closure_records', 1);
    }

    public function test_history_is_paginated_and_other_case_record_ids_are_not_resolvable(): void
    {
        [$worker, $case] = $this->context();
        [, $other] = $this->context($worker);
        $this->actingAs($worker);
        for ($version = 1; $version <= 12; $version++) {
            $this->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic revision ' . $version])->assertRedirect();
        }
        $this->getJson(route('cases.closure-records.index', $case))->assertOk()->assertJsonCount(10, 'history.data')->assertJsonPath('history.total', 12);
        $this->getJson(route('cases.closure-records.index', ['case' => $case, 'page' => 2]))->assertOk()->assertJsonCount(2, 'history.data');
        $id = DB::table('case_closure_records')->where('case_id', $case)->value('id');
        $this->getJson(route('cases.closure-records.show', [$other, $id]))->assertNotFound();
        $this->getJson(route('cases.closure-records.index', ['case' => $case, 'page' => 0]))->assertUnprocessable();
    }

    public function test_drift_is_visible_without_write_side_effects_and_cannot_offer_review_or_close(): void
    {
        [$worker, $case] = $this->context();
        $this->actingAs($worker)->post(route('cases.closure.store', $case), ['closure_summary' => 'Synthetic recorded summary.'])->assertRedirect();
        DB::table('cases')->where('id', $case)->update(['closure_summary' => 'Synthetic surviving drift.']);
        $before = DB::table('audit_logs')->count();
        $this->getJson(route('cases.closure-records.index', $case))->assertOk()->assertJsonPath('projection_drift', true)
            ->assertJsonPath('surviving_projection.summary', 'Synthetic surviving drift.')
            ->assertJsonPath('surviving_projection.recorded_by', null)->assertJsonPath('allowed_actions.review', false)
            ->assertJsonPath('allowed_actions.close', false)->assertJsonPath('allowed_actions.reopen', false);
        $this->assertDatabaseCount('case_closure_records', 1);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }

    public function test_unassigned_inactive_admin_and_cpu_users_cannot_read_and_archives_are_hidden(): void
    {
        [$worker, $case] = $this->context();
        foreach (['social_worker', 'admin', 'cpu_user'] as $role) {
            $actor = User::factory()->create(['role' => $role, 'province' => 'Kalinga']);
            $this->actingAs($actor)->getJson(route('cases.closure-records.index', $case))->assertForbidden();
        }
        $worker->update(['active' => false]);
        $this->actingAs($worker)->getJson(route('cases.closure-records.index', $case))->assertForbidden();
        $worker->update(['active' => true]);
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->actingAs($worker)->getJson(route('cases.closure-records.index', $case))->assertNotFound();
    }
}
