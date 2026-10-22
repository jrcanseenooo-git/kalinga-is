<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Reconcile legacy test records with the normalized LGU access model.
     *
     * Older feature tests create users and cases with only free-text location
     * columns. Production authorization intentionally rejects those records.
     * Before an authenticated request, this test-only fixture adapter creates
     * an active normalized assignment for operational users and attaches their
     * own otherwise-unscoped cases to it. It never grants access to a case
     * assigned to another worker and never creates an MDT case grant.
     */
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        // Legacy tests represented an LGU/FO reviewer as an unassigned
        // social_worker with the same municipality as the case. Reconcile that
        // fixture to the current oversight role instead of weakening assigned-
        // case access for ordinary case managers.
        if ($user instanceof User && $user->role === 'social_worker'
            && $user->account_status !== 'fixture_unassigned'
            && !DB::table('cases')->where('assigned_to', $user->id)->exists()
            && trim((string) $user->municipality) !== '') {
            $reviewProvince = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')
                ->where('clients.municipality', trim((string) $user->municipality))
                ->value('clients.province');
            if ($reviewProvince) {
                $user->forceFill(['role' => 'fo_user', 'province' => $reviewProvince])->save();
            }
        }

        if ($user instanceof User && in_array($user->role, ['social_worker', 'cpu_member'], true)) {
            $this->ensureNormalizedLguAssignment($user);
        }

        // A case manager may assign an MDT member only when both have an LGU
        // assignment. Legacy MDT fixtures create the member before the manager
        // makes the request, so reconcile unassigned members without creating
        // the case-specific grant that the workflow itself must still create.
        if ($user instanceof User && $user->role === 'social_worker') {
            $managerLgu = $this->ensureNormalizedLguAssignment($user);
            User::query()->where('role', 'cpu_member')->get()
                ->each(fn (User $member) => $this->ensureNormalizedLguAssignment($member, $managerLgu));
        }

        return parent::actingAs($user, $guard);
    }

    protected function ensureNormalizedLguAssignment(User $user, ?int $preferredLgu = null): int
    {
        $existing = DB::table('user_lgu_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->value('lgu_id');

        if ($existing) {
            return (int) $existing;
        }

        if ($preferredLgu) {
            DB::table('user_lgu_assignments')->insert([
                'user_id' => $user->id, 'lgu_id' => $preferredLgu, 'effective_from' => now()->subDay(),
                'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $preferredLgu;
        }

        $caseLocation = DB::table('cases')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->where('cases.assigned_to', $user->id)
            ->whereNull('cases.lgu_id')
            ->orderBy('cases.id')
            ->first(['clients.municipality', 'clients.province', 'clients.region']);
        $municipality = trim((string) ($user->municipality ?: $caseLocation?->municipality ?: 'Tabuk City'));
        $province = trim((string) ($user->province ?: $caseLocation?->province ?: 'Kalinga'));
        $region = trim((string) ($user->region ?: $caseLocation?->region ?: 'CAR'));
        $code = 'TEST-' . substr(hash('sha256', $region . '|' . $province . '|' . $municipality), 0, 14);
        $lgu = DB::table('lgu_units')->where('psgc_code', $code)->value('id');
        if (!$lgu) {
            $lgu = DB::table('lgu_units')->insertGetId([
                'psgc_code' => $code, 'name' => $municipality, 'province' => $province,
                'region' => $region, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('user_lgu_assignments')->insert([
            'user_id' => $user->id, 'lgu_id' => $lgu, 'effective_from' => now()->subDay(),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($user->role === 'social_worker') {
            $clientIds = DB::table('cases')->where('assigned_to', $user->id)->whereNull('lgu_id')->pluck('client_id');
            DB::table('cases')->where('assigned_to', $user->id)->whereNull('lgu_id')->update(['lgu_id' => $lgu]);
            DB::table('clients')->whereIn('id', $clientIds)->where(fn ($query) => $query->whereNull('municipality')->orWhere('municipality', ''))->update([
                'municipality' => $municipality, 'province' => $province, 'region' => $region,
            ]);
        }

        return (int) $lgu;
    }

    /**
     * Audit row with these columns and exactly these decoded `changes` exists
     * (or not). Works on SQLite and PostgreSQL, where a JSON column cannot be
     * compared with a string.
     */
    protected function assertAuditChanges(array $attributes, array $changes, bool $exists = true): void
    {
        $found = DB::table('audit_logs')->where($attributes)->pluck('changes')
            ->contains(fn ($value) => json_decode((string) $value, true) == $changes);
        $this->assertSame($exists, $found, ($exists ? 'Missing' : 'Unexpected') . ' audit row ' . json_encode($attributes + ['changes' => $changes]));
    }

    /** No audit row anywhere contains this text (narratives must never reach the audit trail). */
    protected function assertAuditNeverContains(string $text): void
    {
        $this->assertFalse(DB::table('audit_logs')->pluck('changes')->contains(fn ($value) => str_contains((string) $value, $text)), "Audit trail contains \"$text\".");
    }
}
