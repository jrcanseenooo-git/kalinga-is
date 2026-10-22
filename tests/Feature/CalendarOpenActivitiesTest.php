<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalendarOpenActivitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_shows_open_and_recent_closed_activities_but_not_old_ones(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);

        foreach ([['Open calendar task', 'open'], ['Completed calendar task', 'completed'], ['Cancelled calendar task', 'cancelled']] as [$title, $status]) {
            DB::table('tasks')->insert([
                'assigned_to' => $worker->id, 'title' => $title, 'status' => $status,
                'due_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('tasks')->insert(['assigned_to' => $worker->id, 'title' => 'Old completed task', 'status' => 'completed',
            'due_at' => now()->subDays(200), 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(120)]);

        // Recently closed activities stay on the calendar (shown struck through);
        // only open ones count towards the summary; old closed ones drop off.
        $this->actingAs($worker)->get(route('calendar.index'))
            ->assertOk()
            ->assertSee('Open calendar task')
            ->assertSee('Completed calendar task')
            ->assertDontSee('Old completed task')
            ->assertInertia(fn ($page) => $page->where('summary.open', 1)->has('tasks', 3));
    }
}
