<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allows CORS only for explicitly configured first-party origins. Case data is
 * never available to arbitrary browser origins.
 */
class HandleTrustedCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if (!$origin) {
            return $next($request);
        }

        $allowedOrigins = array_filter(array_map('trim', config('kalinga.cors_allowed_origins', [])));
        $sameOrigin = rtrim($request->getSchemeAndHttpHost(), '/') === rtrim($origin, '/');

        if (!$sameOrigin && !in_array($origin, $allowedOrigins, true)) {
            return response()->json(['message' => 'Cross-origin access is not permitted.'], 403);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response()->noContent();
        } else {
            $response = $next($request);
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, X-CSRF-TOKEN, X-Requested-With, X-Kalinga-Device-ID');
        $response->headers->set('Vary', 'Origin', false);

        return $response;
    }
}
