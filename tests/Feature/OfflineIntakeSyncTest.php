<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OptionSets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineIntakeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function deviceHeaders(User $worker): array
    {
        $deviceId = (string) Str::uuid();
        DB::table('offline_devices')->insert(['user_id' => $worker->id, 'device_id' => $deviceId, 'last_seen_at' => now(), 'pack_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['X-Kalinga-Device-ID' => $deviceId];
    }

    public function test_field_worker_syncs_an_offline_intake_once_with_an_idempotency_key(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $headers = $this->deviceHeaders($worker);
        $offlineId = (string) Str::uuid();
        $request = [
            'offline_id' => $offlineId,
            'resource' => 'report_intake',
            'operation' => 'create',
            'payload' => [
                'narrative' => 'Field report recorded in a GIDA location.',
                'source' => 'Barangay worker',
                'municipality' => 'Tabuk City',
                'barangay' => 'Dagupan Centro',
                'subject_first' => 'Sample',
                'subject_last' => 'Child',
                'priority' => 'high',
            ],
        ];

        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)
            ->assertOk()->assertJsonPath('status', 'synced');
        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)
            ->assertOk()->assertJsonPath('status', 'synced');

        $this->assertSame(1, DB::table('reports')->count());
        $this->assertDatabaseHas('reports', [
            'status' => 'FOR_SCREENING',
            'is_public_submission' => true,
            'endorsed_municipality' => 'Tabuk City',
            'municipality' => 'Tabuk City',
            'priority' => 'high',
        ]);
        $this->assertDatabaseHas('offline_sync_items', ['offline_id' => $offlineId, 'status' => 'synced']);
    }

    public function test_field_worker_syncs_a_general_intake_as_a_direct_case_not_a_screening_report(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $headers = $this->deviceHeaders($worker);
        $offlineId = (string) Str::uuid();
        $request = ['offline_id' => $offlineId, 'resource' => 'case_intake', 'operation' => 'create', 'payload' => [
            'first_name' => 'Field', 'last_name' => 'Client', 'birthdate' => '2014-02-16', 'sex' => 'Female',
            'municipality' => 'Tabuk City', 'barangay' => 'Dagupan Centro', 'category' => 'CEFMU', 'cefmu_classifications' => ['early_union'],
            'mode_of_admission' => 'Outreach', 'problem_presented' => 'Observed during field visit.',
            'initial_assessment' => 'Requires follow-up.', 'immediate_safety_concern' => true, 'initial_risk_level' => 'high', 'risk_indicators' => ['Physical safety concern'], 'immediate_actions' => 'Coordinated immediate safety support.', 'privacy_notice_acknowledged' => true, 'privacy_notice_method' => 'explained_verbally', 'priority' => 'high',
        ]];

        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)
            ->assertOk()->assertJsonPath('status', 'synced')->assertJsonPath('case_number', 'KLG-2026-00001');
        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)->assertOk()->assertJsonPath('status', 'synced');

        $this->assertSame(1, DB::table('cases')->count());
        $this->assertSame(0, DB::table('reports')->count());
        $this->assertDatabaseHas('cases', ['assigned_to' => $worker->id, 'status' => 'OPEN', 'category' => 'CEFMU', 'priority' => 'high']);
        $this->assertDatabaseHas('clients', ['first_name' => 'Field', 'last_name' => 'Client', 'municipality' => 'Tabuk City']);
        $this->assertDatabaseHas('offline_sync_items', ['offline_id' => $offlineId, 'status' => 'synced']);
        $this->assertDatabaseHas('notifications', ['user_id' => $worker->id, 'type' => 'case.synced', 'body' => 'An offline intake was synchronized. Open your authorized case worklist to review it.']);
    }

    public function test_a_four_step_field_intake_with_a_consent_photo_syncs_once_and_the_queue_keeps_no_photo(): void
    {
        OptionSets::ensureDefaults();
        Storage::fake(config('filesystems.default'));
        $worker = User::factory()->create(['role' => 'social_worker']);
        $headers = $this->deviceHeaders($worker);
        $image = imagecreatetruecolor(200, 150);
        ob_start();
        imagepng($image);
        $photo = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
        $request = ['offline_id' => (string) Str::uuid(), 'resource' => 'case_intake', 'operation' => 'create', 'payload' => [
            'privacy_notice_acknowledged' => true, 'privacy_notice_method' => 'explained_verbally', 'consent_photo' => $photo, 'consent_form_type' => 'consent',
            'first_name' => 'Field', 'last_name' => 'Client', 'alias' => 'Ning', 'birthdate' => '2012-02-16', 'sex' => 'Female', 'municipality' => 'Tabuk City', 'barangay' => 'Dagupan Centro',
            'cefmu_classifications' => ['early_union'], 'union_forms' => ['informal_cohabitation'], 'union_status' => 'yes_before', 'current_residence' => 'parents_house',
            'risk_indicators' => ['planned_or_impending_union'], 'initial_risk_level' => 'moderate', 'priority' => 'normal',
        ]];

        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)->assertOk()->assertJsonPath('status', 'synced');
        $this->actingAs($worker)->withHeaders($headers)->postJson(route('sync.store'), $request)->assertOk()->assertJsonPath('status', 'synced');

        $case = DB::table('cases')->first();
        $this->assertSame(1, DB::table('cases')->count());
        $this->assertSame('yes_before', $case->union_status);
        $this->assertNotNull($case->consent_document_id);
        $this->assertStringNotContainsString('base64', (string) DB::table('offline_sync_items')->value('payload'));
    }
}
