<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NoteReferralsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-NOTE-REF', 'name' => 'Synthetic City', 'province' => 'Synthetic Province', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker', 'position' => 'Social Welfare Officer II']);
        $cpu = User::factory()->create(['role' => 'cpu_member', 'cpu_discipline' => 'health_officer']);
        foreach ([$worker, $cpu] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Child', 'birthdate' => now()->subYears(15)->toDateString(), 'sex' => 'Female', 'municipality' => 'Synthetic City', 'province' => 'Synthetic Province', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-TEST-NR1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'IN_PROGRESS', 'stage' => 'intervention',
            'priority' => 'normal', 'category' => 'CEFMU', 'cefmu_classifications' => json_encode(['early_union']), 'problem_presented' => 'Synthetic problem presented.', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $member = DB::table('lgu_cpu_members')->insertGetId(['lgu_id' => $lgu, 'name' => 'Synthetic MHO', 'position' => 'Municipal Health Officer', 'office' => 'Municipal / City Health Office',
            'cpu_role' => 'Health representative', 'user_id' => $cpu->id, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return compact('lgu', 'worker', 'cpu', 'case', 'member');
    }

    private function note(array $extra): array
    {
        return array_merge(['body' => 'Synthetic note body.', 'occurred_at' => now()->subHour()->toIso8601String()], $extra);
    }

    public function test_referral_note_sends_referral_and_opens_cpu_member_access_until_completed(): void
    {
        extract($this->fixture());
        $this->actingAs($worker)->post(route('cases.notes.store', $case), $this->note(['note_type' => 'referral', 'referral' => [
            'referred_to' => 'Synthetic MHO', 'lgu_cpu_member_id' => $member, 'service_category' => 'Medical / medico-legal examination',
            'purpose' => 'Medical check-up', 'lawful_basis' => 'vital_interests']]))->assertRedirect()->assertSessionHas('success', 'Referral recorded and sent.');

        $referral = DB::table('referrals')->where('case_id', $case)->first();
        $note = DB::table('case_notes')->where('case_id', $case)->first();
        $this->assertSame('SENT', $referral->status);
        $this->assertSame($note->id, (int) $referral->case_note_id);
        $this->assertSame($cpu->id, (int) $referral->recipient_user_id);
        $this->assertSame('referral', $note->note_type);
        $grant = DB::table('mdt_case_access')->find($referral->mdt_case_access_id);
        $this->assertSame($cpu->id, (int) $grant->user_id);
        $this->assertSame('assigned', $grant->status);
        $this->assertDatabaseHas('notifications', ['user_id' => $cpu->id, 'type' => 'mdt_assignment']);

        // Accept, start, complete: access ends with the referral.
        foreach ([['SENT', 'ACCEPTED'], ['ACCEPTED', 'IN_PROGRESS']] as [$from, $to]) {
            $this->patch(route('referrals.transition', $referral->id), ['expected_status' => $from, 'status' => $to])->assertRedirect();
        }
        $this->patch(route('referrals.transition', $referral->id), ['expected_status' => 'IN_PROGRESS', 'status' => 'COMPLETED', 'outcome' => 'Synthetic check-up done.'])->assertRedirect();
        $this->assertSame('completed', DB::table('mdt_case_access')->where('id', $grant->id)->value('status'));

        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('cpuMembers', 1)->where('cpuMembers.0.has_account', true)->has('referrals', 1)->where('referrals.0.case_note_id', $note->id));
    }

    public function test_referral_note_is_refused_without_consent_or_with_a_member_of_another_lgu(): void
    {
        extract($this->fixture());
        $otherLgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-NOTE-REF-2', 'name' => 'Other Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreign = DB::table('lgu_cpu_members')->insertGetId(['lgu_id' => $otherLgu, 'name' => 'Far Member', 'cpu_role' => 'Member', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker);
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'referral', 'referral' => ['referred_to' => 'PAO', 'service_category' => 'Legal assistance', 'purpose' => 'Legal counseling', 'lawful_basis' => 'consent']]))
            ->assertSessionHasErrors('referral.lawful_basis');
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'referral', 'referral' => ['referred_to' => 'Far', 'lgu_cpu_member_id' => $foreign, 'service_category' => 'Legal assistance', 'purpose' => 'Legal counseling', 'lawful_basis' => 'public_task']]))
            ->assertSessionHasErrors('referral.lgu_cpu_member_id');
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'referral']))->assertSessionHasErrors(['referral.referred_to', 'referral.purpose']);
        $this->assertSame(0, DB::table('referrals')->count());
        $this->assertSame(0, DB::table('case_notes')->count());

        // A CPU member cannot make referrals from notes.
        $this->actingAs($cpu)->post(route('cases.notes.store', $case), $this->note(['note_type' => 'referral']))->assertForbidden();
    }

    public function test_service_note_records_services_and_requires_amount_for_financial_help(): void
    {
        extract($this->fixture());
        $this->actingAs($worker);
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'service_delivery', 'service' => ['service_type' => 'Financial', 'date_provided' => now()->toDateString()]]))
            ->assertSessionHasErrors('service.amount');
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'service_delivery', 'service' => ['service_type' => 'Financial', 'specific_detail' => 'Transport to hearing', 'amount' => 1500, 'date_provided' => now()->toDateString()]]))
            ->assertRedirect()->assertSessionHas('success', 'Service recorded in Services provided.');
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'service_delivery', 'service' => ['service_type' => 'Legal', 'amount' => 999, 'date_provided' => now()->toDateString()]]))->assertRedirect();
        $this->post(route('cases.notes.store', $case), $this->note(['note_type' => 'service_delivery', 'service' => ['service_type' => 'Medical', 'date_provided' => now()->addDays(2)->toDateString()]]))->assertSessionHasErrors('service.date_provided');

        $this->assertSame(['1500.00', null], DB::table('services_provided')->orderBy('id')->pluck('amount')->map(fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', ''))->all());
        $this->get(route('cases.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->has('servicesProvided', 2));
    }

    public function test_case_reports_carry_lgu_signatories_and_stay_within_scope(): void
    {
        extract($this->fixture());
        DB::table('lgu_profiles')->insert(['lgu_id' => $lgu, 'office_name' => 'City Social Welfare and Development Office', 'office_short_name' => 'CSWDO',
            'head_name' => 'Synthetic Head', 'head_position' => 'Social Welfare Officer IV', 'created_at' => now(), 'updated_at' => now()]);
        $assessment = DB::table('case_assessments')->insertGetId(['case_id' => $case, 'version' => 1, 'assessment_date' => now()->toDateString(), 'status' => 'finalized', 'authored_by' => $worker->id, 'finalized_at' => now(), 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('case_assessment_sections')->insert([
            ['case_assessment_id' => $assessment, 'section_key' => 'professional_assessment', 'analysis' => 'Synthetic professional analysis.', 'restricted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['case_assessment_id' => $assessment, 'section_key' => 'health_srh', 'analysis' => 'Synthetic health detail.', 'restricted' => false, 'created_at' => now(), 'updated_at' => now()],
            ['case_assessment_id' => $assessment, 'section_key' => 'psychosocial_environment', 'analysis' => 'Synthetic restricted text.', 'restricted' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach (['summary', 'study'] as $type) {
            $this->actingAs($worker)->get(route('cases.reports.show', [$case, $type]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Reports/CaseReport')->where('type', $type)->where('preparedBy.position', 'Social Welfare Officer II')
                ->where('notedBy.name', 'Synthetic Head')->where('office.short', 'CSWDO')
                ->has('assessment.sections', 1)->where('assessment.sections.0.key', 'professional_assessment'));
        }
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'case_report.generated')->count());
        $this->get(route('cases.reports.show', [$case, 'other']))->assertNotFound();
        $this->actingAs($cpu)->get(route('cases.reports.show', [$case, 'summary']))->assertForbidden();
        $stranger = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($stranger)->get(route('cases.reports.show', [$case, 'study']))->assertForbidden();
    }
}
