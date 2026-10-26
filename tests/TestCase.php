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
        $this->reconcileLegacyReviewer($user);

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

    /** be() is used directly by some tests; it only reconciles legacy reviewer fixtures. */
    public function be(Authenticatable $user, $guard = null): static
    {
        $this->reconcileLegacyReviewer($user);

        return parent::be($user, $guard);
    }

    private function reconcileLegacyReviewer(Authenticatable $user): void
    {
        // Legacy tests represented the second, reviewing person either as an
        // unassigned social_worker with the case municipality or as a province
        // Field Office user. Reviews now belong to the LGU Head, so reconcile
        // those fixtures to an LGU Head assigned to the LGUs of those cases.
        if ($user instanceof User && $user->account_status !== 'fixture_unassigned') {
            $legacyReviewer = $user->role === 'social_worker' && trim((string) $user->municipality) !== ''
                && !DB::table('cases')->where('assigned_to', $user->id)->exists();
            $legacyFieldOffice = $user->role === 'fo_user' && trim((string) $user->province) !== '' && trim((string) $user->region) === '';
            if ($legacyReviewer || $legacyFieldOffice) {
                $column = $legacyReviewer ? 'clients.municipality' : 'clients.province';
                $value = trim((string) ($legacyReviewer ? $user->municipality : $user->province));
                $cases = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->where($column, $value)
                    ->get(['cases.id', 'cases.lgu_id', 'cases.assigned_to', 'clients.municipality', 'clients.province', 'clients.region']);
                if ($cases->isNotEmpty()) {
                    $user->forceFill(['role' => 'lgu_head', 'municipality' => null, 'province' => null])->save();
                    foreach ($cases as $case) {
                        $lgu = $case->lgu_id ?: $this->fixtureLgu((string) $case->municipality, (string) $case->province, (string) ($case->region ?: 'CAR'));
                        if (!$case->lgu_id) {
                            DB::table('cases')->where('id', $case->id)->update(['lgu_id' => $lgu]);
                        }
                        if (!DB::table('user_lgu_assignments')->where('user_id', $user->id)->where('lgu_id', $lgu)->where('active', true)->exists()) {
                            DB::table('user_lgu_assignments')->insert(['user_id' => $user->id, 'lgu_id' => $lgu, 'effective_from' => now()->subDay(), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }
            }
        }
    }

    /** The synthetic LGU used by legacy fixtures for a municipality (one per place). */
    protected function fixtureLgu(string $municipality, string $province, string $region): int
    {
        $municipality = trim($municipality) ?: 'Tabuk City';
        $province = trim($province) ?: 'Kalinga';
        $region = trim($region) ?: 'CAR';
        $code = 'TEST-' . substr(hash('sha256', $region . '|' . $province . '|' . $municipality), 0, 14);

        return (int) (DB::table('lgu_units')->where('psgc_code', $code)->value('id') ?: DB::table('lgu_units')->insertGetId([
            'psgc_code' => $code, 'name' => $municipality, 'province' => $province,
            'region' => $region, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
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
        $lgu = $this->fixtureLgu($municipality, $province, $region);

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

    /** No audit row records a data change (refusals and errors are audited separately). */
    protected function assertNoDataChangeAudited(): void
    {
        $rows = DB::table('audit_logs')->whereNotIn('log_type', ['access_permission', 'security', 'system_error'])->pluck('event')->all();
        $this->assertSame([], $rows, 'Unexpected audit rows for a refused or rolled back change.');
    }

    /** No audit row anywhere contains this text (narratives must never reach the audit trail). */
    protected function assertAuditNeverContains(string $text): void
    {
        $this->assertFalse(DB::table('audit_logs')->pluck('changes')->contains(fn ($value) => str_contains((string) $value, $text)), "Audit trail contains \"$text\".");
    }
}
