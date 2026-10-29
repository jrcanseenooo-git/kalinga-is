<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Services\UatAccounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    private const BYPASS_ROUTES = [
        'two-factor.challenge', 'two-factor.verify', 'two-factor.setup', 'two-factor.confirm',
        'two-factor.email-resend', 'logout', 'password.confirm', 'password.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $currentRoute = $request->route()?->getName();
        if ($currentRoute && in_array($currentRoute, self::BYPASS_ROUTES, true)) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_confirmed', false)) {
            return $next($request);
        }

        if ($request->session()->get('kalinga.auth_method') === 'google') {
            $request->session()->put('two_factor_confirmed', true);

            return $next($request);
        }

        $hasTotp = $user->two_factor_secret && $user->two_factor_confirmed_at;
        $requiresMfa = UatAccounts::usable($user) || (config('kalinga.mfa_enforcement', true)
            && $user->role
            && TwoFactorController::requiresMfa($user->role));

        if ($hasTotp || $requiresMfa) {
            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
