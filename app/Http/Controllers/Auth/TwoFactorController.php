<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    /** Wrong codes allowed per sign-in before the session is ended (15-minute window). */
    public const MAX_CODE_ATTEMPTS = 5;

    /** Roles that must pass a second factor (config kalinga.mfa_roles / MFA_ROLES). */
    public static function requiresMfa(string $role): bool
    {
        return in_array($role, (array) config('kalinga.mfa_roles'), true);
    }

    private function resolveMethod(User $user, ?string $via): string
    {
        if ($via === 'email') {
            return 'email';
        }
        if ($via === 'totp' && $user->two_factor_secret && $user->two_factor_confirmed_at) {
            return 'totp';
        }
        if ($user->two_factor_method === 'totp' && $user->two_factor_secret && $user->two_factor_confirmed_at) {
            return 'totp';
        }

        return 'email';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email);
        $masked = substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));

        return $masked . '@' . $domain;
    }

    private function generateAndSendEmailOtp(User $user): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        User::where('id', $user->id)->update([
            'two_factor_email_code' => encrypt($code),
            'two_factor_email_code_expires_at' => now()->addMinutes(10),
        ]);

        $user->notify(new TwoFactorCodeNotification($code));

        return $code;
    }

    private function ensureEmailOtpSent(User $user): void
    {
        $user->refresh();

        if (
            $user->two_factor_email_code
            && $user->two_factor_email_code_expires_at
            && $user->two_factor_email_code_expires_at->isFuture()
        ) {
            return;
        }

        $this->generateAndSendEmailOtp($user);
    }

    public function challenge(Request $request)
    {
        if ($request->session()->get('two_factor_confirmed')) {
            return redirect()->intended(route('dashboard'));
        }

        $user = $request->user();
        $method = $this->resolveMethod($user, $request->query('via'));

        if ($method === 'email') {
            $this->ensureEmailOtpSent($user);
        }

        $user->refresh();

        $props = [
            'method' => $method,
            'hasTotp' => (bool) ($user->two_factor_secret && $user->two_factor_confirmed_at),
            'maskedEmail' => $this->maskEmail($user->email),
            'status' => session('status'),
        ];

        if ($method === 'email' && config('app.debug')) {
            $props['debugCode'] = $user->two_factor_email_code ? decrypt($user->two_factor_email_code) : null;
        }

        return Inertia::render('TwoFactor/Challenge', $props);
    }

    public function resendEmailOtp(Request $request)
    {
        $this->generateAndSendEmailOtp($request->user());

        return back()->with('status', 'A new verification code has been sent to your email.');
    }

    public function setup(Request $request)
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at) {
            return redirect()->route('profile.edit');
        }

        $g2fa = new Google2FA;
        $secret = $user->two_factor_secret ?? $g2fa->generateSecretKey();

        if (!$user->two_factor_secret) {
            User::where('id', $user->id)->update(['two_factor_secret' => encrypt($secret)]);
        } else {
            $secret = decrypt($user->two_factor_secret);
        }

        $otpUrl = $g2fa->getQRCodeUrl(config('app.name', 'Kalinga'), $user->email, $secret);

        return Inertia::render('TwoFactor/Setup', [
            'secret' => $secret,
            'otpUrl' => $otpUrl,
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string|size:6']);
        $user = $request->user();
        abort_unless($user->two_factor_secret, 422, 'Two-factor setup has not been started.');

        $g2fa = new Google2FA;
        $secret = decrypt($user->two_factor_secret);

        if (!$g2fa->verifyKey($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'The verification code is invalid.']);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        User::where('id', $user->id)->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_method' => 'totp',
        ]);

        AuditLog::insert([
            'user_id' => $user->id, 'event' => 'two_factor.totp_enabled',
            'auditable_type' => 'user', 'auditable_id' => $user->id,
            'changes' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request->session()->put('two_factor_confirmed', true);

        return Inertia::render('TwoFactor/RecoveryCodes', ['codes' => $codes]);
    }

    public function disable(Request $request)
    {
        $user = $request->user();

        if (self::requiresMfa($user->role)) {
            return back()->withErrors(['mfa' => 'Two-factor authentication is mandatory for your role.']);
        }

        User::where('id', $user->id)->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_method' => 'email',
        ]);

        AuditLog::insert([
            'user_id' => $user->id, 'event' => 'two_factor.disabled',
            'auditable_type' => 'user', 'auditable_id' => $user->id,
            'changes' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('profile.edit')->with('status', 'Authenticator app removed.');
    }

    public function resetAuthenticator(Request $request)
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        abort_unless($user->two_factor_confirmed_at, 422, 'Authenticator app is not enabled.');

        User::where('id', $user->id)->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_method' => 'email',
        ]);

        AuditLog::insert([
            'user_id' => $user->id, 'event' => 'two_factor.reset',
            'auditable_type' => 'user', 'auditable_id' => $user->id,
            'changes' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request->session()->put('two_factor_confirmed', true);

        return redirect()->route('profile.edit')
            ->with('status', 'Authenticator app reset. Your login verification now uses email codes.');
    }

    public function verify(Request $request)
    {
        $request->validate([
            'code' => 'nullable|string|size:6',
            'recovery_code' => 'nullable|string',
            'method' => 'nullable|string|in:email,totp',
        ]);

        $user = $request->user();
        $valid = false;

        if ($request->filled('recovery_code')) {
            if (!$user->two_factor_recovery_codes) {
                return back()->withErrors(['code' => 'No recovery codes are available.']);
            }

            $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
            $given = (string) $request->input('recovery_code');
            // Constant-time comparison so response timing reveals nothing about the codes.
            $valid = collect($codes)->contains(fn ($code) => hash_equals((string) $code, $given));

            if ($valid) {
                $remaining = array_values(array_filter($codes, fn ($c) => $c !== $request->input('recovery_code')));
                User::where('id', $user->id)->update([
                    'two_factor_recovery_codes' => encrypt(json_encode($remaining)),
                ]);
            }
        } elseif ($request->filled('code')) {
            $method = $request->input('method');
            if (!$method) {
                $method = ($user->two_factor_secret && $user->two_factor_confirmed_at) ? 'totp' : 'email';
            }

            if ($method === 'totp') {
                abort_unless($user->two_factor_secret && $user->two_factor_confirmed_at, 403);
                $g2fa = new Google2FA;
                $valid = $g2fa->verifyKey(decrypt($user->two_factor_secret), $request->input('code'));
            } else {
                $user->refresh();
                if (
                    $user->two_factor_email_code
                    && $user->two_factor_email_code_expires_at
                    && $user->two_factor_email_code_expires_at->isFuture()
                ) {
                    $valid = hash_equals(decrypt($user->two_factor_email_code), $request->input('code'));

                    if ($valid) {
                        User::where('id', $user->id)->update([
                            'two_factor_email_code' => null,
                            'two_factor_email_code_expires_at' => null,
                        ]);
                    }
                }
            }
        } else {
            return back()->withErrors(['code' => 'A verification code or recovery code is required.']);
        }

        $key = 'two-factor:' . $user->id;
        if (!$valid) {
            RateLimiter::hit($key, 900);
            if (RateLimiter::tooManyAttempts($key, self::MAX_CODE_ATTEMPTS)) {
                // Stops code guessing: the password must be entered again.
                AuditLog::insert(['user_id' => $user->id, 'event' => 'two_factor.locked', 'auditable_type' => 'user', 'auditable_id' => $user->id,
                    'changes' => json_encode(['ip' => $request->ip()]), 'created_at' => now(), 'updated_at' => now()]);
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => 'Too many incorrect verification codes. Sign in again to receive a new code.']);
            }

            return back()->withErrors(['code' => 'The code is invalid or has expired.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('two_factor_confirmed', true);

        AuditLog::insert([
            'user_id' => $user->id, 'event' => 'two_factor.verified',
            'auditable_type' => 'user', 'auditable_id' => $user->id,
            'changes' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->intended(route('dashboard'));
    }
}
