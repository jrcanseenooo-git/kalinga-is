<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MonitoringScope;
use App\Services\OperationalNotification;
use App\Services\RolePermissions;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Kalinga Connect for Project Managers (Phase 8): find case managers (and
 * LGU Heads, Field Office users) in the regions they cover, message them
 * directly, and create group chats such as "Region VIII".
 *
 * Only users with connect.network start conversations or add people; anyone
 * added can read and reply while their account is active. These
 * conversations are separate from the LGU coordination circles and never
 * open a case. Messages are for coordination only (no names or case details).
 */
class ConnectNetworkController extends Controller
{
    private const REACHABLE_ROLES = ['social_worker', 'lgu_head', 'fo_user', 'project_manager'];

    public function __construct(private readonly MonitoringScope $monitoring, private readonly OperationalNotification $notifications) {}

    /** Regions the Project Manager covers; null = all. */
    private function regions(User $user): ?array
    {
        return $this->monitoring->regions($user);
    }

    /** People the Project Manager may reach: active staff working in the regions covered. */
    private function reachable(User $user)
    {
        $regions = $this->regions($user);
        $query = DB::table('users')->where('users.active', true)->where('users.id', '!=', $user->id)->whereIn('users.role', self::REACHABLE_ROLES)
            ->leftJoin('user_lgu_assignments as a', fn ($join) => $join->on('a.user_id', '=', 'users.id')->where('a.active', true))
            ->leftJoin('lgu_units as lgu', 'lgu.id', '=', 'a.lgu_id');
        if ($regions !== null) {
            $query->where(fn ($q) => $q->whereIn('lgu.region', $regions ?: ['-'])->orWhere(fn ($fo) => $fo->where('users.role', 'fo_user')->whereIn('users.region', $regions ?: ['-'])));
        }

        return $query;
    }

    private function member(int $thread, int $userId): ?object
    {
        return DB::table('connect_thread_members')->join('users', 'users.id', '=', 'connect_thread_members.user_id')->where('thread_id', $thread)->where('user_id', $userId)
            ->whereNull('left_at')->where('users.active', true)->first(['connect_thread_members.*']);
    }

    private function threads(User $user)
    {
        $rows = DB::table('connect_threads as t')->join('connect_thread_members as m', fn ($join) => $join->on('m.thread_id', '=', 't.id')->where('m.user_id', $user->id)->whereNull('m.left_at'))
            ->orderByDesc(DB::raw('COALESCE(t.last_message_at, t.created_at)'))->get(['t.*', 'm.last_read_message_id', 'm.role as my_role']);

        return $rows->map(function ($thread) use ($user) {
            $others = DB::table('connect_thread_members')->join('users', 'users.id', '=', 'connect_thread_members.user_id')->where('thread_id', $thread->id)->whereNull('left_at')
                ->where('users.id', '!=', $user->id)->orderBy('users.name')->get(['users.id', 'users.name', 'users.role']);

            return ['id' => $thread->id, 'kind' => $thread->kind, 'title' => $thread->kind === 'group' ? $thread->title : ($others->first()->name ?? 'Conversation'),
                'members' => $others->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'role' => RolePermissions::label($p->role)])->values(),
                'unread' => DB::table('connect_messages')->where('thread_id', $thread->id)->where('sender_id', '!=', $user->id)->where('id', '>', (int) $thread->last_read_message_id)->count(),
                'last_message_at' => $thread->last_message_at, 'can_manage' => $thread->my_role === 'owner'];
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();
        // Project team: Project Managers, Field Office, LGU Heads and case managers (not CPU members).
        abort_unless($user->hasPermission('connect.network') || $user->hasPermission('cases.monitor') || $user->hasPermission('cases.update'), 403);

        return Inertia::render('Connect/Network', [
            'threads' => $this->threads($user)->values(),
            'canStart' => $user->hasPermission('connect.network'),
            'regions' => $user->hasPermission('connect.network') ? ($this->regions($user) ?? DB::table('lgu_units')->where('active', true)->distinct()->orderBy('region')->pluck('region')->filter()->values()->all()) : [],
        ]);
    }

