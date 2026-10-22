<?php

namespace App\Http\Controllers;

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
}
