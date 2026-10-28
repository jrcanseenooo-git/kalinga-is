<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseForms;
use App\Services\CaseStatusAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Intervention Plan and Referrals (owner, 2026-10-28): plan rows keep a key,
 * a status and target dates (not printed); a referral is conducted from a row
 * and stays in the progress notes; the plan is due 7 days after intake and
 * the supervisor is told when it is submitted or overdue.
 */
class InterventionPlanReferralsTest extends TestCase
{
    use RefreshDatabase;

    private int $lgu;

    private int $case;

    private User $worker;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-IPR', 'name' => 'Synthetic Plan Town', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $this->head = User::factory()->create(['role' => 'lgu_head', 'active' => true]);
        foreach ([$this->worker, $this->head] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $this->lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Planned', 'sex' => 'Female', 'birthdate' => now()->subYears(16)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->case = DB::table('cases')->insertGetId(['case_number' => 'KLG-IPR-1', 'client_id' => $client, 'lgu_id' => $this->lgu, 'assigned_to' => $this->worker->id, 'status' => 'IN_PROGRESS',
            'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => now()->subDays(3), 'intake_completed_at' => now()->subDays(3), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function row(array $extra = []): array
    {
        return array_merge(['id' => 'row-1', 'problem' => 'Stopped schooling', 'objective' => 'Return to school', 'activities' => 'ALS enrollment', 'responsible' => 'Case manager',
            'timeframe' => 'Next school year', 'expected_output' => 'Enrolled', 'status' => 'in_progress', 'target_start' => now()->toDateString(), 'target_end' => now()->addMonth()->toDateString()], $extra);
    }

    public function test_plan_rows_keep_key_status_and_target_dates_and_are_validated(): void
    {
        $this->actingAs($this->worker)->putJson(route('cases.forms.save', [$this->case, 'plan']), ['revision' => 0, 'content' => ['rows' => [$this->row(), ['problem' => 'Older row', 'id' => 'bad key!', 'status' => 'bogus']]]])->assertOk();
        $rows = json_decode(DB::table('case_forms')->where('form_type', 'plan')->value('content'), true)['rows'];
        $this->assertSame('row-1', $rows[0]['id']);
        $this->assertSame('in_progress', $rows[0]['status']);
        $this->assertSame(now()->addMonth()->toDateString(), $rows[0]['target_end']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $rows[1]['id'], 'An invalid key is replaced.');
        $this->assertSame('not_started', $rows[1]['status']);

        $this->putJson(route('cases.forms.save', [$this->case, 'plan']), ['revision' => 1, 'content' => ['rows' => [$this->row(['target_start' => '2026-12-10', 'target_end' => '2026-12-01'])]]])->assertStatus(422);
        $this->putJson(route('cases.forms.save', [$this->case, 'plan']), ['revision' => 1, 'content' => ['rows' => [$this->row(['target_end' => 'soon'])]]])->assertStatus(422);
        // A row with only a status (no text) is not content.
        $this->assertFalse(CaseForms::hasContent('plan', ['rows' => [['status' => 'not_started', 'id' => 'x', 'target_start' => '', 'target_end' => '']]]));
        // The printed columns are only the six text columns.
        $this->assertSame(['problem', 'objective', 'activities', 'responsible', 'timeframe', 'expected_output'], array_keys(CaseForms::PLAN_COLUMNS));
        // A row not marked done keeps the plan open for the automatic status.
        $this->assertTrue(CaseStatusAutomation::planHasOpenRows($this->case));
    }

    public function test_referral_conducted_from_a_plan_row_is_linked_and_kept_in_the_progress_notes(): void
    {
        $this->actingAs($this->worker)->post(route('cases.notes.store', $this->case), ['note_type' => 'referral', 'body' => 'Referral for Intervention Plan item 1: Stopped schooling.',
            'occurred_at' => now()->subMinute()->toIso8601String(), 'referral' => ['referred_to' => 'Department of Education (school)', 'service_category' => 'Educational assistance',
                'purpose' => 'School re-enrollment', 'lawful_basis' => 'public_task', 'plan_row' => 'row-1']])->assertSessionHasNoErrors();
        $referral = DB::table('referrals')->where('case_id', $this->case)->first();
        $this->assertSame('row-1', $referral->plan_row_key);
        $this->assertDatabaseHas('case_notes', ['id' => $referral->case_note_id, 'note_type' => 'referral']);
        $this->assertDatabaseHas('referral_events', ['referral_id' => $referral->id, 'remarks' => 'Conducted from the Intervention Plan.']);
        $this->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('referrals.0.plan_row_key', 'row-1'));

        $this->post(route('cases.notes.store', $this->case), ['note_type' => 'referral', 'body' => 'x', 'occurred_at' => now()->subMinute()->toIso8601String(),
            'referral' => ['referred_to' => 'PAO', 'service_category' => 'Legal assistance', 'purpose' => 'Legal counseling', 'lawful_basis' => 'public_task', 'plan_row' => "row'; drop"]])
            ->assertSessionHasErrors('referral.plan_row');
    }

    public function test_plan_is_due_seven_days_after_intake_and_the_supervisor_sees_the_submission_time(): void
    {
        $record = DB::table('cases')->find($this->case);
        $deadline = CaseForms::planDeadline($record);
        $this->assertSame(now('Asia/Manila')->subDays(3)->addDays(7)->toDateString(), $deadline['due']);
        $this->assertSame(4, $deadline['days_left']);
        $this->assertFalse($deadline['overdue']);
        $this->actingAs($this->worker)->get(route('cases.show', $this->case))->assertInertia(fn ($page) => $page->where('planDeadline.days_left', 4)->where('planDeadline.submitted_at', null));

        $this->putJson(route('cases.forms.save', [$this->case, 'plan']), ['revision' => 0, 'content' => ['rows' => [$this->row()]]])->assertOk();
        $this->post(route('cases.forms.finalize', [$this->case, 'plan']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->head->id, 'type' => 'plan_submitted', 'body' => 'KLG-IPR-1 - submitted 3 day(s) after intake (on time)']);
        $this->assertSame(3, CaseForms::planDeadline($record)['days_to_submit']);
        // A second version is not a new submission.
        $this->post(route('cases.forms.finalize', [$this->case, 'plan']));
        $this->assertSame(1, DB::table('notifications')->where('type', 'plan_submitted')->count());
    }

    public function test_deadline_alerts_remind_the_case_manager_and_tell_the_supervisor_when_overdue(): void
    {
        DB::table('cases')->where('id', $this->case)->update(['opened_at' => now()->subDays(6), 'intake_completed_at' => now()->subDays(6)]);
        Artisan::call('kalinga:dispatch-deadline-alerts');
        $this->assertDatabaseHas('notifications', ['user_id' => $this->worker->id, 'type' => 'plan_due_soon']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->head->id, 'type' => 'plan_overdue']);

        DB::table('cases')->where('id', $this->case)->update(['opened_at' => now()->subDays(10), 'intake_completed_at' => now()->subDays(10)]);
        Artisan::call('kalinga:dispatch-deadline-alerts');
        Artisan::call('kalinga:dispatch-deadline-alerts');
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->worker->id)->where('type', 'plan_overdue')->count());
        $this->assertSame(1, DB::table('notifications')->where('user_id', $this->head->id)->where('type', 'plan_overdue')->count());
    }
}
