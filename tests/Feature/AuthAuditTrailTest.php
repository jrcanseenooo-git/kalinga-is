<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sign-in activity and case views in the audit trail, with the client address (PIA / VA). */
class AuthAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_activity_is_audited_with_the_client_address(): void
    {
        $user = User::factory()->create(['email' => 'audit@kalinga.test', 'password' => bcrypt('Audit-Pass-9!'), 'role' => 'social_worker']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('/login', ['email' => 'audit@kalinga.test', 'password' => 'wrong']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('/login', ['email' => 'nobody@kalinga.test', 'password' => 'wrong']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->post('/login', ['email' => 'audit@kalinga.test', 'password' => 'Audit-Pass-9!']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.failed', 'user_id' => $user->id, 'outcome' => 'failure', 'ip_address' => '203.0.113.7']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.failed', 'user_id' => null, 'outcome' => 'failure']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id, 'ip_address' => '203.0.113.8']);
        // Typed passwords and unknown email addresses are never stored.
        $this->assertFalse(DB::table('audit_logs')->where('changes', 'like', '%nobody@%')->orWhere('changes', 'like', '%wrong%')->exists());
    }

    public function test_opening_a_case_is_logged_once_per_half_hour(): void
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-VIEW', 'name' => 'Synthetic Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $worker = User::factory()->create(['role' => 'social_worker']);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Viewed', 'municipality' => 'Synthetic Town', 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('cases')->insertGetId(['case_number' => 'KLG-VIEW-1', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN',
            'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($worker)->withSession(['two_factor_confirmed' => true])->get(route('cases.show', $case))->assertOk();
        $this->get(route('cases.show', $case))->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'case.viewed')->where('auditable_id', $case)->where('user_id', $worker->id)->count());
        // The case timeline shows activity, not views, and never the staff address.
        $this->get(route('cases.show', $case))->assertInertia(fn ($page) => $page->where('timeline', fn ($rows) => collect($rows)->every(fn ($row) => $row['event'] !== 'case.viewed' && !array_key_exists('ip_address', (array) $row))));
    }

    public function test_audit_rows_cannot_be_changed_or_deleted_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The append-only trigger is PostgreSQL only; verified on the review database.');
        }
        $id = DB::table('audit_logs')->insertGetId(['event' => 'synthetic', 'auditable_type' => 'case', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([fn () => DB::table('audit_logs')->where('id', $id)->update(['event' => 'tampered']), fn () => DB::table('audit_logs')->where('id', $id)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('Audit row was modified.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
