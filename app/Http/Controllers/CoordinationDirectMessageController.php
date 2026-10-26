<?php

namespace App\Http\Controllers;

use App\Services\CoordinationAccess;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Participant-only online conversations within a currently authorized LGU circle. */
class CoordinationDirectMessageController extends Controller
{
    public function __construct(private readonly CoordinationAccess $access) {}

    private function authorizePair(Request $request, int $circle, int $member): void
    {
        abort_if($member === (int) $request->user()->id, 422, 'Choose another member.');
        foreach ([(int) $request->user()->id, $member] as $user) {
            $this->access->participant($user, $circle, 'cpu.chat.post');
        }
    }

    private function conversation(int $circle, int $actor, int $member)
    {
        return DB::table('coordination_direct_messages')->where('circle_id', $circle)
            ->where(fn ($pair) => $pair->where(fn ($outgoing) => $outgoing->where('sender_id', $actor)->where('recipient_id', $member))
                ->orWhere(fn ($incoming) => $incoming->where('sender_id', $member)->where('recipient_id', $actor)));
    }

    public function index(Request $request, int $circle, int $member)
    {
        $this->authorize('cpu.chat.view');
        $this->authorizePair($request, $circle, $member);
        $data = $request->validate(['before' => 'nullable|integer|min:1', 'q' => 'nullable|string|min:2|max:100']);
        $base = $this->conversation($circle, (int) $request->user()->id, $member)
            ->when(!empty($data['q']), fn ($query) => $query->whereRaw('LOWER(body) LIKE ?', ['%' . strtolower(trim($data['q'])) . '%']));
        $rows = (clone $base)->when(!empty($data['before']), fn ($query) => $query->where('id', '<', $data['before']))
            ->orderByDesc('id')->limit(40)->get()->reverse()->values();
        $users = $this->access->members($circle)->get(['users.id', 'users.name', 'users.role', 'users.cpu_discipline', 'member.membership_role'])->keyBy('id');
        $messages = $rows->map(fn ($row) => [
            'id' => $row->id, 'parent_id' => $row->parent_id, 'case_id' => null, 'case_reference' => null,
            'body' => $row->body, 'author' => $users[$row->sender_id]->name, 'author_role' => $users[$row->sender_id]->role,
            'is_own' => (int) $row->sender_id === (int) $request->user()->id, 'created_at' => $row->created_at,
            'is_urgent' => (bool) $row->is_urgent, 'follow_up' => (bool) $row->follow_up, 'can_open_case' => false, 'mentions' => [],
        ]);
        $before = $rows->first()?->id;
        $this->authorizePair($request, $circle, $member);

        return response()->json(['messages' => $messages, 'members' => $users->values(),
            'pagination' => ['before' => $before, 'has_more' => $before !== null && (clone $base)->where('id', '<', $before)->exists()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $circle, int $member)
    {
        $this->authorize('cpu.chat.post');
        $this->authorizePair($request, $circle, $member);
        $data = $request->validate([
            'body' => 'required|string|max:3000', 'parent_id' => 'nullable|integer|min:1',
            'is_urgent' => 'boolean', 'follow_up' => 'boolean',
            'case_id' => 'prohibited', 'mention_user_ids' => 'prohibited',
        ]);
        $id = DB::transaction(function () use ($request, $circle, $member, $data) {
            $circleRow = DB::table('lgu_coordination_circles')->where('id', $circle)->lockForUpdate()->first();
            abort_unless($circleRow, 403);
            DB::table('lgu_units')->where('id', $circleRow->lgu_id)->lockForUpdate()->first();
            DB::table('users')->whereIn('id', [$request->user()->id, $member])->orderBy('id')->lockForUpdate()->get();
            DB::table('lgu_coordination_members')->where('circle_id', $circle)->whereIn('user_id', [$request->user()->id, $member])->orderBy('id')->lockForUpdate()->get();
            DB::table('user_lgu_assignments')->where('lgu_id', $circleRow->lgu_id)->whereIn('user_id', [$request->user()->id, $member])->orderBy('id')->lockForUpdate()->get();
            $this->authorizePair($request, $circle, $member);
            if (!empty($data['parent_id'])) {
                abort_unless($this->conversation($circle, (int) $request->user()->id, $member)->where('id', $data['parent_id'])->exists(), 422, 'Reply must belong to this private conversation.');
            }
            $id = DB::table('coordination_direct_messages')->insertGetId($data + [
                'circle_id' => $circle, 'sender_id' => $request->user()->id, 'recipient_id' => $member,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->authorizePair($request, $circle, $member);
            AuditLog::record('cpu_chat.direct_message_posted', 'coordination_direct_message', $id,
                ['circle_id' => $circle, 'recipient_id' => $member], $request->user()->id);

            return $id;
        });

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'private, no-store');
    }
}
