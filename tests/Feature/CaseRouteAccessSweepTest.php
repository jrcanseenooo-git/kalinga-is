<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * IDOR sweep: every route that takes a {case} id must refuse a signed-in
 * social worker who is not assigned to that case's LGU, for every HTTP
 * method, before any data is returned or changed.
 */
class CaseRouteAccessSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_case_route_refuses_a_worker_from_another_lgu(): void
    {
        $this->sweep(fn (int $lgu, int $otherLgu) => tap(User::factory()->create(['role' => 'social_worker', 'municipality' => 'Synthetic Other Town']),
            fn ($user) => DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $otherLgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()])));
    }

    /** The system administrator manages accounts only and has no casework access. */
    public function test_every_case_route_refuses_the_system_administrator(): void
    {
        $this->sweep(fn () => User::factory()->create(['role' => 'system_admin']));
    }

    /** A CPU member in the same LGU sees a case only through a referral or conference addressed to them. */
    public function test_every_case_route_refuses_a_cpu_member_without_a_referral(): void
    {
        $this->sweep(fn (int $lgu) => tap(User::factory()->create(['role' => 'cpu_member', 'cpu_discipline' => 'health_officer', 'municipality' => 'Synthetic Owner Town']),
            fn ($user) => DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()])));
    }

    private function sweep(callable $makeVisitor): void
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-SWEEP-A', 'name' => 'Synthetic Owner Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $otherLgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-SWEEP-B', 'name' => 'Synthetic Other Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create(['role' => 'social_worker', 'municipality' => 'Synthetic Owner Town']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $owner->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $stranger = $makeVisitor($lgu, $otherLgu);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Protected', 'municipality' => 'Synthetic Owner Town', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-SWEEP-1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $owner->id, 'status' => 'IN_PROGRESS',
            'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('cases')->where('id', $case)->first();
        $counts = fn () => collect(['case_notes', 'referrals', 'tasks', 'documents', 'consents', 'risk_assessments', 'case_plans', 'kavat_assessments', 'case_forms', 'case_grants', 'case_remarks'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $initial = $counts();

        // Rate limits are not authorization; with this many case routes they would answer 429 first.
        $this->withoutMiddleware(ThrottleRequests::class);
        $checked = 0;
        $leaks = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_contains($route->uri(), '{case}')) {
                continue;
            }
            $uri = preg_replace('/\{[a-z_]+\??\}/', '1', str_replace('{case}', (string) $case, $route->uri()));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->actingAs($stranger)->withSession(['two_factor_confirmed' => true])->json($method, '/' . $uri, []);
                $status = $response->getStatusCode();
                $checked++;
                // 403/404 refuse; 401/419 never apply here; 422 would mean validation ran before authorization.
                if (!in_array($status, [403, 404], true)) {
                    $leaks[] = "$method /$uri -> $status";
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'The sweep should cover the case routes.');
        $this->assertSame([], $leaks, "Case routes reachable by {$stranger->role}:\n" . implode("\n", $leaks));
        $this->assertEquals($before, DB::table('cases')->where('id', $case)->first());
        $this->assertSame($initial, $counts());
    }
}
