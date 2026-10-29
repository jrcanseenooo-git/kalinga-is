<?php

namespace App\Http\Middleware;

use App\Services\UatAccounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->getAttribute('active') === false || ($user->uat_key && !UatAccounts::usable($user)))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'This account is no longer active.'], 403)
                : redirect()->route('login')->with('status', 'This account is no longer active.');
        }

        return $next($request);
    }
}
