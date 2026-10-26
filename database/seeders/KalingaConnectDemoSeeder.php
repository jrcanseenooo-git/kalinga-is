<?php

namespace Database\Seeders;

use App\Support\AuditLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Add missing demo circles without resetting casework, access or conversations. */
class KalingaConnectDemoSeeder extends Seeder
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
            throw new \RuntimeException('Connect demo setup is restricted to local and testing environments or DEMO_SEED=true.');
        }

        DB::transaction(function () {
            $now = now();
            $users = DB::table('users')->when(
                $this->userIds !== null,
                fn ($query) => $query->whereIn('id', $this->userIds),
                fn ($query) => $query->whereIn('email', ['worker@kalinga.test', 'supervisor@kalinga.test', 'cpu@kalinga.test']),
            )->where('active', true)->whereIn('role', ['social_worker', 'lgu_head', 'cpu_member'])->get();

            foreach ($users as $user) {
                $lgus = DB::table('user_lgu_assignments as assignment')
                    ->join('lgu_units as lgu', 'lgu.id', '=', 'assignment.lgu_id')
                    ->where('assignment.user_id', $user->id)->where('assignment.active', true)
                    ->where('assignment.effective_from', '<=', $now)
                    ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', $now))
                    ->where('lgu.active', true)->whereIn('lgu.psgc_code', array_map(
                        fn ($index) => 'DEMO-LGU-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), range(1, 8)
                    ))->select('lgu.id', 'lgu.name')->distinct()->get();

                foreach ($lgus as $lgu) {
                    $circle = DB::table('lgu_coordination_circles')->where('lgu_id', $lgu->id)->first();
                    if (!$circle) {
                        $id = DB::table('lgu_coordination_circles')->insertGetId([
                            'lgu_id' => $lgu->id, 'name' => $lgu->name . ' CPU Coordination Circle',
                            'active' => true, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        AuditLog::insert(['event' => 'demo.cpu_chat.circle_created', 'auditable_type' => 'lgu_coordination_circle', 'auditable_id' => $id, 'changes' => json_encode(['lgu_id' => $lgu->id])]);
                        $circle = DB::table('lgu_coordination_circles')->find($id);
                    }
                    // Preserve disabled circles and deliberately ended memberships.
                    if (!$circle->active || DB::table('lgu_coordination_members')->where('circle_id', $circle->id)->where('user_id', $user->id)->exists()) {
                        continue;
                    }
                    DB::table('lgu_coordination_members')->insert([
                        'circle_id' => $circle->id, 'user_id' => $user->id,
                        'membership_role' => ['cpu_member' => 'cpu_member', 'lgu_head' => 'lgu_head'][$user->role] ?? 'case_manager',
                        'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    AuditLog::insert(['event' => 'demo.cpu_chat.member_configured', 'auditable_type' => 'user', 'auditable_id' => $user->id, 'changes' => json_encode(['circle_id' => $circle->id])]);
                }
            }
        });
    }
}
