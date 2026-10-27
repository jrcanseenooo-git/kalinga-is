<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for every response, including error pages
 * (bootstrap/app.php calls SecurityHeaders::apply for those).
 *
 * - Content-Security-Policy: own origin only; scripts need the per-request
 *   nonce. The Vite dev server is allowed only in local and testing.
 *   style-src keeps 'unsafe-inline' because Vue writes inline style bindings.
 * - HSTS whenever the request is HTTPS, and always in production (the app
 *   forces https there). Behind a proxy set TRUSTED_PROXIES so HTTPS is seen.
 * - Cross-origin isolation of windows and resources.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request->isSecure());
    }

    public static function apply(Response $response, bool $secure = false): Response
    {
        $response->headers->remove('X-Powered-By');
        header_remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(self), payment=(), usb=(), interest-cohort=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Content-Security-Policy', self::policy());

        if ($secure || app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private static function policy(): string
    {
        $dev = app()->environment('local', 'testing');
        $vite = $dev ? ' http://127.0.0.1:5173 http://localhost:5173' : '';
        $viteConnect = $dev ? $vite . ' ws://127.0.0.1:5173 ws://localhost:5173' : '';
        $nonce = Vite::cspNonce();
        // Without a nonce (an error raised before the page rendered) no inline script may run.
        $scripts = $nonce ? "'self' 'nonce-{$nonce}'" : "'self'";

        return implode('; ', array_filter([
            "default-src 'self'", "base-uri 'self'", "frame-ancestors 'none'", "form-action 'self'", "object-src 'none'",
            "img-src 'self' data: blob:", "font-src 'self' data:", "style-src 'self' 'unsafe-inline'{$vite}",
            "script-src {$scripts}{$vite}", "connect-src 'self'{$viteConnect}", "worker-src 'self'", "manifest-src 'self'",
            app()->environment('production') ? 'upgrade-insecure-requests' : null,
        ]));
    }
}
