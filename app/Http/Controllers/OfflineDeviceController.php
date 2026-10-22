<?php

namespace App\Http\Controllers;

use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineDeviceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('offline.use');

        return response()->json([
            'devices' => DB::table('offline_devices')
                ->where('user_id', $request->user()->id)
                ->orderByDesc('last_seen_at')
                ->get(['id', 'device_id', 'device_name', 'last_seen_at', 'pack_expires_at', 'revoked_at', 'created_at']),
        ]);
    }

    public function revoke(Request $request, int $device)
    {
        $this->authorize('offline.use');

        $row = DB::table('offline_devices')
            ->where('id', $device)
            ->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->first();

        abort_unless($row, 404, 'Device not found or already revoked.');

        DB::transaction(function () use ($request, $device, $row) {
            DB::table('offline_devices')->where('id', $device)->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::insert([
                'user_id' => $request->user()->id,
                'event' => 'offline.device_revoked',
                'auditable_type' => 'offline_device',
                'auditable_id' => $device,
                'changes' => json_encode(['device_id' => $row->device_id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => 'revoked']);
    }

    public function rename(Request $request, int $device)
    {
        $this->authorize('offline.use');

        $data = $request->validate(['device_name' => 'required|string|max:80']);

        $updated = DB::table('offline_devices')
            ->where('id', $device)
            ->where('user_id', $request->user()->id)
            ->update(['device_name' => $data['device_name'], 'updated_at' => now()]);

        abort_unless($updated, 404, 'Device not found.');

        return response()->json(['status' => 'renamed']);
    }

    public function status(Request $request)
    {
        $this->authorize('offline.use');

        $deviceId = (string) $request->header('X-Kalinga-Device-ID');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $deviceId)) {
            return response()->json(['registered' => false, 'revoked' => false, 'expired' => false]);
        }

        $device = DB::table('offline_devices')
            ->where('user_id', $request->user()->id)
            ->where('device_id', $deviceId)
            ->first(['revoked_at', 'pack_expires_at']);

        if (!$device) {
            return response()->json(['registered' => false, 'revoked' => false, 'expired' => false]);
        }

        return response()->json([
            'registered' => true,
            'revoked' => (bool) $device->revoked_at,
            'expired' => $device->pack_expires_at && now()->gt($device->pack_expires_at),
            'pack_expires_at' => $device->pack_expires_at,
        ]);
    }

    public function adminIndex(Request $request, int $user)
    {
        $this->authorize('administer-system');

        return response()->json([
            'devices' => DB::table('offline_devices')
                ->where('user_id', $user)
                ->orderByDesc('last_seen_at')
                ->get(['id', 'device_id', 'device_name', 'last_seen_at', 'pack_expires_at', 'revoked_at', 'created_at']),
        ]);
    }

    public function adminRevoke(Request $request, int $device)
    {
        $this->authorize('administer-system');

        $row = DB::table('offline_devices')->where('id', $device)->whereNull('revoked_at')->first();
        abort_unless($row, 404, 'Device not found or already revoked.');

        DB::transaction(function () use ($request, $device, $row) {
            DB::table('offline_devices')->where('id', $device)->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLog::insert([
                'user_id' => $request->user()->id,
                'event' => 'offline.device_revoked_by_admin',
                'auditable_type' => 'offline_device',
                'auditable_id' => $device,
                'changes' => json_encode(['device_id' => $row->device_id, 'owner_user_id' => $row->user_id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['status' => 'revoked']);
    }
}
