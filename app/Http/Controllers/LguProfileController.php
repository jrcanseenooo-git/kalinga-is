<?php

namespace App\Http\Controllers;

use App\Services\LguAssignmentScope;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * LGU profile and signatories: office identity, logo, the office head who signs
 * reports, and the Child Protection Unit (CPU) composition.
 *
 * Case managers manage the LGUs they are assigned to; system administrators
 * manage any LGU; Field Office users may view LGUs in their province.
 */
class LguProfileController extends Controller
{
    private const LOGO_DISK = 'local';

    public function __construct(private readonly LguAssignmentScope $assignments) {}

    /** LGU ids the user may see. */
    private function visibleLguIds(Request $request): array
    {
        $user = $request->user();

        return match ($user->role) {
            'system_admin' => DB::table('lgu_units')->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'fo_user' => DB::table('lgu_units')->where('active', true)->when($user->province, fn ($q) => $q->where('province', $user->province))->pluck('id')->map(fn ($id) => (int) $id)->all(),
            default => $this->assignments->activeLguIds($user),
        };
    }

    private function viewable(Request $request, int $lgu): void
    {
        $this->authorize('lgu.profile.view');
        abort_unless(in_array($lgu, $this->visibleLguIds($request), true), 403);
    }

    private function manageable(Request $request, int $lgu): void
    {
        $this->authorize('lgu.profile.manage');
        abort_unless(in_array($lgu, $this->visibleLguIds($request), true), 403);
    }

