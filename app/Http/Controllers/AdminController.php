<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Services\MonitoringScope;
use App\Services\RolePermissions;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminController extends Controller
{
    private function guard(Request $r): void
    {
        $this->authorize('administer-system');
    }

    /** Roles an account manager may give: administrators any role; Project Managers the field roles only. */
    private function assignableRoles(Request $r): array
    {
        return $r->user()->hasPermission('admin.manage') ? array_keys(RolePermissions::ROLES) : ['social_worker', 'cpu_member', 'lgu_head', 'fo_user'];
    }

    /** Regions the account manager covers; null = all (administrator or national Project Manager). */
    private function managedRegions(Request $r): ?array
    {
        return $r->user()->hasPermission('admin.manage') ? null : app(MonitoringScope::class)->regions($r->user());
    }

    /** True when the account belongs to the manager's regions (by LGU assignment or recorded region). */
    private function accountInScope(Request $r, object $target): bool
    {
        $regions = $this->managedRegions($r);
        if ($regions === null) {
            return true;
        }
        if (!$r->user()->hasPermission('admin.manage') && in_array($target->role, ['system_admin', 'project_manager'], true) && $target->account_status !== 'pending') {
            return false;
        }
        if (in_array($target->region, $regions, true)) {
            return true;
        }

        return DB::table('user_lgu_assignments')->join('lgu_units', 'lgu_units.id', '=', 'user_lgu_assignments.lgu_id')->where('user_lgu_assignments.user_id', $target->id)
            ->where('user_lgu_assignments.active', true)->whereIn('lgu_units.region', $regions)->exists();
    }

    private function lguInScope(Request $r, int $lgu): bool
    {
        $regions = $this->managedRegions($r);

        return $regions === null || DB::table('lgu_units')->where('id', $lgu)->whereIn('region', $regions)->exists();
    }

    private function psgc(): array
    {
        static $dataset = null;
        if ($dataset !== null) {
            return $dataset;
        }$decoded = json_decode((string) file_get_contents(public_path('data/psgc-2q-2026.json')), true);

        return $dataset = is_array($decoded) ? $decoded : [];
    }

    private function psgcMunicipality(string $code): ?array
    {
        foreach ($this->psgc()['regions'] ?? [] as $region) {
            foreach ([['name' => null, 'municipalities' => $region['municipalities'] ?? []], ...($region['provinces'] ?? [])] as $province) {
                foreach ($province['municipalities'] ?? [] as $municipality) {
                    if (($municipality['code'] ?? null) === $code) {
                        return ['name' => $municipality['name'], 'province' => $province['name'], 'region' => $region['name']];
                    }
                }
            }
        }

        return null;
    }

    private function validateJurisdiction(array $data): void
    {
        if (!($data['region'] ?? null) && !($data['province'] ?? null) && !($data['municipality'] ?? null)) {
            return;
        }
        $region = collect($this->psgc()['regions'] ?? [])->firstWhere('name', $data['region'] ?? null);
        if (!$region) {
            throw ValidationException::withMessages(['region' => 'Select a region from the PSGC list.']);
        }
        $provinces = $region['provinces'] ?? [];
        if (($data['province'] ?? null) && !collect($provinces)->firstWhere('name', $data['province'])) {
            throw ValidationException::withMessages(['province' => 'Select a province within the selected region.']);
        }
        if (!($data['municipality'] ?? null)) {
            return;
        }
        $municipalities = ($data['province'] ?? null) ? (collect($provinces)->firstWhere('name', $data['province'])['municipalities'] ?? []) : ($region['municipalities'] ?? []);
        if (!$data['province'] && count($provinces)) {
            throw ValidationException::withMessages(['province' => 'Select the province before selecting a municipality or city.']);
        }
        if (!collect($municipalities)->firstWhere('name', $data['municipality'])) {
            throw ValidationException::withMessages(['municipality' => 'Select a municipality or city within the selected jurisdiction.']);
        }
    }

    private function auditQuery()
    {
        return DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')->latest('audit_logs.created_at')->select(['audit_logs.id', 'audit_logs.event', 'audit_logs.outcome', 'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.created_at', 'audit_logs.ip_address', 'users.name as user_name']);
    }

    public function index(Request $r)
    {
        $this->authorize('users.approve');
        $regions = $this->managedRegions($r);
        $assignments = DB::table('user_lgu_assignments')->where('active', true)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->get(['user_id', 'lgu_id', 'effective_from']);
        $lgus = DB::table('lgu_units')->where('active', true)->when($regions !== null, fn ($q) => $q->whereIn('region', $regions ?: ['-']))->orderBy('name')->get(['id', 'psgc_code', 'name', 'province', 'region']);
        $users = DB::table('users')->select('id', 'name', 'email', 'role', 'cpu_discipline', 'active', 'region', 'region_scope', 'province', 'municipality', 'account_status', 'created_at')->orderBy('name')->get()
            ->filter(fn ($user) => $user->account_status !== 'pending' && $this->accountInScope($r, $user))->values()
            ->map(fn ($user) => (array) $user + ['region_scope' => json_decode($user->region_scope ?? 'null', true)]);
        $pending = DB::table('users')->where('account_status', 'pending')->select('id', 'name', 'email', 'requested_role', 'cpu_discipline', 'region', 'province', 'municipality', 'created_at')->orderBy('created_at')
            ->when($regions !== null, fn ($q) => $q->whereIn('region', $regions ?: ['-']))->get();

        return Inertia::render('Admin/Index', [
            'users' => $users, 'roles' => DB::table('roles')->whereIn('code', $this->assignableRoles($r))->orderBy('name')->get(),
            'lguUnits' => $lgus, 'lguAssignments' => $assignments->whereIn('user_id', $users->pluck('id'))->values(),
            'audits' => $r->user()->hasPermission('audits.view') ? $this->auditQuery()->limit(100)->get() : [],
            'pendingRegistrations' => $pending,
            'registrableRoles' => collect(RegisteredUserController::REGISTRABLE_ROLES)->mapWithKeys(fn (string $c) => [$c => RolePermissions::ROLES[$c][0]])->all(),
            'cpuDisciplines' => RolePermissions::CPU_DISCIPLINES,
            'roleLabels' => collect(RolePermissions::ROLES)->map(fn ($role) => $role[0]),
            'regionOptions' => DB::table('lgu_units')->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
            'scopeLabel' => $regions === null ? 'All regions' : (implode(', ', $regions) ?: 'No region assigned'),
            'canManageSystem' => $r->user()->hasPermission('admin.manage'),
            'uatEnabled' => $r->user()->role === 'system_admin' && (bool) config('kalinga.uat_enabled'),
        ]);
    }

    public function overview(Request $r)
    {
        $this->guard($r);

        return Inertia::render('Admin/Overview', ['metrics' => ['activeUsers' => DB::table('users')->where('active', true)->count(), 'pendingApprovals' => DB::table('users')->where('account_status', 'pending')->count(), 'activeLgus' => DB::table('lgu_units')->where('active', true)->count(), 'recentAccessChanges' => DB::table('audit_logs')->whereIn('event', ['user.access_updated', 'user.lgu_assigned', 'user.registration_approved', 'user.registration_declined'])->where('created_at', '>=', now()->subDays(7))->count()], 'recentGovernanceEvents' => $this->auditQuery()->whereIn('audit_logs.event', ['user.access_updated', 'user.lgu_assigned', 'user.registration_approved', 'user.registration_declined', 'lgu.created'])->limit(8)->get()]);
    }

    public function lgus(Request $r)
    {
        $this->authorize('lgu.registry.view');
        $regions = $r->user()->hasPermission('admin.manage') ? null : app(MonitoringScope::class)->regions($r->user());
        $staff = DB::table('user_lgu_assignments')->join('users', 'users.id', '=', 'user_lgu_assignments.user_id')->where('user_lgu_assignments.active', true)->where('users.active', true)
            ->where(fn ($q) => $q->whereNull('user_lgu_assignments.effective_to')->orWhere('user_lgu_assignments.effective_to', '>', now()))
            ->groupBy('user_lgu_assignments.lgu_id', 'users.role')->select('user_lgu_assignments.lgu_id', 'users.role', DB::raw('count(*) as total'))->get()->groupBy('lgu_id');
        $cases = DB::table('cases')->whereNull('deleted_at')->whereNotNull('lgu_id')->groupBy('lgu_id')
            ->select('lgu_id', DB::raw('count(*) as total'), DB::raw("sum(case when status <> 'CLOSED' then 1 else 0 end) as active"))->get()->keyBy('lgu_id');
        $profiles = DB::table('lgu_profiles')->pluck('lgu_id')->flip();

        return Inertia::render('Admin/Lgus', [
            'lgus' => DB::table('lgu_units as lgu')->leftJoin('lgu_coordination_circles as circles', 'circles.lgu_id', '=', 'lgu.id')
                ->when($regions !== null, fn ($q) => $q->whereIn('lgu.region', $regions ?: ['-']))
                ->select('lgu.id', 'lgu.psgc_code', 'lgu.name', 'lgu.province', 'lgu.region', 'lgu.active', 'circles.id as circle_id')
                ->orderBy('lgu.region')->orderBy('lgu.province')->orderBy('lgu.name')->get()
                ->map(fn ($lgu) => (array) $lgu + [
                    'case_managers' => (int) ($staff->get($lgu->id)?->firstWhere('role', 'social_worker')->total ?? 0),
                    'cpu_members' => (int) ($staff->get($lgu->id)?->firstWhere('role', 'cpu_member')->total ?? 0),
                    'lgu_heads' => (int) ($staff->get($lgu->id)?->firstWhere('role', 'lgu_head')->total ?? 0),
                    'cases' => (int) ($cases->get($lgu->id)->total ?? 0), 'active_cases' => (int) ($cases->get($lgu->id)->active ?? 0),
                    'has_profile' => $profiles->has($lgu->id),
                ]),
            'canManage' => $r->user()->hasPermission('lgu.registry.manage'),
            'scopeLabel' => $regions === null ? 'All regions' : (implode(', ', $regions) ?: 'No region assigned'),
        ]);
    }

    /** The earlier form builder became Administration > Form options. */
    public function forms(Request $r)
    {
        $this->guard($r);

        return redirect()->route('admin.options');
    }

    public function updateUser(Request $r, int $user)
    {
        $this->authorize('users.approve');
        $d = $r->validate(['role' => 'required|exists:roles,code|in:' . implode(',', $this->assignableRoles($r)), 'active' => 'required|boolean', 'cpu_discipline' => 'nullable|string|in:' . implode(',', array_keys(RolePermissions::CPU_DISCIPLINES)), 'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120',
            'region_scope' => 'nullable|array', 'region_scope.*' => 'string|max:120']);
        $before = DB::table('users')->find($user);
        abort_unless($before && $this->accountInScope($r, $before), 404);
        if (in_array($d['role'], ['social_worker', 'cpu_member', 'lgu_head', 'project_manager', 'system_admin'], true)) {
            // These roles are placed by LGU assignment (or region scope), not by a single address.
            $d['region'] = $d['province'] = $d['municipality'] = null;
        } else {
            $d['province'] = $d['municipality'] = null;
            abort_unless(!empty($d['region']), 422, 'Select the region of the Field Office user.');
            $this->validateJurisdiction($d);
            abort_unless($this->managedRegions($r) === null || in_array($d['region'], $this->managedRegions($r), true), 403, 'You can only manage accounts in your regions.');
        }
        $scope = null;
        if ($d['role'] === 'project_manager') {
            abort_unless($r->user()->hasPermission('admin.manage'), 403);
            $scope = array_values(array_unique($d['region_scope'] ?? []));
            abort_if($scope === [], 422, 'Choose the regions this Project Manager monitors, or All regions.');
        }
        abort_unless($user !== $r->user()->id || $d['active'], 422, 'You cannot deactivate your own account.');
        DB::transaction(function () use ($r, $user, $d, $before, $scope) {
            DB::table('users')->where('id', $user)->update(['role' => $d['role'], 'active' => $d['active'], 'cpu_discipline' => $d['role'] === 'cpu_member' ? ($d['cpu_discipline'] ?? null) : null, 'region' => $d['region'] ?? null, 'region_scope' => $scope === null ? null : json_encode($scope), 'province' => $d['province'] ?? null, 'municipality' => $d['municipality'] ?? null, 'access_revoked_at' => $d['active'] ? null : now(), 'updated_at' => now()]);
            if (!$d['active']) {
                DB::table('offline_devices')->where('user_id', $user)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $this->auditAccessChange($r->user()->id, $user, $before, $d);
        });

        return back()->with('success', 'User access and jurisdiction updated.');
    }

    /** One audit entry per kind of change, with the old and new value (no names or emails). */
    private function auditAccessChange(int $actor, int $user, object $before, array $d): void
    {
        $rows = [];
        $base = ['user_id' => $actor, 'auditable_type' => 'user', 'auditable_id' => $user];
        if ($before->role !== $d['role']) {
            $rows[] = $base + ['event' => 'user.role_assigned', 'changes' => json_encode(['from' => $before->role, 'to' => $d['role']]), 'old_value' => $before->role, 'new_value' => $d['role']];
        }
        if ((bool) $before->active !== (bool) $d['active']) {
            $rows[] = $base + ['event' => $d['active'] ? 'user.activated' : 'user.deactivated', 'changes' => json_encode(['from' => (bool) $before->active ? 'active' : 'inactive', 'to' => $d['active'] ? 'active' : 'inactive']),
                'old_value' => (bool) $before->active ? 'Active' : 'Inactive', 'new_value' => $d['active'] ? 'Active' : 'Inactive'];
        }
        $scope = fn ($region, $province, $municipality) => implode(' / ', array_filter([$region, $province, $municipality])) ?: 'None';
        $oldScope = $scope($before->region, $before->province, $before->municipality);
        $newScope = $scope($d['region'] ?? null, $d['province'] ?? null, $d['municipality'] ?? null);
        if ($oldScope !== $newScope) {
            $rows[] = $base + ['event' => 'user.scope_updated', 'changes' => json_encode(['region' => $d['region'] ?? null, 'province' => $d['province'] ?? null, 'municipality' => $d['municipality'] ?? null]), 'old_value' => $oldScope, 'new_value' => $newScope];
        }
        if (($before->cpu_discipline ?? null) !== ($d['role'] === 'cpu_member' ? ($d['cpu_discipline'] ?? null) : null)) {
            $rows[] = $base + ['event' => 'user.access_updated', 'description' => 'CPU discipline updated', 'changes' => json_encode(['cpu_discipline' => $d['cpu_discipline'] ?? null]), 'old_value' => $before->cpu_discipline, 'new_value' => $d['cpu_discipline'] ?? null];
        }
        if ($rows === []) {
            $rows[] = $base + ['event' => 'user.access_updated', 'description' => 'Account access saved without changes', 'changes' => json_encode(['role' => $d['role']]), 'old_value' => null, 'new_value' => null];
        }
        foreach ($rows as $row) {
            AuditLog::insert($row);
        }
    }

    public function destroyUser(Request $r, int $user)
    {
        $this->guard($r);
        $d = $r->validate(['confirmation' => 'required|string']);
        $target = DB::table('users')->find($user);
        abort_unless($target, 404);
        if ($user === $r->user()->id) {
            return back()->withErrors(['delete' => 'You cannot delete your own account.']);
        }if (!hash_equals($target->email, trim($d['confirmation']))) {
            return back()->withErrors(['confirmation' => 'Type the exact account email to confirm deletion.']);
        }if ($target->role === 'system_admin' && DB::table('users')->where('role', 'system_admin')->where('active', true)->where('id', '!=', $user)->count() === 0) {
            return back()->withErrors(['delete' => 'The last active System Administrator cannot be deleted.']);
        }$inUse = DB::table('cases')->where('assigned_to', $user)->exists() || DB::table('audit_logs')->where('user_id', $user)->exists() || DB::table('user_lgu_assignments')->where('user_id', $user)->exists() || DB::table('offline_devices')->where('user_id', $user)->exists();
        if ($inUse) {
            return back()->withErrors(['delete' => 'This account has operational history and cannot be deleted. Deactivate it instead.']);
        }DB::table('users')->where('id', $user)->delete();
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'user.deleted', 'auditable_type' => 'user', 'auditable_id' => $user, 'changes' => json_encode(['email' => $target->email]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Account deleted.');
    }

    public function storeLgu(Request $r)
    {
        $this->authorize('lgu.registry.manage');
        $d = $r->validate(['psgc_code' => 'required|string|max:20|unique:lgu_units,psgc_code', 'name' => 'required|string|max:160', 'province' => 'nullable|string|max:120', 'region' => 'nullable|string|max:120']);
        $location = $this->psgcMunicipality($d['psgc_code']);
        if (!$location) {
            throw ValidationException::withMessages(['psgc_code' => 'Select a municipality or city PSGC code from the approved reference data.']);
        }$d = array_merge($d, $location);
        abort_unless($this->managedRegions($r) === null || in_array($d['region'], $this->managedRegions($r), true), 403, 'You can only add LGUs in your regions.');
        $id = DB::transaction(function () use ($d, $r) {
            $id = DB::table('lgu_units')->insertGetId($d + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('lgu_coordination_circles')->insert(['lgu_id' => $id, 'name' => $d['name'] . ' CPU Coordination Circle', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'lgu.created', 'auditable_type' => 'lgu_unit', 'auditable_id' => $id, 'changes' => json_encode(['psgc_code' => $d['psgc_code']]), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        return back()->with('success', 'LGU reference and its CPU coordination circle added.');
    }

    public function destroyLgu(Request $r, int $lgu)
    {
        $this->authorize('lgu.registry.manage');
        abort_unless($this->lguInScope($r, $lgu), 404);
        $d = $r->validate(['confirmation' => 'required|string']);
        $record = DB::table('lgu_units')->find($lgu);
        abort_unless($record, 404);
        if (!hash_equals($record->name, trim($d['confirmation']))) {
            return back()->withErrors(['confirmation' => 'Type the exact LGU name to confirm removal.']);
        }$inUse = DB::table('user_lgu_assignments')->where('lgu_id', $lgu)->exists() || DB::table('cases')->where('lgu_id', $lgu)->exists() || DB::table('reports')->where('lgu_id', $lgu)->exists() || DB::table('lgu_coordination_messages')->whereIn('circle_id', DB::table('lgu_coordination_circles')->where('lgu_id', $lgu)->select('id'))->exists();
        if ($inUse) {
            return back()->withErrors(['remove' => 'This LGU has operational records and cannot be removed.']);
        }DB::transaction(function () use ($r, $lgu, $record) {
            DB::table('lgu_coordination_circles')->where('lgu_id', $lgu)->delete();
            DB::table('lgu_units')->where('id', $lgu)->delete();
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'lgu.removed', 'auditable_type' => 'lgu_unit', 'auditable_id' => $lgu, 'changes' => json_encode(['name' => $record->name, 'psgc_code' => $record->psgc_code]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'LGU removed.');
    }

    public function approveRegistration(Request $r, int $user)
    {
        $this->authorize('users.approve');
        $d = $r->validate(['role' => 'required|string|in:' . implode(',', $this->assignableRoles($r))]);
        $target = DB::table('users')->where('id', $user)->where('account_status', 'pending')->first();
        abort_unless($target && $this->accountInScope($r, $target), 404, 'Registration not found or already reviewed.');
        DB::transaction(function () use ($r, $user, $d, $target) {
            DB::table('users')->where('id', $user)->update(['role' => $d['role'], 'active' => true, 'account_status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $r->user()->id, 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'user.registration_approved', 'auditable_type' => 'user', 'auditable_id' => $user, 'changes' => json_encode(['role' => $d['role'], 'requested_role' => $target->requested_role]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Registration approved. ' . $target->name . ' can now sign in.');
    }

    public function declineRegistration(Request $r, int $user)
    {
        $this->authorize('users.approve');
        $target = DB::table('users')->where('id', $user)->where('account_status', 'pending')->first();
        abort_unless($target && $this->accountInScope($r, $target), 404, 'Registration not found or already reviewed.');
        DB::transaction(function () use ($r, $user, $target) {
            DB::table('users')->where('id', $user)->update(['account_status' => 'declined', 'reviewed_at' => now(), 'reviewed_by' => $r->user()->id, 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'user.registration_declined', 'auditable_type' => 'user', 'auditable_id' => $user, 'changes' => json_encode(['requested_role' => $target->requested_role]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Registration declined for ' . $target->name . '.');
    }

    public function assignLgu(Request $r, int $user)
    {
        $this->authorize('users.approve');
        $d = $r->validate(['lgu_id' => 'required|exists:lgu_units,id', 'effective_from' => 'required|date']);
        $account = DB::table('users')->find($user);
        abort_unless($account && $this->accountInScope($r, $account) && $this->lguInScope($r, (int) $d['lgu_id']), 404);
        DB::transaction(function () use ($r, $user, $d) {
            DB::table('user_lgu_assignments')->where('user_id', $user)->where('active', true)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->update(['active' => false, 'effective_to' => now(), 'updated_at' => now()]);
            $oldCircles = DB::table('lgu_coordination_circles')->where('lgu_id', '!=', $d['lgu_id'])->pluck('id');
            DB::table('lgu_coordination_members')->where('user_id', $user)->whereNull('left_at')->whereIn('circle_id', $oldCircles)->update(['left_at' => now(), 'updated_at' => now()]);
            DB::table('user_lgu_assignments')->insert(['user_id' => $user, 'lgu_id' => $d['lgu_id'], 'effective_from' => $d['effective_from'], 'active' => true, 'assigned_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $target = DB::table('users')->find($user);
            $circle = DB::table('lgu_coordination_circles')->where('lgu_id', $d['lgu_id'])->value('id');
            if ($target && $circle && in_array($target->role, ['social_worker', 'cpu_member', 'lgu_head'], true)) {
                DB::table('lgu_coordination_members')->updateOrInsert(['circle_id' => $circle, 'user_id' => $user], ['membership_role' => ['cpu_member' => 'cpu_member', 'lgu_head' => 'lgu_head'][$target->role] ?? 'case_manager', 'joined_at' => now(), 'left_at' => null, 'configured_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'user.lgu_assigned', 'auditable_type' => 'user', 'auditable_id' => $user, 'changes' => json_encode(['lgu_id' => $d['lgu_id'], 'effective_from' => $d['effective_from']]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'LGU assignment updated; previous assignment retained as history.');
    }
}
