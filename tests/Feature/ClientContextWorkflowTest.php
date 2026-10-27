<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticTrigger;
use Tests\TestCase;

/**
 * Client & Intake writes on the case page: support relationships and the
 * signed consent / assent form photo. The separate consent register was
 * removed on 2026-10-28 (the intake records consent).
 */
class ClientContextWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
    }

    private function context(): array
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'province' => 'Kalinga']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Context', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'SYN-CONTEXT-1', 'client_id' => $client, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$worker, $case, $client];
    }

    private function photo(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        ob_start();
        imagejpeg($image);

        return 'data:image/jpeg;base64,' . base64_encode((string) ob_get_clean());
    }

    private function write(string $kind, int $case)
    {
        return match ($kind) {
            'relationship' => $this->post(route('cases.relationships.store', $case), ['name' => 'Synthetic support', 'relationship_type' => 'Aunt', 'is_guardian' => false]),
            'consent_form' => $this->post(route('cases.consent-form.store', $case), ['consent_form_type' => 'assent', 'consent_photo' => $this->photo()]),
        };
    }

    public function test_consent_register_is_gone_and_the_signed_form_can_be_added_later(): void
    {
        [$worker, $case] = $this->context();
        $this->assertFalse(Route::has('cases.consents.store'));
        $this->assertFalse(Route::has('cases.consents.withdraw'));
        $this->assertFalse($worker->hasPermission('consents.record'));
        $this->actingAs($worker)->get(route('cases.show', $case))->assertOk()
            ->assertInertia(fn ($page) => $page->missing('consents')->where('consentForm.document_id', null)->where('consentForm.sharing', false));

        $this->post(route('cases.consent-form.store', $case), ['consent_form_type' => 'assent'])->assertSessionHasErrors('consent_photo');
        $this->write('consent_form', $case)->assertSessionHasNoErrors()->assertRedirect();
        $document = DB::table('cases')->where('id', $case)->value('consent_document_id');
        $this->assertNotNull($document);
        $this->assertDatabaseHas('cases', ['id' => $case, 'consent_form_type' => 'assent']);
        $this->assertDatabaseHas('documents', ['id' => $document, 'name' => 'Assent form (case page).jpg', 'source_module' => 'intake_consent']);
        $this->get(route('cases.show', $case))->assertInertia(fn ($page) => $page->where('consentForm.document_id', $document)->where('consentForm.sharing', true));
    }

    public function test_a_referral_on_consent_needs_the_signed_form(): void
    {
        [$worker, $case] = $this->context();
        $this->actingAs($worker)->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Education', 'lawful_basis' => 'consent'])
            ->assertSessionHasErrors(['lawful_basis' => 'This case has no signed consent / assent form yet. Add the photo in Client & Intake, or choose another documented basis.']);
        $this->write('consent_form', $case)->assertSessionHasNoErrors();
        $this->post(route('referrals.store'), ['case_id' => $case, 'service_category' => 'Education', 'lawful_basis' => 'consent'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('referrals', ['case_id' => $case, 'lawful_basis' => 'consent']);
    }

    public function test_case_visibility_does_not_grant_relationship_or_consent_form_writes(): void
    {
        [, $case] = $this->context();
        $viewer = User::factory()->create(['role' => 'fo_user', 'province' => 'Kalinga']);
        $this->actingAs($viewer);
        foreach (['relationship', 'consent_form'] as $kind) {
            $this->write($kind, $case)->assertForbidden();
        }
        $this->assertDatabaseCount('relationships', 0);
        $this->assertDatabaseHas('cases', ['id' => $case, 'consent_document_id' => null]);
    }

    public function test_writes_roll_back_when_audit_storage_fails(): void
    {
        [$worker, $case] = $this->context();
        $this->actingAs($worker);
        SyntheticTrigger::create("CREATE TRIGGER synthetic_context_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.event IN ('relationship.recorded','document.uploaded') BEGIN SELECT RAISE(ABORT, 'Synthetic context audit failure'); END");
        $this->withoutExceptionHandling();
        foreach (['relationship', 'consent_form'] as $kind) {
            try {
                $this->write($kind, $case);
                $this->fail('Audit failure must reject the write.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Synthetic context audit failure', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('relationships', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseHas('cases', ['id' => $case, 'consent_document_id' => null]);
    }

    public function test_archived_case_rejects_relationship_and_consent_form_writes(): void
    {
        [$worker, $case] = $this->context();
        DB::table('cases')->where('id', $case)->update(['deleted_at' => now()]);
        $this->actingAs($worker);
        foreach (['relationship', 'consent_form'] as $kind) {
            $this->write($kind, $case)->assertNotFound();
        }
    }
}
