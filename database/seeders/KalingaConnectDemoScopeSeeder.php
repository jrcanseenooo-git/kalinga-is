<?php

namespace Database\Seeders;

use App\Support\AuditLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Correct the broad historical demo assignments, preserving their history. */
class KalingaConnectDemoScopeSeeder extends Seeder
{
    /** Demo account ids, when the caller knows them (online demo accounts use real addresses). */
    private ?array $userIds = null;

    public function forUsers(array $ids): static
    {
        $this->userIds = array_values(array_filter($ids));

        return $this;
    }

    /** Local and testing, or a server explicitly running the fictional demo (DEMO_SEED=true). */
    private function allowed(): bool
    {
        return app()->environment(['local', 'testing']) || filter_var(env('DEMO_SEED', false), FILTER_VALIDATE_BOOL);
    }

    public function run(): void
    {
        if (!$this->allowed()) {
            throw new \RuntimeException('Demo scope repair is restricted to local and testing environments or DEMO_SEED=true.');
        }
        DB::transaction(function () {
            $lgus = DB::table('lgu_units')->whereIn('psgc_code', array_map(
                fn ($index) => 'DEMO-LGU-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(2, 8)
            ))->pluck('id');
            $users = $this->userIds !== null ? collect($this->userIds) : DB::table('users')->whereIn('email', ['worker@kalinga.test', 'supervisor@kalinga.test', 'cpu@kalinga.test'])->pluck('id');
            foreach ($users as $user) {
                $assignments = DB::table('user_lgu_assignments')->where('user_id', $user)->whereIn('lgu_id', $lgus)->where('active', true);
                $ids = (clone $assignments)->pluck('id');
                $memberships = DB::table('lgu_coordination_members')->where('user_id', $user)->whereNull('left_at')
                    ->whereIn('circle_id', DB::table('lgu_coordination_circles')->whereIn('lgu_id', $lgus)->select('id'));
                $memberIds = (clone $memberships)->pluck('id');
                $assignments->update(['active' => false, 'effective_to' => now(), 'updated_at' => now()]);
                $memberships->update(['left_at' => now(), 'updated_at' => now()]);
                if ($ids->isNotEmpty() || $memberIds->isNotEmpty()) {
                    AuditLog::record('demo.cpu_chat.scope_corrected', 'user', $user,
                        ['assignment_ids' => $ids->all(), 'membership_ids' => $memberIds->all()]);
                }
            }
        });
    }
}