    public function people(Request $request)
    {
        $this->authorize('connect.network');
        $data = $request->validate(['q' => 'nullable|string|max:80', 'region' => 'nullable|string|max:120', 'role' => 'nullable|in:social_worker,lgu_head,fo_user,project_manager']);
        $query = $this->reachable($request->user());
        if (!empty($data['region'])) {
            $query->where(fn ($q) => $q->where('lgu.region', $data['region'])->orWhere('users.region', $data['region']));
        }
        if (!empty($data['role'])) {
            $query->where('users.role', $data['role']);
        }
        if (trim((string) ($data['q'] ?? '')) !== '') {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($data['q'])) . '%';
            $query->where(fn ($q) => $q->where('users.name', 'like', $term)->orWhere('lgu.name', 'like', $term));
        }
        $people = $query->orderBy('users.name')->limit(300)->get(['users.id', 'users.name', 'users.role', 'lgu.name as lgu', 'lgu.region', 'users.region as user_region'])
            ->groupBy('id')->map(fn ($rows) => ['id' => $rows[0]->id, 'name' => $rows[0]->name, 'role' => RolePermissions::label($rows[0]->role),
                'place' => $rows->pluck('lgu')->filter()->unique()->implode(', ') ?: ($rows[0]->user_region ?: null), 'region' => $rows[0]->region ?? $rows[0]->user_region])->values()->take(100);

