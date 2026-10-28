<?php

namespace App\Http\Middleware;

use App\Services\CaseAccessScope;
use App\Services\ChatUnread;
use App\Services\OptionSets;
use App\Services\RolePermissions;
use App\Services\WebPush;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function handle(Request $request, Closure $next): Response
    {
        // Page props include sensitive case records. Inertia must encrypt its
        // history entries, including the initial page and subsequent visits.
        Inertia::encryptHistory();
        if (!$request->user()) {
            Inertia::clearHistory();
        }
        $response = parent::handle($request, $next);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    private function navCounts(Request $request): array
    {
        $user = $request->user();
        $counts = ['activeCases' => null, 'calendarDue' => null, 'connectUnread' => null, 'teamUnread' => null];
        try {
            if ($user->hasPermission('cases.view') && in_array($user->role, ['social_worker', 'fo_user'], true)) {
                $cases = DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at')->whereNotIn('cases.status', ['CLOSED']);
                $counts['activeCases'] = app(CaseAccessScope::class)->scopeCases($cases, $user)->count();
            }
            if ($user->hasPermission('activities.manage')) {
                $endOfToday = now()->timezone('Asia/Manila')->endOfDay()->utc();
                $counts['calendarDue'] = DB::table('tasks')->where('assigned_to', $user->id)->where('status', 'open')->whereNotNull('due_at')->where('due_at', '<=', $endOfToday)->count();
            }
            if ($user->hasPermission('cpu.chat.view')) {
                $counts['connectUnread'] = ChatUnread::connect($user);
            }
            if ($user->hasPermission('connect.network') || $user->hasPermission('cases.monitor') || $user->hasPermission('cases.update')) {
                $counts['teamUnread'] = ChatUnread::team($user);
            }
        } catch (\Throwable) {
            // Badges are a convenience; never block a page over them.
        }

        return $counts;
    }

    public function share(Request $request): array
    {
        // The field shell is cached outside the encrypted case pack. Keep inbox
        // narratives and case-linked action URLs out of that persistent shell.
        $includeNotifications = !$request->routeIs('offline.workspace');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'position' => $request->user()->position,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'email_verified_at' => $request->user()->email_verified_at,
                ] : null,
                'permissions' => $request->user() ? app(RolePermissions::class)->permissionsFor($request->user()) : [],
            ],
            // One-time outcome messages from redirects (shown as a toast by the layout).
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            // Small counts for the navigation badges; no case content.
            'navCounts' => fn () => $includeNotifications && $request->user() ? $this->navCounts($request) : null,
            'notificationPreview' => fn () => $includeNotifications && $request->user()
                ? DB::table('notifications')->where('user_id', $request->user()->id)->latest()->limit(5)->get(['id', 'type', 'title', 'body', 'action_url', 'read_at', 'created_at'])
                : [],
            'unreadNotifications' => fn () => $includeNotifications && $request->user()
                ? DB::table('notifications')->where('user_id', $request->user()->id)->whereNull('read_at')->count()
                : 0,
            // Public VAPID key for turning on phone notifications (null when not configured).
            // Dropdown lists edited in Administration > Form options (replace the built-in lists).
            'optionSets' => fn () => $request->user() ? OptionSets::forClient() : null,
            'pushPublicKey' => fn () => $request->user() ? WebPush::publicKey() : null,
        ];
    }
}
