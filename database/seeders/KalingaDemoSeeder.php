<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Creates fictional presentation data only; no person in this seeder is a real client. */
class KalingaDemoSeeder extends Seeder
{
    private const CLASSIFICATIONS = ['early_union', 'early_marriage', 'forced_union', 'forced_marriage'];

    public function run(): void
    {
        $now = now();
        $users = DB::table('users')->pluck('id', 'email');
        $worker = $users['worker@kalinga.test'];
        // The supervisor is the LGU Head: monitors the LGU's cases and holds none.
        $supervisor = $users['supervisor@kalinga.test'];
        $admin = $users['admin@kalinga.test'];
        $cpu = $users['cpu@kalinga.test'];

        // Demo casework must exercise the same normalized jurisdiction model
        // as production. Free-text municipality fields alone intentionally do
        // not grant access.
        $demoMunicipalities = ['City of Tabuk', 'Rizal', 'Pinukpuk', 'Tinglayan', 'Pasil', 'Balbalan', 'Lubuagan', 'Tanudan'];
        $lguIds = [];
        foreach ($demoMunicipalities as $index => $municipality) {
            DB::table('lgu_units')->updateOrInsert(['psgc_code' => 'DEMO-LGU-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)], [
                'name' => $municipality, 'province' => 'Kalinga', 'region' => 'Cordillera Administrative Region (CAR)',
                'active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $lguIds[$municipality] = DB::table('lgu_units')->where('psgc_code', 'DEMO-LGU-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT))->value('id');
            // Demo accounts belong to Tabuk. Other LGUs remain reference/demo
            // case data, not additional workplace assignments for these users.
            foreach ($municipality === 'City of Tabuk' ? [$worker, $supervisor] : [] as $caseworker) {
                DB::table('user_lgu_assignments')->updateOrInsert(['user_id' => $caseworker, 'lgu_id' => $lguIds[$municipality]], [
                    'effective_from' => $now->copy()->subYear(), 'effective_to' => null, 'active' => true,
                    'assigned_by' => $admin, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        DB::table('user_lgu_assignments')->updateOrInsert(['user_id' => $cpu, 'lgu_id' => $lguIds['City of Tabuk']], [
            'effective_from' => $now->copy()->subYear(), 'effective_to' => null, 'active' => true,
            'assigned_by' => $admin, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $demoUsers = [$worker, $supervisor, $cpu];
        (new KalingaConnectDemoScopeSeeder)->setContainer(app())->forUsers($demoUsers)->run();
        (new KalingaConnectDemoSeeder)->setContainer(app())->forUsers($demoUsers)->run();

        $records = [
            ['first' => 'Demo', 'last' => 'Aglibut', 'sex' => 'Female', 'birth' => '2012-04-18', 'municipality' => 'City of Tabuk', 'barangay' => 'Bulanao Centro', 'status' => 'IN_PROGRESS', 'priority' => 'high', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Balay', 'sex' => 'Male', 'birth' => '2014-08-09', 'municipality' => 'City of Tabuk', 'barangay' => 'Dagupan Centro', 'status' => 'FOR_FOLLOW_UP', 'priority' => 'normal', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Chawis', 'sex' => 'Female', 'birth' => '2010-01-27', 'municipality' => 'Rizal', 'barangay' => 'Rizal Centro', 'status' => 'AWAITING_REFERRAL', 'priority' => 'high', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Dumlao', 'sex' => 'Male', 'birth' => '2015-12-03', 'municipality' => 'Pinukpuk', 'barangay' => 'Apatan', 'status' => 'OPEN', 'priority' => 'normal', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Eway', 'sex' => 'Female', 'birth' => '2011-06-21', 'municipality' => 'Tinglayan', 'barangay' => 'Buscalan', 'status' => 'IN_PROGRESS', 'priority' => 'urgent', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Fagto', 'sex' => 'Male', 'birth' => '2013-02-14', 'municipality' => 'Pasil', 'barangay' => 'Ableg', 'status' => 'FOR_CLOSURE_REVIEW', 'priority' => 'normal', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Gaddang', 'sex' => 'Female', 'birth' => '2009-09-12', 'municipality' => 'Balbalan', 'barangay' => 'Dao-angan', 'status' => 'CLOSED', 'priority' => 'low', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Hucab', 'sex' => 'Male', 'birth' => '2016-03-30', 'municipality' => 'Lubuagan', 'barangay' => 'Mabilong', 'status' => 'OPEN', 'priority' => 'high', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Insum', 'sex' => 'Female', 'birth' => '2012-11-05', 'municipality' => 'Tanudan', 'barangay' => 'Lower Lubo', 'status' => 'IN_PROGRESS', 'priority' => 'normal', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Jocson', 'sex' => 'Male', 'birth' => '2010-05-19', 'municipality' => 'City of Tabuk', 'barangay' => 'Laya East', 'status' => 'FOR_FOLLOW_UP', 'priority' => 'normal', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Kiat', 'sex' => 'Female', 'birth' => '2014-07-25', 'municipality' => 'Rizal', 'barangay' => 'Liwan East', 'status' => 'AWAITING_REFERRAL', 'priority' => 'high', 'worker' => $worker],
            ['first' => 'Demo', 'last' => 'Lacwasan', 'sex' => 'Male', 'birth' => '2011-10-08', 'municipality' => 'Pinukpuk', 'barangay' => 'Limos', 'status' => 'OPEN', 'priority' => 'low', 'worker' => $worker],
        ];

        // Demo cases use the four CEFMU classifications (the older demo categories were not CEFMU classifications).
        foreach ($records as $index => $record) {
            $number = sprintf('DEMO-KLG-2026-%04d', $index + 1);
            $phone = sprintf('DEMO-CLIENT-%04d', $index + 1);
            DB::table('clients')->updateOrInsert(['phone' => $phone], ['first_name' => $record['first'], 'last_name' => $record['last'], 'birthdate' => $record['birth'], 'sex' => $record['sex'], 'region' => 'Cordillera Administrative Region (CAR)', 'province' => 'Kalinga', 'municipality' => $record['municipality'], 'barangay' => $record['barangay'], 'identifiers' => json_encode(['demo_only' => true]), 'created_at' => $now->copy()->subDays(30 - $index), 'updated_at' => $now]);
            $clientId = DB::table('clients')->where('phone', $phone)->value('id');
            DB::table('cases')->updateOrInsert(['case_number' => $number], ['client_id' => $clientId, 'lgu_id' => $lguIds[$record['municipality']], 'assigned_to' => $record['worker'], 'status' => $record['status'], 'stage' => strtolower(str_replace('_', ' ', $record['status'])), 'priority' => $record['priority'], 'cefmu_classifications' => json_encode([self::CLASSIFICATIONS[$index % 4]]), 'opened_at' => $now->copy()->subDays(30 - $index), 'last_activity_at' => $now->copy()->subDays($index % 5), 'closure_summary' => $record['status'] === 'CLOSED' ? 'Fictional demo case closed after documented services and follow-up.' : null, 'closed_at' => $record['status'] === 'CLOSED' ? $now->copy()->subDays(3) : null, 'retention_review_at' => $record['status'] === 'CLOSED' ? $now->copy()->addYear() : null, 'offline_version' => 1, 'created_at' => $now->copy()->subDays(30 - $index), 'updated_at' => $now]);
            $caseId = DB::table('cases')->where('case_number', $number)->value('id');
            DB::table('case_status_history')->updateOrInsert(['case_id' => $caseId, 'to_status' => $record['status']], ['from_status' => null, 'changed_by' => $record['worker'], 'created_at' => $now->copy()->subDays(30 - $index), 'updated_at' => $now]);
            DB::table('case_notes')->updateOrInsert(['case_id' => $caseId, 'body' => 'Fictional presentation note: initial contact and needs review recorded for the demo workflow.'], ['visibility' => 'CASE_NOTES', 'created_by' => $record['worker'], 'created_at' => $now->copy()->subDays($index % 4), 'updated_at' => $now]);
            DB::table('audit_logs')->updateOrInsert(['event' => 'demo.case_seeded', 'auditable_type' => 'case', 'auditable_id' => $caseId], ['user_id' => $admin, 'changes' => json_encode(['fictional_demo' => true]), 'outcome' => 'success', 'created_at' => $now, 'updated_at' => $now]);
        }

        $firstCase = DB::table('cases')->where('case_number', 'DEMO-KLG-2026-0001')->value('id');
        $thirdCase = DB::table('cases')->where('case_number', 'DEMO-KLG-2026-0003')->value('id');
        DB::table('assessments')->updateOrInsert(['case_id' => $firstCase, 'version' => 1], ['status' => 'completed', 'summary' => 'Fictional demonstration assessment: household needs, school participation, safety factors, and protective supports have been reviewed.', 'responses' => json_encode(['education' => 'Needs follow-up', 'health' => 'Referral recommended', 'safety' => 'No immediate emergency recorded']), 'completed_by' => $worker, 'completed_at' => $now->copy()->subDays(8), 'created_at' => $now->copy()->subDays(9), 'updated_at' => $now]);
        DB::table('case_plans')->updateOrInsert(['case_id' => $firstCase, 'summary' => 'Fictional demonstration plan: coordinate health referral, school support, and a follow-up household visit.'], ['review_due_at' => $now->copy()->addDays(21), 'status' => 'active', 'created_at' => $now->copy()->subDays(7), 'updated_at' => $now]);
        $planId = DB::table('case_plans')->where('case_id', $firstCase)->value('id');
        DB::table('interventions')->updateOrInsert(['case_plan_id' => $planId, 'need' => 'Health access'], ['goal' => 'Complete initial health consultation', 'service_category' => 'Health services', 'target_date' => $now->copy()->addDays(10), 'progress' => 'in_progress', 'outcome' => null, 'created_at' => $now->copy()->subDays(7), 'updated_at' => $now]);
        DB::table('monitoring_visits')->updateOrInsert(['case_id' => $firstCase, 'visited_at' => $now->toDateString()], ['location' => 'Bulanao Centro, City of Tabuk', 'observations' => 'Fictional demo monitoring visit: household was available and follow-up actions were reviewed.', 'risks' => 'Continue routine follow-up.', 'next_steps' => 'Confirm provider appointment and review progress next month.', 'created_by' => $worker, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('relationships')->updateOrInsert(['client_id' => DB::table('cases')->where('id', $firstCase)->value('client_id'), 'name' => 'Demo Guardian Aglibut'], ['relationship_type' => 'Parent / guardian', 'phone' => 'DEMO-GUARDIAN-0001', 'is_guardian' => true, 'created_at' => $now->copy()->subDays(9), 'updated_at' => $now]);
        DB::table('consents')->updateOrInsert(['case_id' => $firstCase, 'lawful_basis' => 'Informed consent (fictional demo)'], ['purposes' => json_encode(['case management', 'approved service referral']), 'sharing_allowed' => true, 'granted_at' => $now->copy()->subDays(9), 'recorded_by' => $worker, 'created_at' => $now->copy()->subDays(9), 'updated_at' => $now]);
        DB::table('referrals')->updateOrInsert(['case_id' => $firstCase, 'service_category' => 'Health services'], ['referred_to' => 'DEMO Kalinga Provincial Health Office', 'status' => 'SENT', 'reason' => 'Fictional demo referral for initial consultation.', 'referred_at' => $now->copy()->subDays(2), 'accepted_at' => null, 'completed_at' => null, 'created_by' => $worker, 'created_at' => $now->copy()->subDays(3), 'updated_at' => $now]);
        DB::table('referrals')->updateOrInsert(['case_id' => $thirdCase, 'service_category' => 'Protection and psychosocial support'], ['referred_to' => 'DEMO Kalinga Child Protection Network', 'status' => 'PENDING', 'reason' => 'Fictional demo referral awaiting provider confirmation.', 'referred_at' => null, 'accepted_at' => null, 'completed_at' => null, 'created_by' => $worker, 'created_at' => $now->copy()->subDay(), 'updated_at' => $now]);
        // Services provided (the records the analytics count), so the demo charts show the service mix.
        foreach ([[$firstCase, 'Medical', 'Initial health consultation', null], [$firstCase, 'Transportation', 'Fare to the provincial health office', 300], [$thirdCase, 'Psychosocial', 'Counseling session', null]] as [$serviceCase, $type, $detail, $amount]) {
            DB::table('services_provided')->updateOrInsert(['case_id' => $serviceCase, 'service_type' => $type], ['specific_detail' => $detail, 'amount' => $amount, 'date_provided' => $now->copy()->subDays(3)->toDateString(),
                'provided_by' => 'DEMO partner office', 'recorded_by' => $worker, 'created_at' => $now, 'updated_at' => $now]);
        }
        DB::table('tasks')->updateOrInsert(['title' => 'DEMO: Confirm health referral appointment'], ['case_id' => $firstCase, 'assigned_to' => $worker, 'due_at' => $now->copy()->addDays(2), 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tasks')->updateOrInsert(['title' => 'DEMO: Review follow-up case list'], ['case_id' => DB::table('cases')->where('case_number', 'DEMO-KLG-2026-0002')->value('id'), 'assigned_to' => $worker, 'due_at' => $now->copy()->addDay(), 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('notifications')->updateOrInsert(['user_id' => $worker, 'title' => 'Demo data ready'], ['type' => 'demo_seed', 'body' => 'Fictional presentation cases, tasks, referrals, and planning records are ready to review.', 'action_url' => '/cases', 'read_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('mdt_case_access')->updateOrInsert(['case_id' => $firstCase, 'user_id' => $cpu], [
            'professional_role' => 'CPU social-work counterpart', 'organization' => 'DEMO Tabuk CPU',
            'purpose' => 'Provide time-limited multidisciplinary input for the fictional demonstration case.',
            'allowed_categories' => json_encode(['case_summary', 'case_stage', 'case_plan', 'referral_service']),
            'status' => 'action_required', 'assigned_by' => $worker, 'assigned_at' => $now->copy()->subDay(),
            'access_starts_at' => $now->copy()->subDay(), 'access_expires_at' => $now->copy()->addDays(30),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
