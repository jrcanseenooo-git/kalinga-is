<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Requests\ProfileUpdateRequest;
use App\Support\AuditLog;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'twoFactorEnabled' => (bool) $user->two_factor_confirmed_at,
            'twoFactorRequired' => $user->role ? TwoFactorController::requiresMfa($user->role) : false,
            'twoFactorMethod' => $user->two_factor_method ?? 'email',
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Accounts are managed by the system administrator. Other users ask the
        // administrator to deactivate or remove their account.
        $user = $request->user();
        abort_unless($user->role === 'system_admin', 403, 'Only the system administrator can delete accounts.');
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);
        $otherAdmins = DB::table('users')->where('role', 'system_admin')->where('active', true)->where('id', '!=', $user->id)->count();
        if ($otherAdmins === 0) {
            return Redirect::route('profile.edit')->withErrors(['password' => 'The last active System Administrator cannot be deleted.']);
        }
        AuditLog::insert(['user_id' => $user->id, 'event' => 'user.self_deleted', 'auditable_type' => 'user', 'auditable_id' => $user->id,
            'changes' => json_encode(['email' => $user->email]), 'created_at' => now(), 'updated_at' => now()]);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
