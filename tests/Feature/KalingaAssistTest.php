<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Kalinga Assist: the rule-based helper panel's "Needs your attention" list. */
class KalingaAssistTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_manager_gets_case_next_steps_with_case_numbers_only(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker', 'active' => true]);
        $lgu = $this->fixtureLgu('City of Tabuk', 'Kalinga', 'CAR');
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Secretname', 'last_name' => 'Hidden', 'municipality' => 'City of Tabuk', 'province' => 'Kalinga', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->insert(['case_number' => 'ASSIST-0001', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN', 'priority' => 'normal',
            'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($worker)->getJson(route('assist.index'))->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $items = collect($response->json('items'));
        $this->assertTrue($items->contains(fn ($item) => $item['title'] === 'Record the baseline KaVAT result' && str_starts_with($item['text'], 'ASSIST-0001: ')));
        $this->assertStringNotContainsString('Secretname', $response->getContent());
    }

    public function test_every_role_can_open_assist_and_guests_cannot(): void
    {
        $this->getJson(route('assist.index'))->assertUnauthorized();
        foreach (['system_admin', 'project_manager', 'lgu_head', 'cpu_member'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'active' => true]))->getJson(route('assist.index'))->assertOk()->assertJsonStructure(['items', 'total']);
        }
    }
}
