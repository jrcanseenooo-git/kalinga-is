<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IntakeDrafts;
use App\Services\OptionSets;
use App\Support\ClientName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The 4-step Kalinga Intake, CEFMU incidence, consent form photo and saved intakes. */
class KalingaIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        OptionSets::ensureDefaults();
        Storage::fake(config('filesystems.default'));
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-INTAKE', 'name' => 'Synthetic Town', 'province' => 'Synthetic Province', 'region' => 'Synthetic Region', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->worker = User::factory()->create(['role' => 'social_worker']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $this->worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function photo(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        // A comment segment stands in for camera or location details that must not survive.
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xFE\x00\x14GPS-SECRET-LOCATION" . substr($jpeg, 2);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    private function intake(array $overrides = []): array
    {
        return array_replace([
            'privacy_notice_acknowledged' => true, 'privacy_notice_method' => 'explained_verbally',
            'first_name' => 'John', 'middle_name' => 'Santos', 'last_name' => 'Dela Cruz', 'alias' => 'Jojo', 'birthdate' => '2010-05-01', 'sex' => 'Male',
            'barangay' => 'Poblacion', 'permanent_same_as_present' => true,
            'primary_caregiver_name' => 'Synthetic Caregiver', 'primary_caregiver_relationship' => 'Mother', 'caregiver_same_address' => false,
            'caregiver_region' => 'Region IV-A (CALABARZON)', 'caregiver_province' => 'Rizal', 'caregiver_municipality' => 'Tanay', 'caregiver_barangay' => 'Sampaloc',
            'family_members' => [
                ['name' => 'Synthetic Sibling', 'relationship_type' => 'Sibling', 'civil_status' => 'Single', 'same_address' => true],
                ['name' => 'Synthetic Father', 'relationship_type' => 'Father', 'same_address' => false, 'municipality' => 'Pililla', 'province' => 'Rizal'],
            ],
            'cefmu_classifications' => ['early_marriage', 'forced_union'], 'union_forms' => ['civil_marriage', 'religious_marriage'],
            'union_status' => 'yes_now', 'partner_current_age' => 24, 'union_duration_months' => 14, 'current_residence' => 'partner_parents_house',
            'mode_of_admission' => 'Walk-in', 'priority' => 'normal',
            'risk_indicators' => ['age_or_power_imbalance', 'school_interruption'], 'initial_risk_level' => 'high',
        ], $overrides);
    }

    private function asWorker(): self
    {
        return $this->actingAs($this->worker)->withSession(['two_factor_confirmed' => true]);
    }

    public function test_intake_creates_the_case_with_incidence_addresses_risk_points_and_a_sanitized_consent_photo(): void
    {
        $response = $this->asWorker()->post(route('cases.store'), $this->intake(['consent_photo' => $this->photo(), 'consent_form_type' => 'assent']));
        $case = DB::table('cases')->latest('id')->first();
        $response->assertRedirect(route('cases.show', $case->id));

        $this->assertSame(['early_marriage', 'forced_union'], json_decode($case->cefmu_classifications, true));
        $this->assertSame(['civil_marriage', 'religious_marriage'], json_decode($case->union_forms, true));
        $this->assertSame('yes_now', $case->union_status);
        $this->assertSame(24, (int) $case->partner_current_age);
        $this->assertSame(14, (int) $case->union_duration_months);
        $this->assertSame('partner_parents_house', $case->current_residence);
        $this->assertSame('high', $case->suggested_risk_level);
        $this->assertEquals(2, (float) $case->risk_points);

        $client = DB::table('clients')->find($case->client_id);
        $this->assertSame('Jojo', $client->alias);
        $this->assertSame('Synthetic Town', $client->municipality);
        $this->assertSame('Poblacion', $client->permanent_barangay, 'Permanent address copied from the present address.');
        $this->assertSame('Tanay', $client->caregiver_municipality);
        $this->assertDatabaseHas('relationships', ['client_id' => $client->id, 'name' => 'Synthetic Sibling', 'same_address_as_client' => true, 'civil_status' => 'Single']);
        $this->assertDatabaseHas('relationships', ['client_id' => $client->id, 'name' => 'Synthetic Father', 'same_address_as_client' => false, 'municipality' => 'Pililla']);

        $document = DB::table('documents')->find($case->consent_document_id);
        $this->assertSame('Assent form (intake).jpg', $document->name);
        $this->assertSame('sanitized', DB::table('record_governance')->where('record_type', 'document')->where('record_id', $document->id)->value('scan_state'));
        $stored = Storage::disk($document->disk)->get($document->path);
        $this->assertStringNotContainsString('GPS-SECRET-LOCATION', $stored);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored)[2]);
        $this->asWorker()->get(route('cases.documents.download', [$case->id, $document->id]))->assertOk();
        $this->assertAuditNeverContains('data:image');
    }

    public function test_child_contact_details_take_an_11_digit_number_and_other_channels(): void
    {
        $this->asWorker()->post(route('cases.store'), $this->intake(['phone' => '+639171234567']))->assertSessionHasErrors('phone');
        $this->post(route('cases.store'), $this->intake(['phone' => '0917 123 4567']))->assertSessionHasErrors('phone');
        $this->post(route('cases.store'), $this->intake(['contact_channels' => [['channel' => 'Carrier pigeon', 'handle' => 'x']]]))->assertSessionHasErrors('contact_channels.0.channel');
        $this->post(route('cases.store'), $this->intake(['phone' => '09171234567', 'contact_channels' => [['channel' => 'Facebook Messenger', 'handle' => ' fb.com/synthetic.child ']]]))->assertSessionHasNoErrors();
        $client = DB::table('clients')->latest('id')->first();
        $this->assertSame('09171234567', $client->phone);
        $this->assertSame([['channel' => 'Facebook Messenger', 'handle' => 'fb.com/synthetic.child']], json_decode($client->contact_channels, true));
    }

    public function test_a_level_lower_than_the_risk_factors_suggest_needs_a_reason(): void
    {
        $data = $this->intake(['risk_indicators' => ['immediate_danger'], 'initial_risk_level' => 'moderate']);
        $this->asWorker()->post(route('cases.store'), $data)->assertSessionHasErrors('risk_level_reason');

        $this->asWorker()->post(route('cases.store'), $data + ['risk_level_reason' => 'The child moved to a safe relative today.'])->assertRedirect();
        $this->assertDatabaseHas('cases', ['initial_risk_level' => 'moderate', 'suggested_risk_level' => 'critical', 'immediate_safety_concern' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'case.risk_level_lowered', 'old_value' => 'critical', 'new_value' => 'moderate']);
    }

    public function test_the_marriage_and_union_answers_must_fit_together(): void
    {
        $this->asWorker()->post(route('cases.store'), $this->intake(['cefmu_classifications' => ['early_union']]))->assertSessionHasErrors('union_forms');
        $this->asWorker()->post(route('cases.store'), $this->intake(['union_status' => 'no']))->assertSessionHasErrors('union_status');
        $this->asWorker()->post(route('cases.store'), $this->intake(['cefmu_classifications' => ['child_bride']]))->assertSessionHasErrors('cefmu_classifications.0');
        $this->asWorker()->post(route('cases.store'), $this->intake(['consent_photo' => 'data:image/jpeg;base64,' . base64_encode('<?php echo 1; ?>')]))->assertSessionHasErrors('consent_photo');
        $this->assertSame(0, DB::table('cases')->count());
    }

    public function test_saved_intakes_are_encrypted_private_resumable_and_removed_when_the_case_is_created(): void
    {
        $id = (string) Str::uuid();
        $this->asWorker()->putJson(route('intake.drafts.update', $id), ['step' => 2, 'payload' => ['first_name' => 'John']])->assertStatus(422);
        $this->asWorker()->putJson(route('intake.drafts.update', $id), ['step' => 2, 'payload' => $this->intake()])->assertOk();

        $row = DB::table('intake_drafts')->find($id);
        $this->assertSame('J**S***D**UZ', $row->label);
        $this->assertStringNotContainsString('Dela Cruz', $row->payload);
        $this->assertSame('Dela Cruz', json_decode(Crypt::decryptString($row->payload), true)['last_name']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'intake_draft.created', 'user_id' => $this->worker->id]);

        $other = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($other)->withSession(['two_factor_confirmed' => true])->putJson(route('intake.drafts.update', $id), ['step' => 3, 'payload' => $this->intake()])->assertNotFound();
        $this->actingAs($other)->withSession(['two_factor_confirmed' => true])->get(route('cases.create', ['draft' => $id]))->assertNotFound();

        $this->asWorker()->get(route('cases.create'))->assertInertia(fn ($page) => $page->where('savedIntakes.0.id', $id)->missing('savedIntakes.0.payload'));
        $this->asWorker()->get(route('cases.create', ['draft' => $id]))->assertInertia(fn ($page) => $page->where('draft.step', 2)->where('draft.payload.alias', 'Jojo'));

        $this->asWorker()->post(route('cases.store'), $this->intake(['draft_id' => $id]))->assertRedirect();
        $this->assertDatabaseMissing('intake_drafts', ['id' => $id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'intake_draft.completed']);
    }

    public function test_saved_intakes_can_be_discarded_and_expire(): void
    {
        $keep = (string) Str::uuid();
        $old = (string) Str::uuid();
        IntakeDrafts::save($keep, $this->worker->id, null, 1, ['privacy_notice_acknowledged' => true]);
        IntakeDrafts::save($old, $this->worker->id, null, 1, ['privacy_notice_acknowledged' => true]);
        DB::table('intake_drafts')->where('id', $old)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('kalinga:purge-intake-drafts')->assertSuccessful();
        $this->assertDatabaseMissing('intake_drafts', ['id' => $old]);
        $this->asWorker()->delete(route('intake.drafts.destroy', $keep))->assertRedirect();
        $this->assertSame(0, DB::table('intake_drafts')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'intake_draft.expired']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'intake_draft.discarded']);
    }

    public function test_masked_names_follow_the_owner_example(): void
    {
        $this->assertSame('J**S***D**UZ', ClientName::masked('John', 'Santos', 'Dela Cruz'));
        $this->assertSame('M**R**OS', ClientName::masked('Maria', null, 'Ramos'));
        $this->assertSame('Not recorded', ClientName::masked(null, null, null));
        $this->assertSame('Jojo', ClientName::secured((object) ['alias' => ' Jojo ', 'first_name' => 'John', 'last_name' => 'Dela Cruz']));
    }
}
