<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Kalinga Intake Sheet, Progress Notes and Intervention Plan reports: content,
 * privacy exclusions, period filter, access control and audit.
 */
class CaseReportsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-RPT', 'name' => 'Synthetic Town', 'province' => 'Synthetic Province', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker', 'position' => 'Social Welfare Officer I']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Reported', 'birthdate' => now()->subYears(16)->toDateString(), 'sex' => 'Female',
            'municipality' => 'Synthetic Town', 'province' => 'Synthetic Province', 'phone' => '09171234567', 'primary_caregiver_name' => 'Synthetic Guardian',
            'primary_caregiver_relationship' => 'Mother', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-TEST-RPT1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'IN_PROGRESS',
            'stage' => 'intervention', 'priority' => 'normal', 'category' => 'CEFMU', 'cefmu_classifications' => json_encode(['early_union']), 'union_type' => 'informal_cohabitation',
            'problem_presented' => 'Synthetic problem.', 'initial_assessment' => 'Synthetic assessment.', 'pregnancy_parenting_status' => 'pregnant',
            'privacy_notice_method' => 'explained_verbally', 'opened_at' => now()->subDays(40), 'intake_completed_at' => now()->subDays(40), 'created_at' => now(), 'updated_at' => now()]);

        return compact('lgu', 'worker', 'client', 'case');
    }

    private function note(int $case, int $author, string $when, string $body, array $extra = []): int
    {
        return DB::table('case_notes')->insertGetId(array_merge(['case_id' => $case, 'body' => $body, 'visibility' => 'CASE_NOTES', 'record_type' => 'progress_note',
            'note_type' => 'contact', 'occurred_at' => $when, 'finalized_at' => now(), 'finalized_by' => $author, 'is_current' => true, 'created_by' => $author,
            'created_at' => now(), 'updated_at' => now()], $extra));
    }

    public function test_intake_sheet_carries_identifying_information_but_not_health_details(): void
    {
        extract($this->fixture());
        $this->actingAs($worker)->get(route('cases.reports.show', [$case, 'intake']))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('Reports/CaseReport')->where('type', 'intake')->where('title', 'Kalinga Intake Sheet')
                ->where('reportNo', fn ($no) => str_starts_with($no, 'GIS-KLG-TEST-RPT1-'))
                ->where('client.caregiver', 'Synthetic Guardian')->where('caseInfo.union_type', 'informal_cohabitation')
                ->where('intake.health_recorded', true)->where('intake.privacy_notice_method', 'explained_verbally')
                ->missing('caseInfo.pregnancy_parenting_status')->missing('intake.pregnancy_parenting_status'));
    }

    public function test_progress_notes_report_lists_case_team_notes_with_addenda_within_the_period(): void
    {
        extract($this->fixture());
        $old = $this->note($case, $worker->id, now()->subDays(30)->toDateTimeString(), 'Synthetic older contact');
        $recent = $this->note($case, $worker->id, now()->subDays(2)->toDateTimeString(), 'Synthetic recent visit', ['action_taken' => 'Synthetic action', 'next_steps' => 'Synthetic next']);
        $this->note($case, $worker->id, now()->subDay()->toDateTimeString(), 'Synthetic correction', ['record_type' => 'addendum', 'previous_note_id' => $recent, 'addendum_reason' => 'Date corrected']);
        $this->note($case, $worker->id, now()->subDays(3)->toDateTimeString(), 'Synthetic MDT-only note', ['visibility' => 'MDT_SHARED']);
        $legacy = DB::table('case_notes')->insertGetId(['case_id' => $case, 'body' => 'Synthetic legacy note', 'visibility' => 'CASE_NOTES', 'created_by' => $worker->id,
            'created_at' => now()->subDays(20), 'updated_at' => now()]);

        $this->actingAs($worker)->get(route('cases.reports.show', [$case, 'notes']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('type', 'notes')->has('progressNotes', 3)
            ->where('progressNotes.0.id', $old)->where('progressNotes.1.id', $legacy)->where('progressNotes.2.action', 'Synthetic action')
            ->where('progressNotes.2.addenda.0.reason', 'Date corrected')
            ->where('progressNotes', fn ($notes) => !str_contains(json_encode($notes), 'MDT-only')));

        $from = now('Asia/Manila')->subDays(7)->toDateString();
        $this->get(route('cases.reports.show', [$case, 'notes']) . '?from=' . $from)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('progressNotes', 1)->where('progressNotes.0.id', $recent)->where('period.from', $from));
        $this->get(route('cases.reports.show', [$case, 'notes']) . '?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
        $this->assertTrue(DB::table('audit_logs')->where('event', 'case_report.generated')->where('auditable_id', $case)->pluck('changes')
            ->contains(fn ($changes) => json_decode($changes, true) == ['type' => 'notes', 'from' => $from]));
    }

    public function test_intervention_plan_prints_goals_and_withholds_health_domain_content(): void
    {
        extract($this->fixture());
        $plan = DB::table('case_plans')->insertGetId(['case_id' => $case, 'version' => 1, 'plan_date' => now()->toDateString(), 'status' => 'active', 'is_current' => true,
            'overall_objective' => 'Synthetic objective', 'summary' => 'Synthetic objective', 'authored_by' => $worker->id, 'review_due_at' => now()->addMonth()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $education = DB::table('case_plan_goals')->insertGetId(['case_plan_id' => $plan, 'domain' => 'education', 'need_problem' => 'Synthetic out of school', 'goal_statement' => 'Synthetic return to school',
            'priority' => 'high', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $health = DB::table('case_plan_goals')->insertGetId(['case_plan_id' => $plan, 'domain' => 'reproductive_health', 'need_problem' => 'Synthetic SRH need', 'goal_statement' => 'Synthetic SRH goal',
            'priority' => 'high', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$education, 'Synthetic enrolment support'], [$health, 'Synthetic SRH counselling']] as [$goal, $activity]) {
            DB::table('interventions')->insert(['case_plan_id' => $plan, 'case_plan_goal_id' => $goal, 'need' => 'n', 'goal' => 'g', 'service_category' => 'Education', 'activity' => $activity,
                'responsible_office' => 'Synthetic school', 'target_date' => now()->addMonth()->toDateString(), 'progress' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->actingAs($worker)->get(route('cases.reports.show', [$case, 'plan']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('plan.current', true)->where('plan.objective', 'Synthetic objective')->has('plan.goals', 2)
            ->where('plan.goals.0.interventions.0.activity', 'Synthetic enrolment support')->where('plan.goals.0.interventions.0.responsible', 'Synthetic school')
            ->where('plan.goals.1.sensitive', true)->where('plan.goals.1.need_problem', null)->where('plan.goals.1.interventions', [])
            ->where('plan', fn ($plan) => !str_contains(json_encode($plan), 'SRH')));

        // The summary report no longer lists health-domain goals as recommendations.
        $this->get(route('cases.reports.show', [$case, 'summary']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('plan.goals', 1)->where('plan.goals.0.goal_statement', 'Synthetic return to school'));
    }

    public function test_plan_report_without_a_plan_and_access_rules(): void
    {
        extract($this->fixture());
        $this->actingAs($worker)->get(route('cases.reports.show', [$case, 'plan']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('plan', null));

        $stranger = User::factory()->create(['role' => 'social_worker']);
        foreach (['intake', 'notes', 'plan'] as $type) {
            $this->actingAs($stranger)->get(route('cases.reports.show', [$case, $type]))->assertForbidden();
        }
        $cpu = User::factory()->create(['role' => 'cpu_member', 'cpu_discipline' => 'health_officer']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $cpu->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($cpu)->get(route('cases.reports.show', [$case, 'intake']))->assertForbidden();
        $this->actingAs($worker)->get('/cases/' . $case . '/reports/unknown')->assertNotFound();
        $this->actingAs($worker)->get(route('cases.reports.show', [999999, 'intake']))->assertNotFound();
        // Tampered, non-numeric ids are a 404, never a server error.
        foreach (['/cases/undefined/reports/intake', '/cases/1abc', '/cases/../reports/notes'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
