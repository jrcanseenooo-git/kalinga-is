<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RolePermissions;
use App\Support\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public const REGISTRABLE_ROLES = ['social_worker', 'cpu_member', 'lgu_head'];

    public function create(): Response
    {
        $roles = collect(self::REGISTRABLE_ROLES)->map(fn (string $code) => [
            'code' => $code,
            'label' => RolePermissions::ROLES[$code][0],
        ])->values()->all();

        return Inertia::render('Auth/Register', [
            'registrableRoles' => $roles,
            'cpuDisciplines' => RolePermissions::CPU_DISCIPLINES,
        ]);
    }

    private const REGISTRATION_SUCCESS = 'Your registration has been submitted. An administrator will review and approve your account. You will be notified once your account is activated.';

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'requested_role' => 'required|string|in:' . implode(',', self::REGISTRABLE_ROLES),
            'cpu_discipline' => 'nullable|required_if:requested_role,cpu_member|string|in:' . implode(',', array_keys(RolePermissions::CPU_DISCIPLINES)),
            'region' => 'required|string|max:120',
            'province' => 'nullable|string|max:120',
            'municipality' => 'required|string|max:120',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return redirect(route('login', absolute: false))
                ->with('status', self::REGISTRATION_SUCCESS);
        }

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['requested_role'],
                'active' => false,
                'account_status' => 'pending',
                'requested_role' => $data['requested_role'],
                'cpu_discipline' => $data['requested_role'] === 'cpu_member' ? $data['cpu_discipline'] : null,
                'region' => $data['region'],
                'province' => $data['province'] ?? null,
                'municipality' => $data['municipality'],
            ]);

            AuditLog::insert([
                'user_id' => $user->id,
                'event' => 'user.self_registered',
                'auditable_type' => 'user',
                'auditable_id' => $user->id,
                'changes' => json_encode([
                    'requested_role' => $data['requested_role'],
                    'cpu_discipline' => $data['cpu_discipline'] ?? null,
                    'region' => $data['region'],
                    'municipality' => $data['municipality'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect(route('login', absolute: false))
            ->with('status', self::REGISTRATION_SUCCESS);
    }
}
