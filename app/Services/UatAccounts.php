<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Fixed, fictional UAT identities. A display name or email prefix never grants UAT access. */
final class UatAccounts
{
    public const REGIONS = [
        'car' => ['Cordillera Administrative Region (CAR)', 'Mountain Province', 'Paracelis', '1404406000', 1],
        'mim' => ['MIMAROPA Region', 'Palawan', "Brooke's Point", '1705306000', 1],
        'viii' => ['Region VIII (Eastern Visayas)', 'Samar', 'City of Catbalogan', '0806005000', 2],
        'caraga' => ['Region XIII (Caraga)', 'Surigao del Norte', 'Socorro', '1606723000', 1],
    ];

    /** @return list<array<string, mixed>> */
    public static function roster(): array
    {
        $rows = [];
        foreach (self::REGIONS as $group => [$region, $province, $municipality, $code, $itos]) {
            $slots = [
                'cm01' => ['social_worker', null], 'cm02' => ['social_worker', null],
                'cpu-health' => ['cpu_member', 'health_officer'],
                'cpu-bcpc' => ['cpu_member', 'barangay_official'],
                'cpu-lea' => ['cpu_member', 'lea'], 'sup' => ['lgu_head', null], 'fo' => ['fo_user', null],
            ];
            for ($i = 1; $i <= $itos; $i++) {
                $prefix = sprintf('ito%02d-', $i);
                $slots[$prefix . 'cm'] = ['social_worker', null];
                $slots[$prefix . 'health'] = ['cpu_member', 'health_officer'];
                $slots[$prefix . 'sup'] = ['lgu_head', null];
            }
            foreach ($slots as $slot => [$role, $discipline]) {
                $key = $group . '-' . $slot;
                $rows[] = [
                    'uat_key' => $key, 'name' => 'UAT ' . strtoupper(str_replace('-', ' ', $key)),
                    'email' => $key . '@uat.kalinga.test', 'role' => $role, 'cpu_discipline' => $discipline,
                    'region' => $region, 'province' => $province, 'municipality' => $municipality, 'psgc_code' => $code,
                ];
            }
        }

        return $rows;
    }

    public static function usable(User $user): bool
    {
        if (!config('kalinga.uat_enabled') || !$user->active || !$user->uat_key || !$user->uat_expires_at || $user->uat_expires_at->isPast()) {
            return false;
        }
        $row = collect(self::roster())->firstWhere('uat_key', $user->uat_key);
        if (!$row) {
            return false;
        }
        foreach (['email', 'role', 'cpu_discipline', 'region', 'province', 'municipality'] as $field) {
            if ($user->{$field} !== $row[$field]) {
                return false;
            }
        }

        return true;
    }

    /** Creates the entire roster atomically. A repeat or collision never resets existing credentials. */
    public function create(User $actor, CarbonImmutable $expires): array
    {
        abort_unless($actor->active && $actor->role === 'system_admin' && config('kalinga.uat_enabled'), 403);
        if ($expires->isPast() || $expires->greaterThan(now()->addDays(7))) {
            throw ValidationException::withMessages(['expires_at' => 'Choose an expiry in the next seven days.']);
        }

        return DB::transaction(function () use ($actor, $expires) {
            $roster = self::roster();
            if (User::whereIn('email', array_column($roster, 'email'))->orWhereIn('uat_key', array_column($roster, 'uat_key'))->exists()) {
                throw ValidationException::withMessages(['roster' => 'A roster account already exists. Nothing was created or reset.']);
            }
            $credentials = [];
            $lgus = [];
            foreach ($roster as $row) {
                $code = $row['psgc_code'];
                if (!isset($lgus[$code])) {
                    $lgu = DB::table('lgu_units')->where('psgc_code', $code)->first();
                    $samePlace = DB::table('lgu_units')->where('name', $row['municipality'])->where('province', $row['province'])->first();
                    if (($samePlace && (!$lgu || $samePlace->id !== $lgu->id)) || ($lgu && (!$lgu->active || $lgu->name !== $row['municipality'] || $lgu->province !== $row['province'] || $lgu->region !== $row['region']))) {
                        throw ValidationException::withMessages(['roster' => 'An LGU entry needs review for ' . $row['municipality'] . '. Nothing was created.']);
                    }
                    $id = $lgu?->id ?? DB::table('lgu_units')->insertGetId([
                        'psgc_code' => $code, 'name' => $row['municipality'], 'province' => $row['province'], 'region' => $row['region'],
                        'active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!$lgu) {
                        AuditLog::record('lgu.created', 'lgu', $id, ['uat_setup' => true], $actor->id);
                    }
                    $circle = DB::table('lgu_coordination_circles')->where('lgu_id', $id)->first();
                    if ($circle && !$circle->active) {
                        throw ValidationException::withMessages(['roster' => 'The coordination circle needs review. Nothing was created.']);
                    }
                    $circleId = $circle?->id ?? DB::table('lgu_coordination_circles')->insertGetId([
                        'lgu_id' => $id, 'name' => $row['municipality'] . ' CPU Coordination Circle', 'active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $lgus[$code] = [$id, $circleId];
                }
                [$lguId, $circleId] = $lgus[$code];
                $password = Str::password(20, symbols: false);
                $user = new User;
                $user->forceFill(collect($row)->except('psgc_code')->all() + [
                    'password' => $password, 'active' => true, 'account_status' => 'approved',
                    'reviewed_at' => now(), 'reviewed_by' => $actor->id,
                    'uat_expires_at' => $expires->utc(),
                ])->save();
                if ($row['role'] !== 'fo_user') {
                    DB::table('user_lgu_assignments')->insert([
                        'user_id' => $user->id, 'lgu_id' => $lguId, 'active' => true, 'effective_from' => now(),
                        'effective_to' => $expires->utc(), 'assigned_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('lgu_coordination_members')->insert([
                        'circle_id' => $circleId, 'user_id' => $user->id,
                        'membership_role' => ['cpu_member' => 'cpu_member', 'lgu_head' => 'lgu_head'][$row['role']] ?? 'case_manager',
                        'joined_at' => now(), 'configured_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                AuditLog::record('user.uat_created', 'user', $user->id, [
                    'role' => $row['role'], 'lgu_id' => $row['role'] === 'fo_user' ? null : $lguId,
                    'uat_key' => $row['uat_key'], 'expires_at' => $expires->toIso8601String(),
                ], $actor->id);
                $credentials[] = $row + ['password' => $password, 'expires_at' => $expires->toIso8601String()];
            }

            UatCredentialDelivery::save($actor, $credentials);

            return $credentials;
        });
    }
}
