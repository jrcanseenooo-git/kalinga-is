<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Only the fresh, isolated foundation database may receive these synthetic fixtures.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.sqlite.database');
if (!$app->environment('testing') || config('database.default') !== 'sqlite'
    || !str_starts_with(realpath(dirname($database)), realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'kalinga-foundation-')
    || basename($database) !== 'synthetic.sqlite') {
    throw new RuntimeException('Synthetic foundation database required.');
}
$db = DB::class;
try {
    $cpu = $db::table('users')->where('email', 'cpu@kalinga.test')->value('id');
    $lgu = $db::table('user_lgu_assignments')->where('user_id', $cpu)->value('lgu_id');
    $circle = $db::table('lgu_coordination_circles')->where('lgu_id', $lgu)->value('id') ?? $db::table('lgu_coordination_circles')->insertGetId([
        'lgu_id' => $lgu, 'name' => 'Synthetic browser circle', 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $db::table('lgu_coordination_members')->updateOrInsert(['circle_id' => $circle, 'user_id' => $cpu], [
        'membership_role' => 'member',
        'joined_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($index = 0; $index < 55; $index++) {
        $db::table('lgu_coordination_messages')->insert([
            'circle_id' => $circle, 'author_id' => $cpu,
            'body' => $index === 0 ? 'Synthetic older history sentinel' : 'Synthetic paged message ' . $index,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Synthetic inactive provider exercises the real directory/status boundary.
    $db::table('service_providers')->insert([
        'name' => 'Synthetic inactive referral partner', 'service_type' => 'Synthetic retired service',
        'active' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Only guarded synthetic UI records: exercise genuine urgent/overdue indicators.
    $worker = $db::table('users')->where('email', 'worker@kalinga.test')->value('id');
    $workerLgu = $db::table('user_lgu_assignments')->where('user_id', $worker)->value('lgu_id');
    $uiClient = $db::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'InterfaceRecord', 'municipality' => 'City of Tabuk', 'province' => 'Kalinga', 'region' => 'Cordillera Administrative Region (CAR)', 'created_at' => now(), 'updated_at' => now()]);
    $uiCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-UI-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'IN_PROGRESS', 'stage' => 'planning', 'priority' => 'urgent', 'category' => 'CEFMU', 'opened_at' => now()->subDays(45), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Synthetic urgent safety review', now()->subDay()], ['Synthetic recorded plan review', now()->addDays(3)]] as [$title,$due]) {
        $db::table('tasks')->insert(['case_id' => $uiCase, 'assigned_to' => $worker, 'title' => $title, 'due_at' => $due, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    $emptyWorker = User::factory()->create(['name' => 'Synthetic empty workspace', 'email' => 'empty-worker@kalinga.test', 'role' => 'social_worker', 'password' => Hash::make('password')]);

    // Separate transfer fixture preserves all UI baseline records and ownership.
    $transferWorker = User::factory()->create(['name' => 'Synthetic eligible recipient', 'email' => 'transfer-worker@kalinga.test', 'role' => 'social_worker', 'active' => true, 'password' => Hash::make('password')]);
    $transferWorker->forceFill(['municipality' => $db::table('clients')->where('id', $uiClient)->value('municipality')])->save();
    $db::table('user_lgu_assignments')->insert(['user_id' => $transferWorker->id, 'lgu_id' => $workerLgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    $db::table('cases')->insert(['case_number' => 'SYN-TRANSFER-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $db::table('cases')->insert(['case_number' => 'SYN-HISTORY-REVOKE-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $editorRevocationCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-EDITOR-REVOKE-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    foreach ([5, 7, 9] as $day) {
        $db::table('tasks')->insert(['case_id' => $uiCase, 'assigned_to' => $worker, 'title' => 'Synthetic scheduled follow-up ' . $day, 'due_at' => now()->addDays($day), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ([15, 20] as $day) {
        $db::table('tasks')->insert(['case_id' => $uiCase, 'assigned_to' => $worker, 'title' => 'Synthetic task expansion ' . $day, 'due_at' => now()->addDays($day), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    // Exercise real server pagination without touching any operational database.
    for ($index = 2; $index <= 6; $index++) {
        // A fixed Philippine-midnight boundary also exercises date presentation on other devices.
        $openedAt = $index === 6 ? '2026-09-30 16:30:00' : now()->subDays(45 + $index);
        $db::table('cases')->insert(['case_number' => 'SYN-UI-2026-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => $openedAt, 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // Independent reviewer: the LGU Head (supervisor) of the worker's LGU. Since the Phase 3 roles,
    // the Field Office monitors its region and no longer reviews casework.
    $reviewerUser = User::factory()->create(['name' => 'Synthetic assessment reviewer', 'email' => 'assessment-reviewer@kalinga.test', 'role' => 'lgu_head', 'province' => 'Kalinga', 'active' => true, 'password' => Hash::make('password')]);
    $db::table('user_lgu_assignments')->insert(['user_id' => $reviewerUser->id, 'lgu_id' => $workerLgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    $assessmentCase = $db::table('cases')->where('case_number', 'SYN-UI-2026-0004')->value('id');
    $assessment = $db::table('case_assessments')->insertGetId(['case_id' => $assessmentCase, 'version' => 1, 'record_type' => 'full_revision', 'assessment_date' => today(), 'status' => 'draft', 'authored_by' => $worker, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);
    $db::table('case_assessment_sections')->insert(['case_assessment_id' => $assessment, 'section_key' => 'presenting_situation', 'completion_state' => 'completed', 'source_type' => 'worker_observation', 'facts' => 'Synthetic verified assessment facts for responsive QA.', 'observations' => 'Synthetic source-attributed observation.', 'analysis' => 'Synthetic professional analysis.', 'restricted' => false, 'created_at' => now(), 'updated_at' => now()]);
    $participationCase = $db::table('cases')->where('case_number', 'SYN-UI-2026-0005')->value('id');
    $db::table('child_participation_records')->insert(['case_id' => $participationCase, 'lineage_key' => (string) Str::uuid(), 'version' => 1, 'record_type' => 'formal_record', 'occasion_type' => 'education', 'decision_title' => 'Synthetic education participation occasion', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'status' => 'draft', 'authored_by' => $worker, 'is_current' => false, 'developmental_considerations' => 'Synthetic participation context for QA.', 'preferred_language' => 'English', 'child_views' => 'Synthetic paraphrased child view.', 'worker_observation' => 'Synthetic worker observation kept separate.', 'created_at' => now(), 'updated_at' => now()]);
    // Finalized synthetic source for correction/addendum UI tests. Actual manual
    // author/reviewer finalization is verified independently through the real UI.
    $syntheticReviewer = $db::table('users')->where('email', 'assessment-reviewer@kalinga.test')->value('id');
    $db::table('child_participation_records')->insert(['case_id' => $participationCase, 'lineage_key' => (string) Str::uuid(), 'version' => 1, 'record_type' => 'formal_record', 'occasion_type' => 'education', 'decision_title' => 'Synthetic finalized participation source', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'status' => 'final', 'authored_by' => $worker, 'reviewed_by' => $syntheticReviewer, 'reviewed_at' => now(), 'finalized_by' => $syntheticReviewer, 'finalized_at' => now(), 'is_current' => true, 'child_views' => 'Synthetic finalized source child view.', 'worker_observation' => 'Synthetic finalized source observation.', 'created_at' => now(), 'updated_at' => now()]);
    $db::table('child_participation_records')->insert(['case_id' => $editorRevocationCase, 'lineage_key' => (string) Str::uuid(), 'version' => 1, 'record_type' => 'formal_record', 'occasion_type' => 'education', 'decision_title' => 'Synthetic editor revocation record', 'participation_date' => today()->toDateString(), 'participation_status' => 'participated', 'status' => 'draft', 'authored_by' => $worker, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);

    // Dedicated service-outcome fixture, isolated from review/transfer UI baselines.
    $serviceCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-SERVICE-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'planning', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $servicePlan = $db::table('case_plans')->insertGetId(['case_id' => $serviceCase, 'version' => 1, 'plan_date' => today(), 'summary' => 'Synthetic service continuity objective.', 'overall_objective' => 'Synthetic service continuity objective.', 'status' => 'active', 'is_current' => true, 'authored_by' => $worker, 'reviewed_by' => $syntheticReviewer, 'reviewed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $serviceGoal = $db::table('case_plan_goals')->insertGetId(['case_plan_id' => $servicePlan, 'domain' => 'education', 'need_problem' => 'Synthetic support need.', 'goal_statement' => 'Synthetic service continuity goal.', 'priority' => 'normal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $serviceTask = $db::table('tasks')->insertGetId(['case_id' => $serviceCase, 'assigned_to' => $worker, 'title' => 'Synthetic service execution task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $serviceProvider = $db::table('service_providers')->insertGetId(['name' => 'Synthetic service partner', 'service_type' => 'Education', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['linked', 'unlinked'] as $kind) {
        $db::table('interventions')->insert(['case_plan_id' => $servicePlan, 'case_plan_goal_id' => $serviceGoal, 'need' => 'Synthetic support need.', 'goal' => 'Synthetic service continuity goal.', 'activity' => 'Synthetic ' . $kind . ' education activity', 'service_category' => 'Education', 'responsible_office' => 'Synthetic service office', 'progress' => 'not_started', 'priority' => 'normal', 'service_provider_id' => $serviceProvider, 'target_date' => $kind === 'linked' ? today()->subDays(2) : today()->addDay(), 'operational_record_type' => $kind === 'linked' ? 'task' : null, 'operational_record_id' => $kind === 'linked' ? $serviceTask : null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $serviceDraft = $db::table('case_plans')->insertGetId(['case_id' => $serviceCase, 'previous_plan_id' => $servicePlan, 'version' => 2, 'plan_date' => today(), 'summary' => 'Synthetic separate draft cycle.', 'overall_objective' => 'Synthetic separate draft cycle.', 'status' => 'draft', 'is_current' => false, 'authored_by' => $worker, 'created_at' => now(), 'updated_at' => now()]);
    $serviceDraftGoal = $db::table('case_plan_goals')->insertGetId(['case_plan_id' => $serviceDraft, 'domain' => 'education', 'need_problem' => 'Synthetic draft need.', 'goal_statement' => 'Synthetic draft service goal.', 'priority' => 'normal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $db::table('interventions')->insert(['case_plan_id' => $serviceDraft, 'case_plan_goal_id' => $serviceDraftGoal, 'need' => 'Synthetic draft need.', 'goal' => 'Synthetic draft service goal.', 'activity' => 'Synthetic draft service activity', 'service_category' => 'Education', 'progress' => 'not_started', 'priority' => 'normal', 'created_at' => now(), 'updated_at' => now()]);
    $db::table('interventions')->insert(['case_plan_id' => $servicePlan, 'need' => 'Synthetic historical support need.', 'goal' => 'Synthetic historical service goal.', 'service_category' => 'Education', 'progress' => 'not_started', 'priority' => 'normal', 'created_at' => now(), 'updated_at' => now()]);

    $monitorCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-MONITOR-2026-0001', 'client_id' => $uiClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $db::table('monitoring_visits')->insert(['case_id' => $monitorCase, 'visited_at' => today()->subDay(), 'observations' => 'Synthetic earlier visit observation.', 'location' => 'Synthetic operational meeting point', 'risks' => 'Synthetic recorded risk requiring review.', 'next_steps' => 'Synthetic recorded next professional step.', 'created_by' => $worker, 'created_at' => now(), 'updated_at' => now()]);
    $contextClient = $db::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Context', 'province' => 'Kalinga', 'municipality' => 'Tabuk City', 'birthdate' => '2012-02-03', 'sex' => 'Female', 'safe_contact_method' => 'Agreed method', 'safe_contact_notes' => 'Synthetic safe contact guidance.', 'primary_caregiver_name' => 'Synthetic trusted caregiver', 'primary_caregiver_relationship' => 'Aunt', 'primary_caregiver_contact' => 'Synthetic agreed channel', 'created_at' => now(), 'updated_at' => now()]);
    $contextCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-CONTEXT-2026-0001', 'client_id' => $contextClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'category' => 'CEFMU', 'cefmu_classifications' => '["early_union"]', 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    // Isolated conference review and denied-read cases for actual UI workflows.
    foreach (['SYN-CONFERENCE-REVIEW', 'SYN-CONFERENCE-DENIAL'] as $number) {
        $newConferenceCase = $db::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $contextClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'category' => 'CEFMU', 'status' => 'OPEN', 'stage' => 'casework', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $db::table('tasks')->insert(['case_id' => $newConferenceCase, 'assigned_to' => $worker, 'title' => 'Synthetic conference execution task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    $db::table('cases')->insert(['case_number' => 'SYN-CLOSURE-INTEGRITY', 'client_id' => $contextClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'category' => 'CEFMU', 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    // Dedicated synthetic closure interface cases; guarded test database only.
    foreach (['WORKFLOW', 'VALIDATION', 'STALE', 'LOSTACK', 'READBACK', 'DENIAL', 'VISUAL'] as $kind) {
        $closureUiCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-CLOSURE-UI-' . $kind, 'client_id' => $contextClient, 'lgu_id' => $workerLgu, 'assigned_to' => $worker, 'category' => 'CEFMU', 'status' => 'FOR_CLOSURE_REVIEW', 'stage' => 'for closure review', 'priority' => 'normal', 'offline_version' => 1, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        if ($kind === 'VISUAL') {
            foreach ([1, 2] as $version) {
                $db::table('case_closure_records')->insert(['case_id' => $closureUiCase, 'version' => $version, 'current_slot' => $version === 2 ? 1 : null, 'summary' => $version === 2 ? 'Synthetic current closure outcomes, continuing support and remaining concerns.' : 'Synthetic earlier retained closure recommendation.', 'source' => 'authored_record', 'recorded_by' => $worker, 'captured_by' => $worker, 'recorded_at' => now()->subDays(3 - $version), 'superseded_at' => $version === 1 ? now()->subDay() : null, 'created_at' => now()->subDays(3 - $version), 'updated_at' => now()]);
            }
            $db::table('cases')->where('id', $closureUiCase)->update(['closure_summary' => 'Synthetic current closure outcomes, continuing support and remaining concerns.']);
            $db::table('audit_logs')->insert(['user_id' => $worker, 'event' => 'case.closure_recorded', 'auditable_type' => 'case', 'auditable_id' => $closureUiCase, 'created_at' => now(), 'updated_at' => now()]);
            $db::table('case_aftercare_plans')->insert(['case_id' => $closureUiCase, 'required' => true, 'status' => 'planned', 'responsible_office' => 'Synthetic continuing support office', 'initial_follow_up_at' => today()->addDays(7), 'created_by' => $worker, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    $secondLgu = $db::table('lgu_units')->insertGetId(['psgc_code' => 'SYN-BROWSER-SECOND', 'name' => 'Synthetic second LGU', 'province' => 'Kalinga', 'region' => 'CAR', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $db::table('user_lgu_assignments')->insert(['user_id' => $cpu, 'lgu_id' => $secondLgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    $secondCircle = $db::table('lgu_coordination_circles')->insertGetId(['lgu_id' => $secondLgu, 'name' => 'Synthetic second coordination circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $db::table('lgu_coordination_members')->insert(['circle_id' => $secondCircle, 'user_id' => $cpu, 'membership_role' => 'member', 'joined_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    $db::table('lgu_coordination_messages')->insert(['circle_id' => $secondCircle, 'author_id' => $cpu, 'body' => 'Synthetic second-circle operational message.', 'created_at' => now(), 'updated_at' => now()]);

    $cpuClient = $db::table('clients')->insertGetId(['first_name' => 'CPU protected identity sentinel', 'last_name' => 'Synthetic child', 'created_at' => now(), 'updated_at' => now()]);
    $cpuCase = $db::table('cases')->insertGetId(['case_number' => 'SYN-CPU-PRESENTATION', 'client_id' => $cpuClient, 'lgu_id' => $lgu, 'assigned_to' => $worker, 'status' => 'FOR_FOLLOW_UP', 'stage' => 'monitoring', 'priority' => 'high', 'category' => 'Synthetic support coordination', 'opened_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now()]);
    $cpuGrants = [];
    foreach (['Review the requested health coordination and provide recommendations.', 'Separate education coordination assignment.'] as $index => $referralPurpose) {
        $cpuGrants[] = $db::table('mdt_case_access')->insertGetId(['case_id' => $cpuCase, 'user_id' => $cpu, 'professional_role' => 'CPU counterpart', 'organization' => 'Synthetic Tabuk CPU', 'purpose' => $referralPurpose, 'remarks' => $index === 0 ? 'Coordinate the available appointment and document the professional recommendation.' : 'Keep this separate from the health assignment.', 'allowed_categories' => json_encode($index === 0 ? ['case_summary', 'case_plan', 'referral_service'] : ['case_stage']), 'status' => $index === 0 ? 'action_required' : 'assigned', 'assigned_by' => $worker, 'assigned_at' => now()->subDays(2 - $index), 'access_starts_at' => now()->subDays(2), 'access_expires_at' => now()->addDays(14), 'expire_on_completion' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    $db::table('mdt_actions')->insert(['case_id' => $cpuCase, 'mdt_access_id' => $cpuGrants[0], 'title' => 'Confirm the health coordination schedule', 'instructions' => 'Contact the referring case manager about an available schedule. Record findings and a recommendation before marking this action complete.', 'status' => 'action_required', 'due_at' => now()->addDays(3), 'created_by' => $worker, 'created_at' => now(), 'updated_at' => now()]);
    $db::table('case_plans')->insert(['case_id' => $cpuCase, 'plan_date' => today(), 'summary' => 'Synthetic authorized support plan summary.', 'status' => 'draft', 'version' => 1, 'authored_by' => $worker, 'created_at' => now(), 'updated_at' => now()]);

    echo 'KALINGA_SYNTHETIC_FIXTURES_READY' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Synthetic fixture preparation failed.' . PHP_EOL);
    exit(1);
}
