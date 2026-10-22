<?php

use App\Http\Middleware\EnforceMidnightIdleLogout;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureTwoFactorVerified;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\HandleTrustedCors;
use App\Http\Middleware\SecurityHeaders;
use App\Support\DatabaseFailurePrivacy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Behind a load balancer or hosting proxy, list its addresses (or "*") in
        // TRUSTED_PROXIES so HTTPS and the client address are detected correctly.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnforceMidnightIdleLogout::class,
            EnsureActiveUser::class,
            HandleTrustedCors::class,
            SecurityHeaders::class,
            EnsureTwoFactorVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (PDOException $exception) {
            return DatabaseFailurePrivacy::report($exception);
        });
        $exceptions->render(function (PDOException $exception, Request $request) {
            return DatabaseFailurePrivacy::response($request);
        });
        // A business-rule refusal (422) or stale-record conflict (409) raised during a normal
        // Inertia page visit returns to the form with the message, instead of the error page.
        // JSON/axios callers keep their status codes and JSON bodies.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if (!$request->header('X-Inertia') || !in_array($exception->getStatusCode(), [409, 422], true) || $request->isMethod('GET')) {
                return null;
            }
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : 'This change could not be recorded. Review the form and try again.';

            return back(303)->withErrors(['form' => $message])->with('error', $message);
        });
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => SecurityHeaders::apply($response, $request->isSecure()));
    })->create();
