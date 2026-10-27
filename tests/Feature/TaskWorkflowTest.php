<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_complete_linked_task(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Sam', 'last_name' => 'Lopez', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-2026-TASK-01', 'client_id' => $client, 'assigned_to' => $owner->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => 'normal', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->post(route('tasks.store'), ['title' => 'Schedule follow-up', 'case_id' => $case, 'due_at' => '2026-10-01 09:00'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['title' => 'Schedule follow-up', 'case_id' => $case, 'assigned_to' => $owner->id, 'status' => 'open']);
        // The organizer is not notified of their own activity (it would wake their phone); the history records it.
        $this->assertDatabaseHas('task_history', ['actor_id' => $owner->id, 'event' => 'created']);
        $task = DB::table('tasks')->value('id');
        $this->actingAs($owner)->patch(route('tasks.transition', $task), ['status' => 'completed'])->assertRedirect();
        $this->assertDatabaseHas('tasks', ['id' => $task, 'status' => 'completed']);
    }

    public function test_other_user_cannot_update_task_or_link_unassigned_case(): void
    {
        $owner = User::factory()->create(['role' => 'social_worker']);
        $other = User::factory()->create(['role' => 'social_worker']);
        $task = DB::table('tasks')->insertGetId(['assigned_to' => $owner->id, 'title' => 'Private task', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($other)->patch(route('tasks.transition', $task), ['status' => 'completed'])->assertForbidden();
    }
}
