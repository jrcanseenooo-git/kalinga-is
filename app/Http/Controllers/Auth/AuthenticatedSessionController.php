<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\GoogleOAuthConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'googleEnabled' => app(GoogleOAuthConfiguration::class)->enabled(),
            'registrationEnabled' => (bool) config('kalinga.registration_enabled'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $request->session()->put('kalinga.session_day', now('Asia/Manila')->toDateString());
        $request->session()->put('kalinga.last_activity_at', now('Asia/Manila')->toIso8601String());
        $request->session()->forget('kalinga.midnight_idle_logout_pending');
        Inertia::clearHistory();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // Set this after invalidation so the next page discards the old key.
        Inertia::clearHistory();

        return redirect('/');
    }
}
