<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Audit entries for refused requests (access denied, repeated attempts) and
 * application errors. Only the route name, method, path pattern and the
 * error class are kept: never request data, query strings or error messages,
 * which can contain personal information.
 */
final class AuditExceptions
{
    private const DENIALS_BEFORE_ALERT = 5;

    private const ALERT_WINDOW_MINUTES = 10;

    public static function denied(Request $request): void
    {
        try {
            self::recordDenial($request);
        } catch (Throwable) {
            // Auditing must never turn a refusal into an error page.
        }
    }

    private static function recordDenial(Request $request): void
    {
        $userId = $request->user()?->id;
        $route = $request->route()?->getName() ?? 'unnamed';
        $who = $userId ? 'user:' . $userId : 'ip:' . $request->ip();

        // The same refused page within a minute is one entry.
        if (!Cache::add('audit:denied:' . $who . ':' . $route, true, 60)) {
            return;
        }
        AuditLog::safely('access.denied', 'route', null, ['route' => $route, 'method' => $request->method(), 'path' => self::pattern($request)], $userId, 'denied',
            ['record_ref' => $route, 'description' => 'Access denied: ' . $route]);

        $countKey = 'audit:denied-count:' . $who;
        Cache::add($countKey, 0, now()->addMinutes(self::ALERT_WINDOW_MINUTES));
        $count = Cache::increment($countKey);
        if ($count === self::DENIALS_BEFORE_ALERT) {
            AuditLog::safely('security.repeated_access_denied', 'user', $userId, ['denials' => $count, 'window_minutes' => self::ALERT_WINDOW_MINUTES], $userId, 'failure',
                ['description' => "{$count} refused page requests within " . self::ALERT_WINDOW_MINUTES . ' minutes']);
        }
    }

    public static function error(Throwable $exception, string $event = 'system.error'): void
    {
        $request = app()->runningInConsole() && !app()->runningUnitTests() ? null : request();
        $route = $request?->route()?->getName() ?? ($request ? 'unnamed' : 'console');
        AuditLog::safely($event, 'system', null, ['error' => class_basename($exception), 'route' => $route, 'method' => $request?->method()], $request?->user()?->id, 'failure',
            ['record_ref' => $route, 'description' => ($event === 'system.database_error' ? 'Database error' : 'Application error') . ' (' . class_basename($exception) . ') on ' . $route]);
    }

    /** The route URI with parameters as placeholders, so no ids or names are stored. */
    private static function pattern(Request $request): string
    {
        return '/' . ltrim((string) ($request->route()?->uri() ?? 'unknown'), '/');
    }
}
