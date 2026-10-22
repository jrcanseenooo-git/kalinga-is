<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OfflineWorkspaceController extends Controller
{
    public function __construct(private readonly CaseAccessScope $scope) {}

    public function index(Request $request)
    {
        $this->authorize('offline.use');

        return Inertia::render('Offline/Workspace');
    }

    public function pack(Request $request)
    {
        $user = $request->user();
        $this->authorize('offline.use');
        $device = $this->registerDevice($request);

        $cases = DB::table('cases')
            ->join('clients', 'clients.id', '=', 'cases.client_id')
            ->whereNull('cases.deleted_at');

        $this->scope->scopeCases($cases, $user);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'expires_at' => $device->pack_expires_at,
            'user_id' => $user->id,
            'cases' => $cases->orderByDesc('cases.updated_at')->limit(50)->get([
                'cases.id', 'cases.case_number', 'cases.status', 'cases.priority', 'cases.category', 'cases.offline_version', 'cases.last_activity_at',
                'clients.first_name', 'clients.last_name', 'clients.sex', 'clients.region', 'clients.province', 'clients.municipality', 'clients.barangay',
            ]),
        ]);
    }

    private function registerDevice(Request $request): object
    {
        $deviceId = (string) $request->header('X-Kalinga-Device-ID');
        abort_unless((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceId), 422, 'A registered offline device is required.');
        $existing = DB::table('offline_devices')->where('user_id', $request->user()->id)->where('device_id', $deviceId)->first();
        abort_unless(!$existing || !$existing->revoked_at, 403, 'This offline device has been revoked.');
        $expiresAt = now()->addHours(72);
        DB::table('offline_devices')->updateOrInsert(['user_id' => $request->user()->id, 'device_id' => $deviceId], ['last_seen_at' => now(), 'pack_expires_at' => $expiresAt, 'revoked_at' => null, 'updated_at' => now(), 'created_at' => now()]);

        return (object) ['pack_expires_at' => $expiresAt->toIso8601String()];
    }
}
