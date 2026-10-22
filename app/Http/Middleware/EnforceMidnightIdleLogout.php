<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceMidnightIdleLogout
{
    private const SESSION_DAY = 'kalinga.session_day';

    private const LAST_ACTIVITY = 'kalinga.last_activity_at';

    private const MIDNIGHT_PENDING = 'kalinga.midnight_idle_logout_pending';

    private const IDLE_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return $next($request);
        }

        $now = now('Asia/Manila');
        $session = $request->session();
        $sessionDay = (string) $session->get(self::SESSION_DAY, $now->toDateString());

        if ($sessionDay < $now->toDateString()) {
            $session->put(self::MIDNIGHT_PENDING, true);
        }

        $lastActivity = $session->get(self::LAST_ACTIVITY);
        if ((bool) $session->get(self::MIDNIGHT_PENDING) && $lastActivity && Carbon::parse($lastActivity, 'Asia/Manila')->lte($now->copy()->subMinutes(self::IDLE_MINUTES))) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your session ended after midnight because it was idle for five minutes.'], 401);
            }

            return redirect()->route('login')->with('status', 'Your session ended after midnight because it was idle for five minutes.');
        }

        $session->put(self::SESSION_DAY, $sessionDay);
        $session->put(self::LAST_ACTIVITY, $now->toIso8601String());

        return $next($request);
    }
}
