<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Short-lived encrypted delivery escrow, committed with the account changes. */
final class UatCredentialDelivery
{
    private static function key(User $actor): string
    {
        return 'kalinga-uat-delivery:' . $actor->id;
    }

    public static function save(User $actor, array $accounts): void
    {
        self::purgeExpired();
        DB::table('cache')->updateOrInsert(['key' => self::key($actor)], [
            'value' => Crypt::encryptString(json_encode(['accounts' => $accounts], JSON_THROW_ON_ERROR)),
            'expiration' => min(now()->addMinutes(30)->timestamp, CarbonImmutable::parse($accounts[0]['expires_at'])->timestamp),
        ]);
    }

    public static function available(User $actor): bool
    {
        return DB::table('cache')->where('key', self::key($actor))->where('expiration', '>', now()->timestamp)->exists();
    }

    public static function read(User $actor): array
    {
        self::purgeExpired();
        $row = DB::table('cache')->where('key', self::key($actor))->where('expiration', '>', now()->timestamp)->first();
        abort_unless($row, 404);

        return json_decode(Crypt::decryptString($row->value), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function forget(User $actor): void
    {
        DB::table('cache')->where('key', self::key($actor))->delete();
    }

    public static function purgeExpired(): int
    {
        return DB::table('cache')->where('key', 'like', 'kalinga-uat-delivery:%')->where('expiration', '<=', now()->timestamp)->delete();
    }

    /** One recovery only, for the creator's complete, unused and unchanged roster. */
    public static function reissueUnused(User $actor, ?string $sharedPassword = null): void
    {
        abort_unless($actor->active && $actor->role === 'system_admin' && config('kalinga.uat_enabled'), 403);
        DB::transaction(function () use ($actor, $sharedPassword) {
            if ($sharedPassword !== null && (strlen($sharedPassword) < 12 || strlen($sharedPassword) > 128)) {
                throw ValidationException::withMessages(['password' => 'Use a password between 12 and 128 characters.']);
            }
            $event = $sharedPassword === null ? 'user.uat_reissued' : 'user.uat_shared_password_set';
            $users = User::whereNotNull('uat_key')->orderBy('id')->lockForUpdate()->get();
            $ids = $users->modelKeys();
            if ($users->count() !== 43 || ($sharedPassword === null && self::available($actor))
                || DB::table('audit_logs')->whereIn('user_id', $ids)->whereIn('event', ['auth.login', 'auth.google_login', 'two_factor.uat_verified'])->exists()
                || DB::table('audit_logs')->whereIn('event', $sharedPassword === null ? ['user.uat_reissued', 'user.uat_shared_password_set'] : ['user.uat_shared_password_set'])->whereIn('auditable_id', $ids)->exists()) {
                throw ValidationException::withMessages(['roster' => 'Recovery requires the complete unused roster and can run only once. Download an available export instead.']);
            }
            foreach ($users as $user) {
                if (!UatAccounts::usable($user) || (int) $user->reviewed_by !== $actor->id) {
                    throw ValidationException::withMessages(['roster' => 'The roster has changed or belongs to another administrator. Nothing was reset.']);
                }
            }
            $rows = collect(UatAccounts::roster())->keyBy('uat_key');
            $accounts = [];
            foreach ($users as $user) {
                $password = $sharedPassword ?? Str::password(20, symbols: false);
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                $accounts[] = $rows[$user->uat_key] + ['password' => $password, 'expires_at' => $user->uat_expires_at->toIso8601String()];
                AuditLog::record($event, 'user', $user->id, ['uat_key' => $user->uat_key], $actor->id);
            }
            self::save($actor, $accounts);
        });
    }
}
