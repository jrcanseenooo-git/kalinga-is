<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\KalingaConnectDemoScopeSeeder;
use Database\Seeders\KalingaConnectDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KalingaConnectDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function demoUser(string $email, string $role): User
    {
        $user = User::factory()->create(['email' => $email, 'role' => $role, 'active' => true]);
        $lgu = DB::table('lgu_units')->where('psgc_code', 'DEMO-LGU-01')->value('id');
        if (!$lgu) {
            $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'DEMO-LGU-01', 'name' => 'City of Tabuk', 'province' => 'Kalinga', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_demo_worker_and_cpu_see_the_same_circle_without_duplicate_setup(): void
    {
        $worker = $this->demoUser('worker@kalinga.test', 'social_worker');
        $cpu = $this->demoUser('cpu@kalinga.test', 'cpu_member');
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->assertDatabaseCount('lgu_coordination_circles', 1);
        $this->assertDatabaseCount('lgu_coordination_members', 2);
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'like', 'demo.cpu_chat.%')->count());
        foreach ([$worker, $cpu] as $user) {
            $this->actingAs($user)->get(route('cpu.coordination.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Cpu/Coordination')
                    ->has('circles', 1)->where('circles.0.name', 'City of Tabuk CPU Coordination Circle')->has('members', 2));
        }
    }

    public function test_repair_preserves_departed_memberships_and_existing_messages(): void
    {
        $worker = $this->demoUser('worker@kalinga.test', 'social_worker');
        $this->seed(KalingaConnectDemoSeeder::class);
        $circle = DB::table('lgu_coordination_circles')->value('id');
        DB::table('lgu_coordination_members')->where('user_id', $worker->id)->update(['left_at' => now()]);
        $message = DB::table('lgu_coordination_messages')->insertGetId(['circle_id' => $circle, 'author_id' => $worker->id, 'body' => 'Fictional coordination message', 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->assertNotNull(DB::table('lgu_coordination_members')->where('user_id', $worker->id)->value('left_at'));
        $this->assertDatabaseHas('lgu_coordination_messages', ['id' => $message, 'body' => 'Fictional coordination message']);
    }

    public function test_expired_assignment_and_non_demo_user_receive_no_membership(): void
    {
        $worker = $this->demoUser('worker@kalinga.test', 'social_worker');
        $this->demoUser('other@example.test', 'cpu_member');
        DB::table('user_lgu_assignments')->where('user_id', $worker->id)->update(['effective_to' => now()->subHour()]);
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->assertDatabaseCount('lgu_coordination_members', 0);
        $this->assertDatabaseCount('lgu_coordination_circles', 0);
    }

    public function test_repair_does_not_reactivate_disabled_circles(): void
    {
        $this->demoUser('worker@kalinga.test', 'social_worker');
        $this->seed(KalingaConnectDemoSeeder::class);
        DB::table('lgu_coordination_circles')->update(['active' => false]);
        $this->demoUser('cpu@kalinga.test', 'cpu_member');
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->assertDatabaseCount('lgu_coordination_members', 1);
        $this->assertFalse((bool) DB::table('lgu_coordination_circles')->value('active'));
    }

    public function test_scope_repair_ends_only_extra_demo_assignments_and_memberships(): void
    {
        $worker = $this->demoUser('worker@kalinga.test', 'social_worker');
        $other = $this->demoUser('other@example.test', 'social_worker');
        $extra = DB::table('lgu_units')->insertGetId(['psgc_code' => 'DEMO-LGU-02', 'name' => 'Rizal', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$worker, $other] as $user) {
            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $extra, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->seed(KalingaConnectDemoSeeder::class);
        $this->seed(KalingaConnectDemoScopeSeeder::class);
        $this->seed(KalingaConnectDemoScopeSeeder::class);
        $this->assertDatabaseHas('user_lgu_assignments', ['user_id' => $worker->id, 'lgu_id' => $extra, 'active' => false]);
        $this->assertDatabaseHas('user_lgu_assignments', ['user_id' => $other->id, 'lgu_id' => $extra, 'active' => true]);
        $this->actingAs($worker)->get(route('cpu.coordination.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('circles', 1)->where('circles.0.lgu_name', 'City of Tabuk'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'demo.cpu_chat.scope_corrected')->count());
        $this->assertDatabaseCount('lgu_coordination_circles', 2);
    }
}
