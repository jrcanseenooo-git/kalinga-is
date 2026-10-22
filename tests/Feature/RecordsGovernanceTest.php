<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $user, string $municipality = 'Tabuk'): int
    {
        $client = DB::table('clients')->insertGetId(['first_name' => 'Records', 'last_name' => 'Child', 'municipality' => $municipality, 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'REC-' . uniqid(), 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function document(User $user, int $case, string $name = 'record.pdf'): int
    {
        Storage::fake('local');
        $this->actingAs($user)->post(route('cases.documents.upload', $case), ['document' => UploadedFile::fake()->create($name, 10, 'application/pdf')])->assertRedirect();

        return (int) DB::table('documents')->latest('id')->value('id');
    }

    public function test_new_upload_is_quarantined_and_has_explicit_not_filename_inferred_lineage(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $first = $this->document($worker, $case, 'same.pdf');
        $second = $this->document($worker, $case, 'same.pdf');
        $a = DB::table('documents')->find($first);
        $b = DB::table('documents')->find($second);
        $this->assertNotSame($a->lineage_id, $b->lineage_id);
        $this->assertNull($b->previous_document_id);
        $this->assertSame(1, (int) $b->version);
        $this->assertDatabaseHas('record_governance', ['record_type' => 'document', 'record_id' => $first, 'scan_state' => 'pending_scan', 'legacy_record' => false]);
        $this->actingAs($worker)->get(route('cases.documents.download', [$case, $first]))->assertStatus(423);
    }

    public function test_explicit_version_preserves_prior_document_and_does_not_delete_it(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $first = $this->document($worker, $case, 'first.pdf');
        $this->actingAs($worker)->post(route('cases.documents.upload', $case), ['document' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf'), 'previous_document_id' => $first])->assertRedirect();
        $next = DB::table('documents')->latest('id')->first();
        $prior = DB::table('documents')->find($first);
        $this->assertSame($first, (int) $next->previous_document_id);
        $this->assertSame($prior->lineage_id, $next->lineage_id);
        $this->assertSame(2, (int) $next->version);
        $this->assertNull($prior->deleted_at);
    }

    public function test_legacy_document_is_governed_but_remains_legacy_unverified_and_available(): void
    {
        Storage::fake('local');
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        Storage::disk('local')->put('cases/' . $case . '/old.pdf', 'legacy');
        $id = DB::table('documents')->insertGetId(['case_id' => $case, 'name' => 'old.pdf', 'disk' => 'local', 'path' => 'cases/' . $case . '/old.pdf', 'mime_type' => 'application/pdf', 'version' => 1, 'uploaded_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($worker)->get(route('cases.documents.download', [$case, $id]))->assertOk();
        $this->assertDatabaseHas('record_governance', ['record_id' => $id, 'scan_state' => 'legacy_unverified', 'legacy_record' => true]);
    }

    public function test_restricted_metadata_is_redacted_and_direct_download_is_denied_without_permission(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case, 'PRIVATE-NAME-NEVER-LEAKS.pdf');
        $classification = DB::table('record_classifications')->where('code', 'health_srh')->value('id');
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $this->actingAs($supervisor)->patch(route('cases.documents.governance.update', [$case, $doc]), ['record_classification_id' => $classification])->assertRedirect();
        // Mock a user who has cases.view but NOT records.restricted_view to verify redaction
        app()->instance(RolePermissions::class, new class extends RolePermissions
        {
            public function allows(User $user, string $permission): bool
            {
                if ($permission === 'records.restricted_view') {
                    return false;
                }

                return parent::allows($user, $permission);
            }
        });
        $reader = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $page = $this->actingAs($reader)->get(route('cases.show', $case))->assertOk();
        $page->assertDontSee('PRIVATE-NAME-NEVER-LEAKS.pdf')->assertSee('Restricted document');
        $this->actingAs($reader)->get(route('cases.documents.download', [$case, $doc]))->assertNotFound();
    }

    public function test_unsupported_record_type_is_rejected_and_case_scope_is_enforced(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $outside = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Other']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $this->actingAs($supervisor)->post(route('cases.records.holds.store', $case), ['scope' => 'record', 'reason_code' => 'litigation', 'authority' => 'Court', 'record_type' => 'arbitrary_php_class', 'record_id' => $doc])->assertStatus(422);
        $this->actingAs($outside)->post(route('cases.records.holds.store', $case), ['scope' => 'case', 'reason_code' => 'litigation', 'authority' => 'Court'])->assertForbidden();
    }

    public function test_case_hold_blocks_disposition_and_release_is_authorized_and_audited(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $governance = DB::table('record_governance')->where('record_id', $doc)->value('id');
        $hold = $this->actingAs($supervisor)->post(route('cases.records.holds.store', $case), ['scope' => 'case', 'reason_code' => 'litigation', 'authority' => 'Court order'])->assertRedirect();
        $this->actingAs($supervisor)->post(route('cases.records.dispositions.store', [$case, $governance]), ['proposal_reason' => 'Review'])->assertStatus(422);
        $holdId = DB::table('legal_holds')->latest('id')->value('id');
        $this->actingAs($supervisor)->patch(route('cases.records.holds.release', [$case, $holdId]), ['release_reason' => 'Authority released hold'])->assertRedirect();
        $this->assertDatabaseHas('legal_holds', ['id' => $holdId, 'released_by' => $supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'record_hold.released']);
    }

    public function test_disposition_is_proposal_only_and_disposed_or_physical_deletion_is_unreachable(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $governance = DB::table('record_governance')->where('record_id', $doc)->value('id');
        $this->actingAs($supervisor)->post(route('cases.records.dispositions.store', [$case, $governance]), ['proposal_reason' => 'Human review requested'])->assertRedirect();
        $this->assertDatabaseHas('record_dispositions', ['record_governance_id' => $governance, 'status' => 'proposed']);
        $this->assertDatabaseHas('record_governance', ['id' => $governance, 'lifecycle_state' => 'disposition_pending']);
        $this->assertNull(DB::table('documents')->find($doc)->deleted_at);
        $this->actingAs($supervisor)->patch('/cases/' . $case . '/record-governance/' . $governance . '/dispositions', ['status' => 'disposed'])->assertStatus(405);
        $this->assertDatabaseMissing('record_dispositions', ['record_governance_id' => $governance, 'status' => 'disposed']);
    }

    public function test_disposition_review_and_approval_require_a_different_authorized_user_and_never_execute(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $proposer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $reviewer = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $governance = DB::table('record_governance')->where('record_id', $doc)->value('id');
        $this->actingAs($proposer)->post(route('cases.records.dispositions.store', [$case, $governance]), ['proposal_reason' => 'Review'])->assertRedirect();
        $this->actingAs($proposer)->patch(route('cases.records.dispositions.review', [$case, $governance]), ['action' => 'approved'])->assertForbidden();
        $this->actingAs($reviewer)->patch(route('cases.records.dispositions.review', [$case, $governance]), ['action' => 'reviewed'])->assertRedirect();
        $this->actingAs($reviewer)->patch(route('cases.records.dispositions.review', [$case, $governance]), ['action' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('record_dispositions', ['record_governance_id' => $governance, 'status' => 'approved', 'approved_by' => $reviewer->id]);
        $this->assertDatabaseHas('record_governance', ['id' => $governance, 'lifecycle_state' => 'approved_for_disposition']);
        $this->assertNull(DB::table('record_dispositions')->where('record_governance_id', $governance)->value('executed_at'));
    }

    public function test_disclosure_preserves_exact_document_reference_without_copied_narrative(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $this->actingAs($supervisor)->post(route('cases.records.disclosures.store', $case), ['recipient_organization' => 'Service provider', 'purpose' => 'Authorized service referral', 'lawful_authority' => 'consent', 'delivery_method' => 'secure transfer', 'items' => [['record_type' => 'document', 'record_id' => $doc, 'category' => 'selected_document']]])->assertRedirect();
        $this->assertDatabaseHas('record_disclosure_items', ['record_type' => 'document', 'record_id' => $doc, 'category' => 'selected_document']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'record.disclosed']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'PRIVATE-NARRATIVE']);
    }

    public function test_retention_rule_change_does_not_silently_change_existing_lifecycle_or_authority(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $doc = $this->document($worker, $case);
        $rule = DB::table('retention_rules')->insertGetId(['code' => 'draft-rule', 'label' => 'Draft', 'record_type' => 'document', 'review_after_days' => 30, 'policy_validated' => false, 'created_at' => now(), 'updated_at' => now()]);
        $governance = DB::table('record_governance')->where('record_id', $doc)->first();
        DB::table('record_governance')->where('id', $governance->id)->update(['retention_rule_id' => $rule, 'retention_review_at' => now()->addDays(30)]);
        DB::table('retention_rules')->where('id', $rule)->update(['review_after_days' => 1]);
        $unchanged = DB::table('record_governance')->find($governance->id);
        $this->assertSame('active', $unchanged->lifecycle_state);
        $this->assertNotNull($unchanged->retention_review_at);
        $this->assertNull(DB::table('record_dispositions')->where('record_governance_id', $governance->id)->value('id'));
    }

    public function test_restricted_document_name_never_leaks_to_search_analytics_dashboard_notifications_or_audit_payloads(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $supervisor = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $case = $this->caseFor($worker);
        $secret = 'RECORDS-SECRET-NARRATIVE-NAME';
        $doc = $this->document($worker, $case, $secret . '.pdf');
        $classification = DB::table('record_classifications')->where('code', 'health_srh')->value('id');
        $this->actingAs($supervisor)->patch(route('cases.documents.governance.update', [$case, $doc]), ['record_classification_id' => $classification])->assertRedirect();
        $this->actingAs($worker)->getJson(route('search', ['q' => 'RECORDS']))->assertOk()->assertDontSee($secret);
        $this->actingAs($worker)->get(route('dashboard'))->assertOk()->assertDontSee($secret);
        $this->actingAs($worker)->get(route('analytics.index'))->assertOk()->assertDontSee($secret);
        $this->actingAs($worker)->get(route('notifications.index'))->assertOk()->assertDontSee($secret);
        $audit = DB::table('audit_logs')->where('event', 'record.classified')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($secret, (string) $audit->changes);
        $this->assertStringNotContainsString('.pdf', (string) $audit->changes);
        // Mock a user without records.restricted_view to verify redaction on case overview
        app()->instance(RolePermissions::class, new class extends RolePermissions
        {
            public function allows(User $user, string $permission): bool
            {
                if ($permission === 'records.restricted_view') {
                    return false;
                }

                return parent::allows($user, $permission);
            }
        });
        $reader = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Tabuk']);
        $this->actingAs($reader)->get(route('cases.show', $case))->assertOk()->assertDontSee($secret)->assertSee('Restricted document');
    }
}
