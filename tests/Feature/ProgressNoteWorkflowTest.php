<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProgressNoteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function caseFor(User $owner, string $number = 'KLG-2026-NOTE-01'): int
    {
        $client = DB::table('clients')->insertGetId([
            'first_name' => 'Progress', 'last_name' => 'Client',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cases')->insertGetId([
            'case_number' => $number, 'client_id' => $client, 'assigned_to' => $owner->id,
            'status' => 'IN_PROGRESS', 'stage' => 'casework', 'priority' => 'normal',
            'opened_at' => now(), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_authorized_worker_finalizes_structured_note_and_can_explicitly_create_linked_follow_up(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);

        $this->actingAs($worker)->post(route('cases.notes.store', $case), [
            'body' => 'Worker completed the agreed contact and documented a follow-up need.',
            'note_type' => 'contact',
            'occurred_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
            'create_follow_up' => true,
            'follow_up_title' => 'Confirm service attendance',
            'follow_up_due_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $note = DB::table('case_notes')->where('case_id', $case)->first();
        $this->assertSame('progress_note', $note->record_type);
        $this->assertSame('contact', $note->note_type);
        $this->assertNotNull($note->finalized_at);
        $this->assertSame($worker->id, $note->finalized_by);
        $this->assertDatabaseHas('tasks', [
            'case_id' => $case, 'title' => 'Confirm service attendance', 'status' => 'open',
            'source_type' => 'case_note', 'source_id' => $note->id,
        ]);
        $audit = DB::table('audit_logs')->where('event', 'case_note.finalized')->latest('id')->first();
        $this->assertStringNotContainsString('Worker completed', (string) $audit->changes);
    }

    public function test_explicit_philippine_note_and_follow_up_times_are_stored_as_utc(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->travelTo(Carbon::parse('2026-10-19 03:00:00', 'UTC'));
        try {
            $this->actingAs($worker)->post(route('cases.notes.store', $case), [
                'body' => 'Synthetic Philippine-time note.', 'note_type' => 'contact',
                'occurred_at' => '2026-10-19T10:15:00+08:00', 'create_follow_up' => true,
                'follow_up_title' => 'Synthetic Philippine-time follow-up', 'follow_up_due_at' => '2026-10-19T18:00:00+08:00',
            ])->assertRedirect();
            $this->assertDatabaseHas('case_notes', ['case_id' => $case, 'occurred_at' => '2026-10-19 02:15:00']);
            $this->assertDatabaseHas('tasks', ['case_id' => $case, 'due_at' => '2026-10-19 10:00:00']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_explicit_philippine_addendum_time_is_stored_as_utc(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->travelTo(Carbon::parse('2026-10-19 03:00:00', 'UTC'));
        try {
            $note = DB::table('case_notes')->insertGetId(['case_id' => $case, 'body' => 'Synthetic original note.', 'visibility' => 'CASE_NOTES', 'note_type' => 'contact', 'record_type' => 'progress_note', 'occurred_at' => '2026-10-18 01:00:00', 'finalized_at' => now(), 'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->actingAs($worker)->post(route('cases.notes.addendum', [$case, $note]), ['body' => 'Synthetic supplementary record.', 'addendum_reason' => 'Synthetic clarification.', 'occurred_at' => '2026-10-19T10:30:00+08:00'])->assertRedirect();
            $this->assertDatabaseHas('case_notes', ['previous_note_id' => $note, 'occurred_at' => '2026-10-19 02:30:00']);
            $this->assertDatabaseHas('case_notes', ['id' => $note, 'occurred_at' => '2026-10-18 01:00:00']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_final_note_is_preserved_and_addendum_is_a_linked_new_record(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $note = DB::table('case_notes')->insertGetId([
            'case_id' => $case, 'body' => 'Original professional record.', 'visibility' => 'CASE_NOTES',
            'record_type' => 'progress_note', 'note_type' => 'monitoring', 'occurred_at' => now()->subDay(),
            'finalized_at' => now()->subDay(), 'finalized_by' => $worker->id, 'is_current' => true,
            'created_by' => $worker->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->actingAs($worker)->post(route('cases.notes.addendum', [$case, $note]), [
            'body' => 'Supplementary factual update.',
            'addendum_reason' => 'A later verified contact clarified the chronology.',
            'occurred_at' => now()->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->assertDatabaseHas('case_notes', ['id' => $note, 'body' => 'Original professional record.', 'record_type' => 'progress_note']);
        $this->assertDatabaseHas('case_notes', [
            'case_id' => $case, 'previous_note_id' => $note, 'record_type' => 'addendum',
            'body' => 'Supplementary factual update.', 'finalized_by' => $worker->id,
        ]);
        // There is deliberately no note-update route: a direct mutation URL is not a workflow.
        $this->actingAs($worker)->patch("/cases/{$case}/notes/{$note}", ['body' => 'Silently changed'])->assertNotFound();
    }

    public function test_other_worker_cannot_create_or_add_to_an_unassigned_case_note(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($owner);
        $note = DB::table('case_notes')->insertGetId([
            'case_id' => $case, 'body' => 'Protected.', 'visibility' => 'CASE_NOTES',
            'record_type' => 'progress_note', 'note_type' => 'contact', 'occurred_at' => now(),
            'finalized_at' => now(), 'finalized_by' => $owner->id, 'is_current' => true,
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($other)->post(route('cases.notes.store', $case), [
            'body' => 'Not authorized.', 'note_type' => 'contact', 'occurred_at' => now()->format('Y-m-d H:i:s'),
        ])->assertForbidden();
        $this->actingAs($other)->post(route('cases.notes.addendum', [$case, $note]), [
            'body' => 'Not authorized.', 'addendum_reason' => 'No access.', 'occurred_at' => now()->format('Y-m-d H:i:s'),
        ])->assertForbidden();
    }

    public function test_case_workspace_exposes_generic_assessment_metadata_not_legacy_narrative(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        DB::table('assessments')->insert([
            'case_id' => $case, 'status' => 'completed', 'summary' => 'Legacy confidential assessment narrative.',
            'responses' => json_encode(['health' => 'Legacy confidential assessment narrative.']),
            'version' => 1, 'completed_by' => $worker->id, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($worker)->get(route('cases.show', $case))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cases/Show')
                ->where('legacyAssessmentSummary.count', 1)
                ->missing('assessments')
            )
            ->assertDontSee('Legacy confidential assessment narrative.');
    }
}
