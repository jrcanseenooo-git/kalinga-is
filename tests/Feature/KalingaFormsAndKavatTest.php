<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kavat;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($this->worker)->post(route('cases.kavat.store', $this->case), ['occasion' => 'baseline', 'administered_at' => now()->toDateString()])->assertRedirect();
        $kavat = DB::table('kavat_assessments')->first();
        $this->post(route('cases.kavat.store', $this->case), ['occasion' => 'review', 'administered_at' => now()->toDateString()])->assertStatus(422);

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
        $this->post(route('cases.grants.store', $this->case), ['grant_type' => 'nope', 'amount' => 0, 'released_at' => now()->addDay()->toDateString()])->assertSessionHasErrors(['grant_type', 'amount', 'released_at']);
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

        $send('kavat', ['case_id' => $this->case, 'kavat_id' => null, 'revision' => 0, 'answers' => ['part1' => ['1' => 'yes'], 'part2' => []], 'occasion' => 'baseline', 'administered_at' => now()->toDateString()])
            ->assertOk()->assertJsonPath('revision', 1);
        $kavat = DB::table('kavat_assessments')->value('id');
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => $kavat, 'revision' => 1, 'answers' => $this->exampleAnswers(), 'occasion' => 'baseline', 'administered_at' => now()->toDateString()])->assertOk();
        $this->assertSame('draft', DB::table('kavat_assessments')->value('status'));
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => null, 'revision' => 0, 'answers' => ['part1' => [], 'part2' => []], 'occasion' => 'review', 'administered_at' => now()->toDateString()])->assertStatus(409);
        $send('kavat', ['case_id' => $this->case, 'kavat_id' => $kavat, 'revision' => 2, 'answers' => ['part1' => ['99' => 'yes'], 'part2' => []], 'occasion' => 'baseline', 'administered_at' => now()->toDateString()])->assertStatus(422);
        $this->getJson(route('offline.workspace.pack'), $headers)->assertJsonPath('cases.0.kavat_draft.revision', 2);

        $other = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->actingAs($other)->postJson('/sync', ['offline_id' => (string) Str::uuid(), 'resource' => 'case_form', 'operation' => 'update',
            'payload' => ['case_id' => $this->case, 'form_type' => 'study', 'revision' => 2, 'content' => ['sections' => []]]], ['X-Kalinga-Device-ID' => 'cbb90eda-653b-4c39-8120-5511cb2ea0bb'])->assertForbidden();
    }
}
