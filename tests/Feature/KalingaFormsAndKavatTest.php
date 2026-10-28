<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kavat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** KaVAT scoring, the Kalinga forms (autosave, conflicts, versions) and the grants log. */
class KalingaFormsAndKavatTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private int $case;

    protected function setUp(): void
    {
        parent::setUp();
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'KAV-1', 'name' => 'Synthetic Tabuk', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Child', 'sex' => 'Female', 'birthdate' => now()->subYears(16)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->case = DB::table('cases')->insertGetId(['case_number' => 'KAV-CASE-1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $this->worker->id, 'status' => 'IN_PROGRESS',
            'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The worked example in the guidelines: Part 1 = 65, Part 2 = 88, final 26.92 (High Priority). */
    private function exampleAnswers(): array
    {
        $part1 = array_fill_keys(range(1, 25), 'maybe');
        foreach ([1, 2, 3, 6, 7] as $n) {
            $part1[$n] = 'no';
        }

        return ['part1' => $part1, 'part2' => ['living_arrangement' => '1', 'family_support' => '1', 'dependents' => '1', 'education' => '1', 'economic_support' => '1',
            'breadwinner' => 'yes', 'income' => 'yes', 'partner_age_gap' => '1', 'age_at_union' => 'minor', 'decision' => '1', 'violence' => 'yes', 'safety' => 'no']];
    }

    public function test_scoring_follows_the_guideline_example(): void
    {
        $score = Kavat::score($this->exampleAnswers());
        $this->assertTrue($score['complete']);
        $this->assertSame(65, $score['part1']);
        $this->assertEquals(88, $score['part2']);
        $this->assertEquals(52, $score['empowerment_rate']);
        $this->assertEquals(89.8, $score['objective_risk_rate']);
        $this->assertEquals(26.92, $score['final_score']);
        $this->assertSame('high', $score['priority']);
        $this->assertSame(98, array_sum(array_column($score['sections'], 'max')));
        $this->assertSame(Kavat::definition(), json_decode(file_get_contents(base_path('tests/frontend/fixtures/kavat-definition.json')), true),
            'Refresh tests/frontend/fixtures/kavat-definition.json from Kavat::definition() after changing the instrument.');

        $reversed = Kavat::score(['part1' => [4 => 'yes', 1 => 'yes'], 'part2' => ['safety' => 'rta']]);
        $this->assertSame(6, $reversed['part1']);
        $this->assertFalse($reversed['complete']);
        $this->assertNull($reversed['final_score']);
        $this->assertSame('safety', $reversed['red_flags'][0]['question']);
    }

    public function test_case_manager_answers_and_finalizes_a_kavat(): void
    {
        // The baseline is done before intake in the separate tool, so it cannot be started here.
        $this->actingAs($this->worker)->post(route('cases.kavat.store', $this->case), ['occasion' => 'baseline', 'administered_at' => now()->toDateString()])->assertSessionHasErrors('occasion');
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'quarterly', 'administered_at' => now()->toDateString()])->assertSessionHasErrors('occasion');
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'midline', 'administered_at' => now()->toDateString()])->assertRedirect();
        $kavat = DB::table('kavat_assessments')->first();
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'endline', 'administered_at' => now()->toDateString()])->assertStatus(422);

        $this->putJson(route('cases.kavat.save', [$this->case, $kavat->id]), ['revision' => 1, 'answers' => ['part1' => [1 => 'yes'], 'part2' => []]])->assertOk()->assertJsonPath('revision', 2);
        $this->putJson(route('cases.kavat.save', [$this->case, $kavat->id]), ['revision' => 1, 'answers' => ['part1' => [], 'part2' => []]])->assertStatus(409);
        $this->putJson(route('cases.kavat.save', [$this->case, $kavat->id]), ['revision' => 2, 'answers' => ['part1' => [], 'part2' => ['safety' => 'bogus']]])->assertStatus(422);
        $this->post(route('cases.kavat.finalize', [$this->case, $kavat->id]))->assertStatus(422);

        $this->putJson(route('cases.kavat.save', [$this->case, $kavat->id]), ['revision' => 2, 'answers' => $this->exampleAnswers()])->assertOk()->assertJsonPath('score.final_score', 26.92);
        $this->post(route('cases.kavat.finalize', [$this->case, $kavat->id]))->assertRedirect();
        $this->assertDatabaseHas('kavat_assessments', ['id' => $kavat->id, 'status' => 'final', 'priority' => 'high', 'part1_score' => 65]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'kavat.finalized', 'log_type' => 'workflow_status']);
        $this->putJson(route('cases.kavat.save', [$this->case, $kavat->id]), ['revision' => 3, 'answers' => $this->exampleAnswers()])->assertStatus(422);

        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('kavat.0.status', 'final')->has('kavatDefinition.domains', 7)->has('forms.study'));
    }

    public function test_forms_autosave_with_conflict_detection_and_numbered_versions(): void
    {
        $this->actingAs($this->worker);
        $this->putJson(route('cases.forms.save', [$this->case, 'summary']), ['revision' => 0, 'content' => ['sections' => ['presenting_problem' => 'First draft.', 'unknown' => 'dropped']]])
            ->assertOk()->assertJsonPath('revision', 1);
        $this->putJson(route('cases.forms.save', [$this->case, 'summary']), ['revision' => 0, 'content' => ['sections' => ['presenting_problem' => 'Other device.']]])
            ->assertStatus(409)->assertJsonPath('content.sections.presenting_problem', 'First draft.');
        $this->assertStringNotContainsString('dropped', DB::table('case_forms')->value('content'));

        $this->post(route('cases.forms.finalize', [$this->case, 'plan']))->assertSessionHasErrors('content');
        $this->putJson(route('cases.forms.save', [$this->case, 'plan']), ['revision' => 0, 'content' => ['rows' => [['problem' => 'Stopped schooling', 'objective' => 'Return to school', 'activities' => 'ALS enrollment']]]])->assertOk();
        $this->post(route('cases.forms.finalize', [$this->case, 'plan']))->assertRedirect();
        $this->post(route('cases.forms.finalize', [$this->case, 'plan']))->assertRedirect();
        $this->assertSame(2, DB::table('case_form_versions')->where('form_type', 'plan')->max('version'));
        $this->get(route('cases.reports.show', [$this->case, 'summary']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/CaseReport')->where('form.hasContent', true)->where('form.content.sections.presenting_problem', 'First draft.'));
        $this->get(route('cases.reports.show', [$this->case, 'study']))->assertInertia(fn ($page) => $page->where('form.hasContent', false)->where('planMatrix.0.problem', 'Stopped schooling'));
        $this->putJson(route('cases.forms.save', [$this->case, 'bogus']), ['revision' => 0, 'content' => []])->assertNotFound();

        $stranger = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($stranger)->putJson(route('cases.forms.save', [$this->case, 'summary']), ['revision' => 1, 'content' => ['sections' => []]])->assertNotFound();
        $head = User::factory()->create(['role' => 'lgu_head']);
        $this->actingAs($head)->putJson(route('cases.forms.save', [$this->case, 'summary']), ['revision' => 1, 'content' => ['sections' => []]])->assertForbidden();

        DB::table('cases')->where('id', $this->case)->update(['status' => 'CLOSED']);
        $this->actingAs($this->worker)->putJson(route('cases.forms.save', [$this->case, 'summary']), ['revision' => 1, 'content' => ['sections' => []]])->assertStatus(422);
    }

    public function test_grants_log_records_and_voids_without_deleting(): void
    {
        $this->actingAs($this->worker);
        $this->post(route('cases.grants.store', $this->case), ['grant_type' => 'educational', 'amount' => 3000, 'released_at' => now()->toDateString(), 'fund_source' => 'LGU'])->assertRedirect();
        $this->post(route('cases.grants.store', $this->case), ['grant_type' => 'financial', 'amount' => 2000, 'released_at' => now()->toDateString()])->assertRedirect();
        $this->post(route('cases.grants.store', $this->case), ['grant_type' => 'nope', 'amount' => 0, 'released_at' => now('Asia/Manila')->addDay()->toDateString()])->assertSessionHasErrors(['grant_type', 'amount', 'released_at']);
        $grant = DB::table('case_grants')->where('grant_type', 'financial')->value('id');
        $this->post(route('cases.grants.void', [$this->case, $grant]), ['reason' => 'Entered twice by mistake.'])->assertRedirect();
        $this->assertSame(2, DB::table('case_grants')->count());
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('grants.total', 3000)->where('grants.count', 1));
        $this->assertDatabaseHas('audit_logs', ['event' => 'grant.voided', 'log_type' => 'data_modification']);
    }

    public function test_field_mode_carries_forms_and_kavat_and_syncs_them_with_conflict_detection(): void
    {
        $headers = ['X-Kalinga-Device-ID' => 'cbb90eda-653b-4c39-8120-5511cb2ea0aa'];
        $this->actingAs($this->worker)->withSession(['two_factor_confirmed' => true]);
        $this->putJson(route('cases.forms.save', [$this->case, 'study']), ['revision' => 0, 'content' => ['sections' => ['sources' => 'Client']]])->assertOk();
        $this->getJson(route('offline.workspace.pack'), $headers)->assertOk()
            ->assertJsonPath('cases.0.forms.study.revision', 1)->assertJsonPath('cases.0.forms.study.content.sections.sources', 'Client')
            ->assertJsonPath('cases.0.kavat_draft', null)->assertJsonPath('kavatDefinition.part2Max', 98);

        $send = fn (string $resource, array $payload, ?string $id = null) => $this->postJson('/sync', ['offline_id' => $id ?? (string) Str::uuid(), 'resource' => $resource, 'operation' => 'update', 'payload' => $payload], $headers);
        $send('case_form', ['case_id' => $this->case, 'form_type' => 'study', 'revision' => 1, 'content' => ['sections' => ['sources' => 'Client and mother']]])->assertOk()->assertJsonPath('revision', 2);
        $id = (string) Str::uuid();
        $stale = ['case_id' => $this->case, 'form_type' => 'study', 'revision' => 1, 'content' => ['sections' => ['sources' => 'Older copy']]];
        $send('case_form', $stale, $id)->assertStatus(409);
        $this->assertStringContainsString('Client and mother', DB::table('case_forms')->where('form_type', 'study')->value('content'));
        $this->assertDatabaseHas('offline_sync_items', ['offline_id' => $id, 'status' => 'conflict']);
        $this->assertStringNotContainsString('Older copy', DB::table('offline_sync_items')->where('offline_id', $id)->value('payload'));

        $send('kavat', ['case_id' => $this->case, 'kavat_id' => null, 'revision' => 0, 'answers' => ['part1' => ['1' => 'yes'], 'part2' => []], 'occasion' => 'midline', 'administered_at' => now()->toDateString()])
            ->assertOk()->assertJsonPath('revision', 1);
        $kavat = DB::table('kavat_assessments')->value('id');
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => $kavat, 'revision' => 1, 'answers' => $this->exampleAnswers(), 'occasion' => 'midline', 'administered_at' => now()->toDateString()])->assertOk();
        $this->assertSame('draft', DB::table('kavat_assessments')->value('status'));
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => null, 'revision' => 0, 'answers' => ['part1' => [], 'part2' => []], 'occasion' => 'endline', 'administered_at' => now()->toDateString()])->assertStatus(409);
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => $kavat, 'revision' => 2, 'answers' => ['part1' => ['99' => 'yes'], 'part2' => []], 'occasion' => 'midline', 'administered_at' => now()->toDateString()])->assertStatus(422);
        $this->getJson(route('offline.workspace.pack'), $headers)->assertJsonPath('cases.0.kavat_draft.revision', 2);

        $other = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($other)->postJson('/sync', ['offline_id' => (string) Str::uuid(), 'resource' => 'case_form', 'operation' => 'update',
            'payload' => ['case_id' => $this->case, 'form_type' => 'study', 'revision' => 2, 'content' => ['sections' => []]]], ['X-Kalinga-Device-ID' => 'cbb90eda-653b-4c39-8120-5511cb2ea0bb'])->assertForbidden();
    }

    public function test_baseline_result_from_the_screening_tool_is_recorded_and_corrected(): void
    {
        $this->actingAs($this->worker)->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('kavatBaseline', null)
            ->where('kavatDefinition.startOccasions', ['midline', 'endline']));
        $this->post(route('cases.kavat.baseline', $this->case), ['administered_at' => now('Asia/Manila')->addDay()->toDateString(), 'empowerment_rate' => 120, 'safety_rate' => 50])
            ->assertSessionHasErrors(['administered_at', 'empowerment_rate']);
        $this->post(route('cases.kavat.baseline', $this->case), ['administered_at' => now()->subMonth()->toDateString(), 'empowerment_rate' => 52, 'safety_rate' => 10.2])->assertSessionHasNoErrors();
        // 52 x 0.4 + 10.2 x 0.6 = 26.92, High Priority (below 70).
        $this->assertDatabaseHas('kavat_baselines', ['case_id' => $this->case, 'final_score' => 26.92, 'priority' => 'high']);
        $this->post(route('cases.kavat.baseline', $this->case), ['administered_at' => now()->subMonth()->toDateString(), 'empowerment_rate' => 80, 'safety_rate' => 75, 'notes' => 'Corrected from the sheet.'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('kavat_baselines')->where('case_id', $this->case)->count());
        $this->assertDatabaseHas('kavat_baselines', ['case_id' => $this->case, 'final_score' => 77, 'priority' => 'standard']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'kavat.baseline_recorded', 'old_value' => '26.92', 'new_value' => '77']);
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('kavatBaseline.final_score', 77)->where('kavatBaseline.notes', 'Corrected from the sheet.'));
        $stranger = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($stranger)->post(route('cases.kavat.baseline', $this->case), ['administered_at' => now()->toDateString(), 'empowerment_rate' => 1, 'safety_rate' => 1])->assertNotFound();
    }

    public function test_only_one_midline_and_one_endline_are_recorded(): void
    {
        $this->actingAs($this->worker);
        foreach (['midline', 'endline'] as $occasion) {
            $this->post(route('cases.kavat.store', $this->case), ['occasion' => $occasion, 'administered_at' => now()->toDateString()])->assertSessionHasNoErrors();
            $id = DB::table('kavat_assessments')->where('occasion', $occasion)->value('id');
            $this->putJson(route('cases.kavat.save', [$this->case, $id]), ['revision' => 1, 'answers' => $this->exampleAnswers()])->assertOk();
            $this->post(route('cases.kavat.finalize', [$this->case, $id]))->assertSessionHasNoErrors();
        }
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'midline', 'administered_at' => now()->toDateString()])->assertSessionHasErrors('occasion');
        $this->assertSame(2, DB::table('kavat_assessments')->where('case_id', $this->case)->count());
    }

    public function test_a_finalized_kavat_is_corrected_with_a_reason_and_can_be_cancelled(): void
    {
        $this->actingAs($this->worker)->post(route('cases.kavat.store', $this->case), ['occasion' => 'midline', 'administered_at' => now()->toDateString()]);
        $id = DB::table('kavat_assessments')->value('id');
        $this->putJson(route('cases.kavat.save', [$this->case, $id]), ['revision' => 1, 'answers' => $this->exampleAnswers()])->assertOk();
        $this->post(route('cases.kavat.finalize', [$this->case, $id]))->assertSessionHasNoErrors();
        $finalizedAt = DB::table('kavat_assessments')->value('finalized_at');

        // Open for correction: the previous answers and score are kept.
        $this->post(route('cases.kavat.correct', [$this->case, $id]))->assertSessionHasNoErrors();
        $row = DB::table('kavat_assessments')->find($id);
        $this->assertSame('draft', $row->status);
        $this->assertEquals(26.92, (float) $row->previous_final_score);
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('kavat.0.correcting', true)->where('kavat.0.previous_final_score', 26.92));

        // Cancel restores the finalized answers.
        $changed = $this->exampleAnswers();
        $changed['part1'][1] = $changed['part1'][1] === 'yes' ? 'no' : 'yes';
        $this->putJson(route('cases.kavat.save', [$this->case, $id]), ['revision' => (int) $row->revision, 'answers' => $changed])->assertOk();
        $this->delete(route('cases.kavat.destroy', [$this->case, $id]))->assertSessionHasNoErrors();
        $row = DB::table('kavat_assessments')->find($id);
        $this->assertSame('final', $row->status);
        $this->assertNull($row->previous_answers);
        $this->assertEquals(json_decode(json_encode($this->exampleAnswers()['part1']), true), json_decode($row->answers, true)['part1']);

        // Correct again, change an answer and finalize: a reason is required.
        $this->post(route('cases.kavat.correct', [$this->case, $id]));
        $revision = (int) DB::table('kavat_assessments')->where('id', $id)->value('revision');
        $this->putJson(route('cases.kavat.save', [$this->case, $id]), ['revision' => $revision, 'answers' => $changed])->assertOk();
        $this->post(route('cases.kavat.finalize', [$this->case, $id]))->assertSessionHasErrors('correction_reason');
        $this->post(route('cases.kavat.finalize', [$this->case, $id]), ['correction_reason' => 'Statement 1 was recorded wrongly.'])->assertSessionHasNoErrors();
        $row = DB::table('kavat_assessments')->find($id);
        $this->assertSame('final', $row->status);
        $this->assertSame('Statement 1 was recorded wrongly.', $row->correction_reason);
        $this->assertSame($this->worker->id, (int) $row->corrected_by);
        $this->assertEquals($finalizedAt, $row->finalized_at, 'The original finalization date is kept.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'kavat.corrected', 'old_value' => '26.92']);

        // Not while another KaVAT is in progress, and not by someone outside the case.
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'endline', 'administered_at' => now()->toDateString()]);
        $this->post(route('cases.kavat.correct', [$this->case, $id]))->assertStatus(422);
        $stranger = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($stranger)->post(route('cases.kavat.correct', [$this->case, $id]))->assertNotFound();
    }

    public function test_today_in_the_philippines_is_accepted_before_8_am(): void
    {
        // 01:30 on 28 October in Manila is still 27 October on the UTC server clock.
        $this->travelTo(Carbon::parse('2026-10-27 17:30:00', 'UTC'));
        $this->actingAs($this->worker)->post(route('cases.kavat.store', $this->case), ['occasion' => 'midline', 'administered_at' => '2026-10-28'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('kavat_assessments', ['case_id' => $this->case, 'administered_at' => '2026-10-28']);
        $this->post(route('cases.kavat.baseline', $this->case), ['administered_at' => '2026-10-29', 'empowerment_rate' => 50, 'safety_rate' => 50])->assertSessionHasErrors('administered_at');
        $this->travelBack();
    }
}
