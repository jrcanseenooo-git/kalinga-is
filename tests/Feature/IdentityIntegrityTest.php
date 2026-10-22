<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IdentityIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'social_worker', string $municipality = 'Tabuk City'): User
    {
        return User::factory()->create(['role' => $role, 'municipality' => $municipality, 'province' => 'Kalinga']);
    }

    private function client(array $data = []): int
    {
        return DB::table('clients')->insertGetId($data + ['first_name' => 'Ana', 'last_name' => 'Cruz', 'birthdate' => '2012-01-01', 'sex' => 'Female', 'municipality' => 'Tabuk City', 'province' => 'Kalinga', 'barangay' => 'Bulanao', 'identity_status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function caseFor(int $client, User $user, string $number = 'KLG-ID-1'): int
    {
        return DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $client, 'assigned_to' => $user->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function candidate(int $a, int $b, string $context = 'same_scope'): int
    {
        return DB::table('identity_duplicate_candidates')->insertGetId(['client_a_id' => $a, 'client_b_id' => $b, 'candidate_key' => min($a, $b) . ':' . max($a, $b), 'status' => 'potential_duplicate', 'matching_signals' => json_encode(['matches' => ['exact_normalized_name', 'exact_dob'], 'conflicts' => []]), 'jurisdiction_context' => $context, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function identifier(int $client, string $type, string $value): int
    {
        return DB::table('client_identifiers')->insertGetId(['client_id' => $client, 'identifier_type' => $type, 'protected_value' => 'not-exposed-in-tests', 'normalized_hash' => app(IdentityIntegrity::class)->identifierHash($value), 'verification_status' => 'verified', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function confirm(User $reviewer, int $candidate): void
    {
        $this->actingAs($reviewer)->patch(route('identity.review.decision', $candidate), ['decision' => 'same_person', 'rationale' => 'Verified through authorized records.'])->assertRedirect();
    }

    private function proposal(User $reviewer, int $candidate, int $source, int $survivor): int
    {
        $this->actingAs($reviewer)->post(route('identity.merge.propose', $candidate), ['source_client_id' => $source, 'survivor_client_id' => $survivor, 'reason' => 'Reviewed duplicate identity.', 'field_decisions' => [['field_name' => 'first_name', 'resolution' => 'survivor_value']]])->assertRedirect();

        return (int) DB::table('client_identity_merges')->latest('id')->value('id');
    }

    public function test_old_duplicate_endpoint_is_retired_and_cannot_expose_match_details(): void
    {
        $user = $this->user('cpu_member');
        $this->actingAs($user)->getJson(route('cases.duplicates', ['first_name' => 'Ana', 'last_name' => 'Cruz']))->assertStatus(410)->assertSee('Identity Review');
    }

    public function test_deterministic_candidates_are_explainable_and_never_auto_merge(): void
    {
        $reviewer = $this->user();
        $a = $this->client();
        $b = $this->client(['first_name' => 'Ana', 'last_name' => 'Cruz', 'birthdate' => '2012-01-01', 'sex' => 'Female', 'municipality' => 'Tabuk City', 'province' => 'Kalinga', 'barangay' => 'Bulanao']);
        $this->caseFor($a, $reviewer);
        $this->caseFor($b, $reviewer, 'KLG-ID-2');
        app(IdentityIntegrity::class)->generateForClient($a, $reviewer->id);
        $candidate = DB::table('identity_duplicate_candidates')->first();
        $this->assertNotNull($candidate);
        $this->assertSame('potential_duplicate', $candidate->status);
        $this->assertContains('exact_dob', json_decode($candidate->matching_signals, true)['matches']);
        $this->assertDatabaseHas('clients', ['id' => $a, 'identity_status' => 'active']);
        $this->assertDatabaseHas('clients', ['id' => $b, 'identity_status' => 'active']);
    }

    public function test_twins_siblings_and_same_name_are_review_prompts_not_identity_merges(): void
    {
        $reviewer = $this->user();
        $a = $this->client(['first_name' => 'Maria', 'last_name' => 'Santos', 'birthdate' => '2014-02-02']);
        $b = $this->client(['first_name' => 'Maria', 'last_name' => 'Santos', 'birthdate' => '2014-02-02']);
        $this->caseFor($a, $reviewer);
        $this->caseFor($b, $reviewer, 'KLG-ID-3');
        $candidate = $this->candidate($a, $b);
        $this->actingAs($reviewer)->patch(route('identity.review.decision', $candidate), ['decision' => 'related_person', 'rationale' => 'Twins in the same household, not the same person.'])->assertRedirect();
        $this->actingAs($reviewer)->post(route('identity.review.link', $candidate), ['link_type' => 'related_person', 'rationale' => 'Sibling relationship verified.'])->assertRedirect();
        $this->assertDatabaseHas('client_identity_links', ['left_client_id' => min($a, $b), 'right_client_id' => max($a, $b)]);
        $this->assertDatabaseHas('clients', ['id' => $a, 'identity_status' => 'active']);
        $this->assertDatabaseMissing('client_identity_merges', ['source_client_id' => $a]);
    }

    public function test_proposal_approval_execution_are_separate_and_preserve_multiple_cases(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $executor = $this->user('fo_user');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D', 'municipality' => 'Tabuk City']);
        $caseA = $this->caseFor($a, $proposer);
        $caseB = $this->caseFor($b, $proposer, 'KLG-ID-4');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($proposer)->patch(route('identity.merge.approve', $merge))->assertForbidden();
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge))->assertRedirect();
        $this->actingAs($executor)->postJson(route('identity.merge.execute', $merge))->assertOk()->assertJsonPath('status', 'executed');
        $this->assertDatabaseHas('clients', ['id' => $b, 'identity_status' => 'merged', 'canonical_client_id' => $a]);
        $this->assertDatabaseHas('cases', ['id' => $caseA, 'client_id' => $a]);
        $this->assertDatabaseHas('cases', ['id' => $caseB, 'client_id' => $a]);
        $this->assertDatabaseCount('cases', 2);
        $this->assertDatabaseHas('client_identity_merge_affected_records', ['merge_id' => $merge, 'record_type' => 'case', 'record_id' => $caseB]);
    }

    public function test_merge_retry_is_idempotent_and_does_not_duplicate_transfers_or_audit_events(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-5');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge));
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge))->assertOk();
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge))->assertOk()->assertJsonPath('status', 'executed');
        $this->assertDatabaseCount('client_identity_merge_affected_records', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'identity_merge.executed')->where('auditable_id', $merge)->count());
    }

    public function test_stale_approval_and_already_merged_source_are_rejected_without_relaxing_scope(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-6');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        DB::table('clients')->where('id', $b)->update(['first_name' => 'Changed']);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge))->assertStatus(409);
        $this->assertDatabaseHas('client_identity_merges', ['id' => $merge, 'status' => 'proposed']);
        DB::table('clients')->where('id', $b)->update(['identity_status' => 'merged', 'canonical_client_id' => $a]);
        $this->actingAs($proposer)->post(route('identity.merge.propose', $candidate), ['source_client_id' => $b, 'survivor_client_id' => $a, 'reason' => 'retry', 'field_decisions' => [['field_name' => 'first_name', 'resolution' => 'survivor_value']]])->assertStatus(422);
    }

    public function test_cross_jurisdiction_candidate_is_minimized_and_cannot_be_merged(): void
    {
        $local = $this->user('social_worker', 'Tabuk City');
        $a = $this->client(['municipality' => 'Tabuk City']);
        $b = $this->client(['first_name' => 'Ana', 'municipality' => 'Other LGU', 'province' => 'Other']);
        $this->caseFor($a, $local);
        $other = $this->user('social_worker', 'Other LGU');
        $this->caseFor($b, $other, 'KLG-ID-7');
        $candidate = $this->candidate($a, $b, 'cross_jurisdiction');
        $payload = $this->actingAs($local)->getJson(route('identity.review.show', $candidate))->assertOk()->json('candidate');
        $this->assertTrue($payload['right']['restricted']);
        $this->assertArrayNotHasKey('birthdate', $payload['right']);
        $this->actingAs($local)->patch(route('identity.review.decision', $candidate), ['decision' => 'same_person', 'rationale' => 'cannot'])->assertStatus(422);
    }

    public function test_identifier_conflicts_are_preserved_with_source_provenance_and_never_auto_moved(): void
    {
        $proposer = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-IDENTIFIER');
        $survivorIdentifier = $this->identifier($a, 'pwd_card', 'PWD-A');
        $sourceIdentifier = $this->identifier($b, 'pwd_card', 'PWD-B');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->assertDatabaseHas('client_identity_merge_identifier_conflicts', ['merge_id' => $merge, 'source_identifier_id' => $sourceIdentifier, 'survivor_identifier_id' => $survivorIdentifier, 'resolution' => 'preserve_source_provenance']);
        $this->assertDatabaseHas('client_identifiers', ['id' => $sourceIdentifier, 'client_id' => $b]);
        $this->assertDatabaseHas('client_identifiers', ['id' => $survivorIdentifier, 'client_id' => $a]);
    }

    public function test_authorized_historical_identifier_lookup_resolves_only_the_canonical_survivor_without_exposing_value(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-HISTORY');
        $this->identifier($b, 'local_reference', 'HISTORY-001');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge))->assertRedirect();
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge))->assertOk();
        $this->actingAs($proposer)->postJson(route('identity.review.historical-identifier'), ['identifier_type' => 'local_reference', 'identifier' => 'HISTORY-001'])->assertOk()->assertJsonPath('matches.0.canonical_client_id', $a)->assertDontSee('HISTORY-001')->assertJsonMissing(['protected_value' => 'not-exposed-in-tests']);
        $audit = (string) DB::table('audit_logs')->where('event', 'identity_identifier.resolved')->value('changes');
        $this->assertStringNotContainsString('HISTORY-001', $audit);
    }

    public function test_historical_identifier_lookup_does_not_bypass_case_scope_or_normal_search_keeps_merged_source_hidden(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $outsider = $this->user('social_worker', 'Other LGU');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-HIDDEN');
        $this->identifier($b, 'local_reference', 'HISTORY-002');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge));
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge));
        $this->actingAs($outsider)->postJson(route('identity.review.historical-identifier'), ['identifier_type' => 'local_reference', 'identifier' => 'HISTORY-002'])->assertOk()->assertJsonCount(0, 'matches');
        $this->actingAs($proposer)->getJson(route('search', ['q' => 'Ana Cruz']))->assertOk()->assertJsonMissing(['id' => $b]);
        $payload = $this->actingAs($proposer)->getJson(route('identity.review.show', $candidate))->assertOk()->json('candidate');
        $mergedSide = ($payload['left']['merged'] ?? false) ? $payload['left'] : $payload['right'];
        $this->assertTrue($mergedSide['merged']);
        $this->assertSame('Merged identity → canonical survivor', $mergedSide['label']);
        $this->assertArrayNotHasKey('birthdate', $mergedSide);
    }

    public function test_pending_or_completed_merge_locks_the_candidate_review_history(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $this->caseFor($a, $proposer);
        $this->caseFor($b, $proposer, 'KLG-ID-LOCKED');
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($proposer)->patch(route('identity.review.decision', $candidate), ['decision' => 'different_person', 'rationale' => 'Must not alter a pending merge.'])->assertStatus(422);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge));
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge));
        $this->actingAs($approver)->patch(route('identity.review.decision', $candidate), ['decision' => 'different_person', 'rationale' => 'Must not alter an executed merge.'])->assertStatus(422);
        $this->assertDatabaseHas('identity_duplicate_candidates', ['id' => $candidate, 'status' => 'merged']);
        $this->assertSame(1, DB::table('identity_review_decisions')->where('candidate_id', $candidate)->count());
    }

    public function test_governed_documents_and_holds_remain_case_owned_when_identity_is_merged(): void
    {
        $proposer = $this->user('social_worker');
        $approver = $this->user('social_worker');
        $a = $this->client();
        $b = $this->client(['middle_name' => 'D']);
        $caseA = $this->caseFor($a, $proposer);
        $caseB = $this->caseFor($b, $proposer, 'KLG-ID-8');
        $doc = DB::table('documents')->insertGetId(['case_id' => $caseB, 'name' => 'protected.pdf', 'disk' => 'private', 'path' => 'x', 'mime_type' => 'application/pdf', 'version' => 1, 'uploaded_by' => $proposer->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('record_governance')->insert(['case_id' => $caseB, 'record_type' => 'document', 'record_id' => $doc, 'lifecycle_state' => 'retained', 'scan_state' => 'legacy_unverified', 'legacy_record' => true, 'created_at' => now(), 'updated_at' => now()]);
        $hold = DB::table('legal_holds')->insertGetId(['case_id' => $caseB, 'scope' => 'case', 'reason_code' => 'legal', 'authority' => 'court', 'placed_by' => $approver->id, 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $candidate = $this->candidate($a, $b);
        $this->confirm($proposer, $candidate);
        $merge = $this->proposal($proposer, $candidate, $b, $a);
        $this->actingAs($approver)->patch(route('identity.merge.approve', $merge));
        $this->actingAs($approver)->postJson(route('identity.merge.execute', $merge));
        $this->assertDatabaseHas('documents', ['id' => $doc, 'case_id' => $caseB]);
        $this->assertDatabaseHas('record_governance', ['record_id' => $doc, 'case_id' => $caseB, 'lifecycle_state' => 'retained']);
        $this->assertDatabaseHas('legal_holds', ['id' => $hold, 'case_id' => $caseB, 'released_at' => null]);
    }

    public function test_legacy_assessment_reconciliation_is_explicit_and_partial_never_becomes_final_comprehensive_assessment(): void
    {
        $reviewer = $this->user('social_worker');
        $client = $this->client();
        $case = $this->caseFor($client, $reviewer);
        $legacy = DB::table('assessments')->insertGetId(['case_id' => $case, 'status' => 'completed', 'responses' => json_encode(['old' => 'data']), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertDatabaseMissing('legacy_assessment_reconciliations', ['legacy_assessment_id' => $legacy]);
        $this->actingAs($reviewer)->patch(route('legacy-assessments.reconcile', $legacy), ['classification' => 'partial', 'unresolved_fields' => ['health_srh']])->assertRedirect();
        $this->assertDatabaseHas('legacy_assessment_reconciliations', ['legacy_assessment_id' => $legacy, 'classification' => 'partial', 'validation_status' => 'reviewed']);
        $this->assertDatabaseMissing('case_assessments', ['case_id' => $case, 'status' => 'final']);
    }
}