    private function audit(Request $request, string $event, int $lgu, array $changes = []): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'lgu_unit', 'auditable_id' => $lgu,
            'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function saveProfile(int $lgu, array $values): void
    {
        if (DB::table('lgu_profiles')->where('lgu_id', $lgu)->exists()) {
            DB::table('lgu_profiles')->where('lgu_id', $lgu)->update($values + ['updated_at' => now()]);
        } else {
            DB::table('lgu_profiles')->insert($values + ['lgu_id' => $lgu, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function show(Request $request): Response
    {
        $this->authorize('lgu.profile.view');
        $ids = $this->visibleLguIds($request);
        $lgus = DB::table('lgu_units')->whereIn('id', $ids)->orderBy('region')->orderBy('province')->orderBy('name')->get(['id', 'name', 'province', 'region']);
        $selected = (int) $request->integer('lgu');
        if (!in_array($selected, $ids, true)) {
            $selected = (int) ($lgus->first()->id ?? 0);
        }

        $profile = $selected ? DB::table('lgu_profiles')->where('lgu_id', $selected)->first() : null;
        $members = $selected ? DB::table('lgu_cpu_members')->leftJoin('users', 'users.id', '=', 'lgu_cpu_members.user_id')
            ->where('lgu_cpu_members.lgu_id', $selected)->orderByDesc('lgu_cpu_members.active')->orderBy('lgu_cpu_members.sort_order')->orderBy('lgu_cpu_members.id')
            ->get(['lgu_cpu_members.id', 'lgu_cpu_members.name', 'lgu_cpu_members.position', 'lgu_cpu_members.office', 'lgu_cpu_members.cpu_role',
                'lgu_cpu_members.user_id', 'lgu_cpu_members.active', 'users.name as account_name']) : collect();
        $assigned = $selected ? DB::table('user_lgu_assignments')->join('users', 'users.id', '=', 'user_lgu_assignments.user_id')
            ->where('user_lgu_assignments.lgu_id', $selected)->where('user_lgu_assignments.active', true)
            ->where(fn ($q) => $q->whereNull('user_lgu_assignments.effective_to')->orWhere('user_lgu_assignments.effective_to', '>', now()))
            ->where('users.active', true)->orderBy('users.name')->get(['users.id', 'users.name', 'users.position', 'users.role', 'users.cpu_discipline']) : collect();

        return Inertia::render('Lgu/Profile', [
            'lgus' => $lgus,
            'selectedLguId' => $selected ?: null,
            'profile' => $profile ? [
                'office_name' => $profile->office_name, 'office_short_name' => $profile->office_short_name, 'address' => $profile->address,
                'contact_number' => $profile->contact_number, 'email' => $profile->email, 'head_name' => $profile->head_name,
                'head_position' => $profile->head_position, 'head_designation' => $profile->head_designation,
                'has_logo' => (bool) $profile->logo_path, 'updated_at' => $profile->updated_at,
            ] : null,
            'logoUrl' => $profile?->logo_path ? route('lgu-profile.logo', ['lgu' => $selected, 'v' => strtotime((string) $profile->updated_at)]) : null,
            'cpuMembers' => $members,
            'caseManagers' => $assigned->where('role', 'social_worker')->values(),
            'linkableAccounts' => $assigned->where('role', 'cpu_member')->values(),
            'canManage' => $request->user()->hasPermission('lgu.profile.manage') && $selected !== 0,
        ]);
    }

    public function update(Request $request, int $lgu): RedirectResponse
    {
        $this->manageable($request, $lgu);
        $data = $request->validate([
            'office_name' => 'required|string|max:180', 'office_short_name' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255', 'contact_number' => 'nullable|string|max:60', 'email' => 'nullable|email|max:160',
            'head_name' => 'required|string|max:160', 'head_position' => 'required|string|max:160', 'head_designation' => 'nullable|string|max:160',
        ]);
        $this->saveProfile($lgu, $data + ['updated_by' => $request->user()->id]);
        $this->audit($request, 'lgu_profile.updated', $lgu, ['fields' => array_keys($data)]);

        return back()->with('success', 'LGU profile and signatory saved.');
    }

    public function uploadLogo(Request $request, int $lgu): RedirectResponse
    {
        $this->manageable($request, $lgu);
        $request->validate(['logo' => 'required|file|image|mimes:png,jpg,jpeg,webp|max:1024|dimensions:max_width=2000,max_height=2000']);
        $file = $request->file('logo');
        $path = $file->storeAs('lgu-logos', $lgu . '-' . now()->format('YmdHis') . '.' . $file->extension(), self::LOGO_DISK);
        $old = DB::table('lgu_profiles')->where('lgu_id', $lgu)->value('logo_path');
        $this->saveProfile($lgu, ['logo_path' => $path, 'updated_by' => $request->user()->id]);
        if ($old && $old !== $path) {
            Storage::disk(self::LOGO_DISK)->delete($old);
        }
        $this->audit($request, 'lgu_profile.logo_uploaded', $lgu);

        return back()->with('success', 'Logo uploaded. It will appear on generated reports.');
    }

    public function removeLogo(Request $request, int $lgu): RedirectResponse
    {
        $this->manageable($request, $lgu);
        $old = DB::table('lgu_profiles')->where('lgu_id', $lgu)->value('logo_path');
        if ($old) {
            DB::table('lgu_profiles')->where('lgu_id', $lgu)->update(['logo_path' => null, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            Storage::disk(self::LOGO_DISK)->delete($old);
            $this->audit($request, 'lgu_profile.logo_removed', $lgu);
        }

        return back()->with('success', 'Logo removed.');
    }

    /** Streams the logo to signed-in users who may see this LGU or generate its reports. */
    public function logo(Request $request, int $lgu): StreamedResponse
    {
        $allowed = in_array($lgu, $this->visibleLguIds($request), true) && ($request->user()->hasPermission('lgu.profile.view'));
        abort_unless($allowed, 403);
        $path = DB::table('lgu_profiles')->where('lgu_id', $lgu)->value('logo_path');
        abort_unless($path && Storage::disk(self::LOGO_DISK)->exists($path), 404);

        return Storage::disk(self::LOGO_DISK)->response($path, null, ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function memberRules(int $lgu): array
    {
        return [
            'name' => 'required|string|max:160', 'position' => 'required|string|max:160', 'office' => 'nullable|string|max:160',
            'cpu_role' => 'required|string|max:80', 'sort_order' => 'nullable|integer|min:0|max:999', 'active' => 'boolean',
            'user_id' => ['nullable', 'integer', Rule::exists('user_lgu_assignments', 'user_id')->where('lgu_id', $lgu)->where('active', true)],
        ];
    }

    public function storeMember(Request $request, int $lgu): RedirectResponse
    {
        $this->manageable($request, $lgu);
        $data = $request->validate($this->memberRules($lgu));
        $id = DB::table('lgu_cpu_members')->insertGetId($data + ['lgu_id' => $lgu, 'active' => $data['active'] ?? true, 'sort_order' => $data['sort_order'] ?? 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'lgu_cpu_member.added', $lgu, ['member_id' => $id, 'cpu_role' => $data['cpu_role']]);

        return back()->with('success', 'CPU member added.');
    }

    public function updateMember(Request $request, int $lgu, int $member): RedirectResponse
    {
        $this->manageable($request, $lgu);
        abort_unless(DB::table('lgu_cpu_members')->where('id', $member)->where('lgu_id', $lgu)->exists(), 404);
        $data = $request->validate($this->memberRules($lgu));
        DB::table('lgu_cpu_members')->where('id', $member)->update($data + ['updated_at' => now()]);
        $this->audit($request, 'lgu_cpu_member.updated', $lgu, ['member_id' => $member]);

        return back()->with('success', 'CPU member updated.');
    }

    public function destroyMember(Request $request, int $lgu, int $member): RedirectResponse
    {
        $this->manageable($request, $lgu);
        $record = DB::table('lgu_cpu_members')->where('id', $member)->where('lgu_id', $lgu)->first();
        abort_unless($record, 404);
        DB::table('lgu_cpu_members')->where('id', $member)->delete();
        $this->audit($request, 'lgu_cpu_member.removed', $lgu, ['member_id' => $member, 'name' => $record->name]);

        return back()->with('success', 'CPU member removed.');
    }
}