        return response()->json(['people' => $people], 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Opens (or reuses) a direct conversation with one person. */
    public function direct(Request $request)
    {
        $this->authorize('connect.network');
        $data = $request->validate(['user_id' => 'required|integer']);
        $user = $request->user();
        abort_unless((clone $this->reachable($user))->where('users.id', $data['user_id'])->exists(), 404);
        $existing = DB::table('connect_threads as t')->where('t.kind', 'direct')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('connect_thread_members')->whereColumn('thread_id', 't.id')->where('user_id', $user->id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('connect_thread_members')->whereColumn('thread_id', 't.id')->where('user_id', $data['user_id']))->value('t.id');
        $id = $existing ?: DB::transaction(function () use ($user, $data) {
            $id = DB::table('connect_threads')->insertGetId(['kind' => 'direct', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('connect_thread_members')->insert([['thread_id' => $id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
                ['thread_id' => $id, 'user_id' => (int) $data['user_id'], 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]]);
            AuditLog::record('connect.thread_created', 'connect_thread', $id, ['kind' => 'direct'], $user->id, 'success', ['description' => 'Direct conversation started']);

            return $id;
        });
        DB::table('connect_thread_members')->where('thread_id', $id)->update(['left_at' => null]);

        return response()->json(['thread_id' => $id]);
    }

    public function group(Request $request)
    {
        $this->authorize('connect.network');
        $data = $request->validate(['title' => 'required|string|min:3|max:120', 'members' => 'required|array|min:1|max:300', 'members.*' => 'integer']);
        $user = $request->user();
        $allowed = (clone $this->reachable($user))->whereIn('users.id', $data['members'])->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        if (count($allowed) !== count(array_unique($data['members']))) {
            throw ValidationException::withMessages(['members' => 'You can add only active staff in the regions you cover.']);
        }
        $id = DB::transaction(function () use ($user, $data, $allowed) {
            $id = DB::table('connect_threads')->insertGetId(['kind' => 'group', 'title' => $data['title'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('connect_thread_members')->insert(array_merge([['thread_id' => $id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]],
                array_map(fn ($member) => ['thread_id' => $id, 'user_id' => $member, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()], $allowed)));
            AuditLog::record('connect.thread_created', 'connect_thread', $id, ['kind' => 'group', 'members' => count($allowed)], $user->id, 'success', ['description' => 'Group chat created: ' . $data['title']]);

            return $id;
        });
        foreach ($allowed as $member) {
            $this->notifications->send($member, 'connect.added', 'Added to a group chat', $user->name . ' added you to "' . $data['title'] . '".', route('connect.network', ['thread' => $id]));
        }

        return response()->json(['thread_id' => $id]);
    }

    public function addMembers(Request $request, int $thread)
    {
        $this->authorize('connect.network');
        $row = DB::table('connect_threads')->where('id', $thread)->where('kind', 'group')->first();
        abort_unless($row && ($this->member($thread, $request->user()->id)->role ?? null) === 'owner', 404);
        $data = $request->validate(['members' => 'required|array|min:1|max:300', 'members.*' => 'integer']);
        $allowed = (clone $this->reachable($request->user()))->whereIn('users.id', $data['members'])->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        foreach ($allowed as $member) {
            DB::table('connect_thread_members')->updateOrInsert(['thread_id' => $thread, 'user_id' => $member], ['role' => 'member', 'left_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->notifications->send($member, 'connect.added', 'Added to a group chat', $request->user()->name . ' added you to "' . $row->title . '".', route('connect.network', ['thread' => $thread]));
        }
        AuditLog::record('connect.members_added', 'connect_thread', $thread, ['count' => count($allowed)], $request->user()->id, 'success', ['description' => 'People added to group chat: ' . $row->title]);

        return response()->json(['added' => count($allowed)]);
    }

    public function messages(Request $request, int $thread)
    {
        $membership = $this->member($thread, $request->user()->id);
        abort_unless($membership, 404);
        $data = $request->validate(['after' => 'nullable|integer|min:0']);
        $messages = DB::table('connect_messages')->join('users', 'users.id', '=', 'connect_messages.sender_id')->where('thread_id', $thread)
            ->when($data['after'] ?? null, fn ($q, $after) => $q->where('connect_messages.id', '>', $after))
            ->orderByDesc('connect_messages.id')->limit(100)->get(['connect_messages.id', 'connect_messages.body', 'connect_messages.is_urgent', 'connect_messages.created_at', 'users.name as sender', 'users.role as sender_role', 'connect_messages.sender_id'])
            ->reverse()->values()->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'is_urgent' => (bool) $m->is_urgent, 'created_at' => $m->created_at, 'sender' => $m->sender,
                'sender_role' => RolePermissions::label($m->sender_role), 'mine' => (int) $m->sender_id === (int) $request->user()->id]);
        if ($last = $messages->last()) {
            DB::table('connect_thread_members')->where('id', $membership->id)->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $last['id']))->update(['last_read_message_id' => $last['id']]);
        }

        return response()->json(['messages' => $messages], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function send(Request $request, int $thread)
    {
        $user = $request->user();
        abort_unless($this->member($thread, $user->id), 404);
        $data = $request->validate(['body' => 'required|string|min:1|max:4000', 'is_urgent' => 'boolean']);
        $title = DB::table('connect_threads')->where('id', $thread)->value('title');
        $id = DB::transaction(function () use ($thread, $user, $data) {
            $id = DB::table('connect_messages')->insertGetId(['thread_id' => $thread, 'sender_id' => $user->id, 'body' => $data['body'], 'is_urgent' => $data['is_urgent'] ?? false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('connect_threads')->where('id', $thread)->update(['last_message_at' => now(), 'updated_at' => now()]);
            DB::table('connect_thread_members')->where('thread_id', $thread)->where('user_id', $user->id)->update(['last_read_message_id' => $id]);

            return $id;
        });
        // One notification per person until they read the conversation (no message text in it).
        $members = DB::table('connect_thread_members')->where('thread_id', $thread)->whereNull('left_at')->where('user_id', '!=', $user->id)->get(['user_id', 'last_read_message_id']);
        foreach ($members as $member) {
            $unreadBefore = DB::table('connect_messages')->where('thread_id', $thread)->where('sender_id', '!=', $member->user_id)->where('id', '>', (int) $member->last_read_message_id)->where('id', '<', $id)->exists();
            if (!$unreadBefore || ($data['is_urgent'] ?? false)) {
                $this->notifications->send((int) $member->user_id, 'connect.message', ($data['is_urgent'] ?? false) ? 'Urgent message' : 'New message', 'From ' . $user->name . ($title ? ' in "' . $title . '"' : '') . '.', route('connect.network', ['thread' => $thread]));
            }
        }
        AuditLog::record('connect.message_sent', 'connect_thread', $thread, ['message_id' => $id], $user->id, 'success', ['description' => 'Message sent']);

        return response()->json(['id' => $id]);
    }

    public function leave(Request $request, int $thread)
    {
        $membership = $this->member($thread, $request->user()->id);
        abort_unless($membership && DB::table('connect_threads')->where('id', $thread)->value('kind') === 'group', 404);
        DB::table('connect_thread_members')->where('id', $membership->id)->update(['left_at' => now(), 'updated_at' => now()]);
        AuditLog::record('connect.left', 'connect_thread', $thread, [], $request->user()->id, 'success', ['description' => 'Left a group chat']);

        return response()->json(['left' => true]);
    }
}
