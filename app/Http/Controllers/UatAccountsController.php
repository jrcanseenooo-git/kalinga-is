<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UatAccounts;
use App\Services\UatCredentialDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UatAccountsController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->role === 'system_admin' && config('kalinga.uat_enabled'), 403);
    }

    public function index(Request $request)
    {
        $this->guard($request);

        return Inertia::render('Admin/UatAccounts', [
            'roster' => UatAccounts::roster(),
            'existingCount' => User::whereIn('email', array_column(UatAccounts::roster(), 'email'))->orWhereNotNull('uat_key')->count(),
            'defaultExpiry' => now('Asia/Manila')->endOfDay()->format('Y-m-d\TH:i'),
            'exportAvailable' => UatCredentialDelivery::available($request->user()),
        ]);
    }

    public function download(Request $request)
    {
        $this->guard($request);
        $data = UatCredentialDelivery::read($request->user());

        return response()->json(['system' => $request->getSchemeAndHttpHost()] + $data)
            ->header('Content-Disposition', 'attachment; filename="kalinga-uat-credentials.json"')
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }

    public function acknowledge(Request $request)
    {
        $this->guard($request);
        $request->validate(['confirmed' => 'accepted']);
        UatCredentialDelivery::forget($request->user());

        return redirect()->route('admin.uat.index');
    }

    public function recover(Request $request)
    {
        $this->guard($request);
        $request->validate(['confirmed' => 'accepted']);
        UatCredentialDelivery::reissueUnused($request->user());

        return redirect()->route('admin.uat.index');
    }

    public function sharedPassword(Request $request)
    {
        $this->guard($request);
        $data = $request->validate(['confirmed' => 'accepted', 'password' => 'required|string|min:12|max:128|confirmed']);
        UatCredentialDelivery::reissueUnused($request->user(), $data['password']);

        return redirect()->route('admin.uat.index');
    }

    public function store(Request $request, UatAccounts $accounts)
    {
        $this->guard($request);
        $data = $request->validate(['expires_at' => 'required|date_format:Y-m-d\TH:i', 'confirmed' => 'accepted']);
        $expires = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['expires_at'], 'Asia/Manila');

        return response()->json(['accounts' => $accounts->create($request->user(), $expires)])
            ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
