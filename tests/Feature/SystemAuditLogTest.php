<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuditCatalogue;
use App\Support\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** System Audit Logs: 14 types, recommended fields, filters, export, read-only (QA / PIA). */
class SystemAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'system_admin']);
    }

    private function caseFor(User $worker): int
    {
        $lgu = DB::table('lgu_units')->insertGetId(['psgc_code' => 'TEST-AUD', 'name' => 'Synthetic Town', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_lgu_assignments')->insert(['user_id' => $worker->id, 'lgu_id' => $lgu, 'active' => true, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $client = DB::table('clients')->insertGetId(['first_name' => 'Synthetic', 'last_name' => 'Client', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cases')->insertGetId(['case_number' => 'CASE-2026-001', 'client_id' => $client, 'lgu_id' => $lgu, 'assigned_to' => $worker->id, 'status' => 'OPEN',
            'priority' => 'normal', 'category' => 'CEFMU', 'opened_at' => now(), 'offline_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_every_event_is_classified_into_one_of_the_fourteen_types(): void
    {
        $this->assertCount(14, AuditCatalogue::TYPES);
        foreach (['auth.login' => 'authentication', 'user.deactivated' => 'user_management', 'user.role_assigned' => 'access_permission', 'access.denied' => 'access_permission',
            'case.created' => 'data_creation', 'case.updated' => 'data_modification', 'case.archived' => 'data_deletion', 'case.viewed' => 'data_access',
            'case.transitioned' => 'workflow_status', 'referral.created' => 'workflow_status', 'document.downloaded' => 'document_file',
            'case_report.generated' => 'report_generation', 'analytics.exported' => 'report_generation', 'lgu_profile.updated' => 'system_configuration',
            'auth.lockout' => 'security', 'system.database_error' => 'system_error', 'offline.sync' => 'integration_sync',
            'conference.some_new_thing_created' => 'data_creation', 'totally.unknown' => 'data_modification'] as $event => $type) {
            $this->assertSame($type, AuditCatalogue::classify($event)['type'], $event);
            $this->assertArrayHasKey(AuditCatalogue::classify($event)['type'], AuditCatalogue::TYPES);
        }
        $this->assertSame('AUD-000125', AuditCatalogue::logId(125));
    }

    public function test_entries_carry_role_record_id_old_and_new_value_without_secrets(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $case = $this->caseFor($worker);
        $this->actingAs($worker);

        AuditLog::record('case.transitioned', 'case', $case, ['from' => 'OPEN', 'to' => 'IN_PROGRESS', 'password' => 'Secret-123', 'nested' => ['api_token' => 'abc']]);

        $row = DB::table('audit_logs')->where('event', 'case.transitioned')->first();
        $this->assertSame('social_worker', $row->user_role);
        $this->assertSame('workflow_status', $row->log_type);
        $this->assertSame('Case Management', $row->module);
        $this->assertSame('UPDATE', $row->action);
        $this->assertSame('CASE-2026-001', $row->record_ref);
        $this->assertSame('OPEN', $row->old_value);
        $this->assertSame('IN_PROGRESS', $row->new_value);
        $this->assertSame('success', $row->outcome);
        $this->assertStringNotContainsString('Secret-123', $row->changes);
        $this->assertStringNotContainsString('abc', $row->changes);
    }

    public function test_user_management_changes_are_separate_entries_with_old_and_new_values(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'cpu_member', 'active' => true]);

        $this->actingAs($admin)->withSession(['two_factor_confirmed' => true])
            ->patch(route('admin.users.update', $user), ['role' => 'social_worker', 'active' => false])->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['event' => 'user.role_assigned', 'auditable_id' => $user->id, 'old_value' => 'cpu_member', 'new_value' => 'social_worker', 'log_type' => 'access_permission']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'auditable_id' => $user->id, 'old_value' => 'Active', 'new_value' => 'Inactive', 'log_type' => 'user_management']);
    }

    public function test_access_denied_is_logged_once_per_minute_and_repeated_denials_raise_a_security_alert(): void
    {
        $worker = User::factory()->create(['role' => 'social_worker']);
        $this->actingAs($worker)->withSession(['two_factor_confirmed' => true]);

        $this->get(route('admin.audits'))->assertForbidden();
        $this->get(route('admin.audits'))->assertForbidden();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'access.denied')->where('user_id', $worker->id)->count());
        $denied = DB::table('audit_logs')->where('event', 'access.denied')->first();
        $this->assertSame('denied', $denied->outcome);
        $this->assertSame('admin.audits', $denied->record_ref);

        foreach (['admin.overview', 'admin.index', 'admin.forms', 'admin.lgus.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'security.repeated_access_denied')->where('user_id', $worker->id)->count());
        $this->assertSame('security', DB::table('audit_logs')->where('event', 'security.repeated_access_denied')->value('log_type'));
    }

    public function test_administrator_can_filter_search_and_export_but_others_cannot(): void
    {
        $admin = $this->admin();
        $worker = User::factory()->create(['role' => 'social_worker', 'name' => 'Juan Dela Cruz']);
        $case = $this->caseFor($worker);
        AuditLog::record('case.viewed', 'case', $case, [], $worker->id);
        AuditLog::record('analytics.exported', 'analytics', null, [], $admin->id);
        $viewed = DB::table('audit_logs')->where('event', 'case.viewed')->value('id');

        $this->actingAs($admin)->withSession(['two_factor_confirmed' => true]);
        $this->get(route('admin.audits', ['type' => 'data_access']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Audits')->where('audits.total', 1)->where('audits.data.0.record', 'CASE-2026-001')
                ->where('audits.data.0.user', 'Juan Dela Cruz')->where('audits.data.0.role', 'Case Manager')->where('audits.data.0.type', 'Data Access'));
        $this->get(route('admin.audits', ['q' => 'juan']))->assertInertia(fn ($page) => $page->where('audits.total', 1));
        $this->get(route('admin.audits', ['record' => AuditCatalogue::logId($viewed)]))->assertInertia(fn ($page) => $page->where('audits.total', 1)->where('audits.data.0.log_id', AuditCatalogue::logId($viewed)));
        $this->get(route('admin.audits', ['role' => 'system_admin', 'module' => 'Analytics', 'action' => 'EXPORT', 'status' => 'success']))->assertInertia(fn ($page) => $page->where('audits.total', 1));
        $this->get(route('admin.audits', ['from' => now()->addDays(2)->toDateString()]))->assertInertia(fn ($page) => $page->where('audits.total', 0));

        $csv = $this->get(route('admin.audits.export', ['format' => 'csv', 'type' => 'data_access']));
        $csv->assertOk();
        $this->assertStringContainsString('Log ID,', str_replace('"', '', $csv->getContent()));
        $this->assertStringContainsString('CASE-2026-001', $csv->getContent());
        $xlsx = $this->get(route('admin.audits.export', ['format' => 'xlsx']));
        $xlsx->assertOk();
        $this->assertStringStartsWith('PK', file_get_contents($xlsx->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseHas('audit_logs', ['event' => 'audit.exported', 'user_id' => $admin->id, 'log_type' => 'report_generation']);

        $this->actingAs($worker)->get(route('admin.audits.export'))->assertForbidden();
    }

    public function test_there_is_no_way_to_edit_or_delete_an_entry_through_the_application(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit'));
        $this->assertTrue($routes->every(fn ($route) => $route->methods() === ['GET', 'HEAD']), 'Audit routes must be read-only.');
    }
}
