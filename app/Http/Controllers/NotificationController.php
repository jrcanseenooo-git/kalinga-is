<?php

namespace App\Http\Controllers;

use App\Services\WebPush;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Notifications/Index', [
            'items' => DB::table('notifications')
                ->where('user_id', $r->user()->id)
                ->latest()
                ->paginate(50),
        ]);
    }

    public function read(Request $r, int $notification)
    {
        DB::table('notifications')
            ->where('id', $notification)
            ->where('user_id', $r->user()->id)
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back();
    }

    public function readAll(Request $r)
    {
        DB::table('notifications')
            ->where('user_id', $r->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return back();
    }

    /**
     * For the phone's service worker after a push: the newest unread
     * notifications (title, short text, link). Returned only to the signed-in
     * user, never cached.
     */
    public function latest(Request $r)
    {
        $items = DB::table('notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->where('created_at', '>=', now()->subDays(3))
            ->latest()->limit(3)->get(['id', 'title', 'body', 'action_url', 'created_at']);

        return response()->json(['items' => $items, 'unread' => DB::table('notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->count()], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function subscribe(Request $r)
    {
        abort_unless(WebPush::enabled(), 404);
        $data = $r->validate(['endpoint' => ['required', 'url', 'max:1000', 'starts_with:https://'], 'device_label' => 'nullable|string|max:80']);
        $hash = hash('sha256', $data['endpoint']);
        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => $hash], ['user_id' => $r->user()->id, 'endpoint' => $data['endpoint'],
            'device_label' => $data['device_label'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('push.subscribed', 'user', $r->user()->id, ['device_label' => $data['device_label'] ?? null], $r->user()->id, 'success', ['description' => 'Phone notifications turned on']);

        return response()->json(['status' => 'subscribed']);
    }

    public function unsubscribe(Request $r)
    {
        $data = $r->validate(['endpoint' => 'required|string|max:1000']);
        DB::table('push_subscriptions')->where('user_id', $r->user()->id)->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();
        AuditLog::record('push.unsubscribed', 'user', $r->user()->id, [], $r->user()->id, 'success', ['description' => 'Phone notifications turned off']);

        return response()->json(['status' => 'unsubscribed']);
    }
}
