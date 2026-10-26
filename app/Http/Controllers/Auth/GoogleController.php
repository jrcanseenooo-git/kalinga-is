<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleOAuthConfiguration;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function __construct(private readonly GoogleOAuthConfiguration $google) {}

    public function redirect(): RedirectResponse
    {
        if (!$this->google->enabled()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in is not configured for this environment.']);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')
                ->with('status', 'Google sign-in was cancelled.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable) {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in failed. Please try again.']);
        }

        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if (!$user || !$user->active) {
            AuditLog::safely('auth.google_rejected', 'user', $user?->id, ['known_account' => (bool) $user], $user?->id, 'failure');

            return redirect()->route('login')
                ->withErrors(['email' => 'Authentication failed. Please check your credentials or contact your administrator.']);
        }

        if (!$user->google_id) {
            $user->update(['google_id' => $googleUser->getId()]);
        }

        if (!$user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        Auth::login($user, remember: true);

        $request->session()->regenerate();
        $request->session()->put('kalinga.session_day', now('Asia/Manila')->toDateString());
        $request->session()->put('kalinga.last_activity_at', now('Asia/Manila')->toIso8601String());
        $request->session()->forget('kalinga.midnight_idle_logout_pending');
        $request->session()->put('kalinga.auth_method', 'google');

        return redirect()->route('dashboard');
    }
}
