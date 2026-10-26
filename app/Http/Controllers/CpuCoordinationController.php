<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\CoordinationAccess;
use App\Services\LguAssignmentScope;
use App\Services\MdtGrantScope;
use App\Services\OperationalNotification;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** LGU coordination chat. It is deliberately separate from case MDT discussion and formal records. */
class CpuCoordinationController extends Controller
{
    public function __construct(
        private readonly LguAssignmentScope $lgus,
        private readonly CaseAccessScope $cases,
        private readonly OperationalNotification $notifications,
        private readonly MdtGrantScope $grants,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('cpu.chat.view');
        $circles = $this->withConversationPreview($this->circlesFor($request->user()), $request->user()->id);
        $firstCircle = $circles->first();
        $unreadBeforeOpening = $firstCircle ? (int) $firstCircle->unread : 0;
        $messages = $firstCircle ? $this->circleMessages((int) $firstCircle->id, $request->user()) : collect();

        return Inertia::render('Cpu/Coordination', [
            'circles' => $circles,
            'members' => $circles->isEmpty() ? [] : $this->members((int) $firstCircle->id),
            'messages' => $messages,
            'pagination' => $firstCircle ? $this->pagination((int) $firstCircle->id, $messages) : ['has_more' => false, 'before' => null],
            'unread_before_opening' => $unreadBeforeOpening,
        ]);
    }

    public function messages(Request $request, int $circle)
    {
        $this->authorize('cpu.chat.view');
        $this->membership($request->user(), $circle);
        $data = $request->validate(['before' => 'nullable|integer|min:1']);
        $messages = $this->circleMessages($circle, $request->user(), $data['before'] ?? null);

        return response()->json(['messages' => $messages, 'members' => $this->members($circle), 'pagination' => $this->pagination($circle, $messages)]);
    }

    public function store(Request $request, int $circle)
    {
        $this->authorize('cpu.chat.post');
        $this->membership($request->user(), $circle);
        $data = $request->validate([
            'body' => 'required|string|max:3000', 'parent_id' => 'nullable|integer', 'case_id' => 'nullable|integer',
            'is_urgent' => 'boolean', 'follow_up' => 'boolean', 'mention_user_ids' => 'array', 'mention_user_ids.*' => 'integer|exists:users,id',
        ]);
        if (!empty($data['parent_id'])) {
            abort_unless(DB::table('lgu_coordination_messages')->where('circle_id', $circle)->where('id', $data['parent_id'])->exists(), 422);
        }

        $caseReference = null;
        if (!empty($data['case_id'])) {
            $case = DB::table('cases')->whereNull('deleted_at')->find($data['case_id']);
            abort_unless($case && $this->caseIsInCircle($case, $circle), 422, 'The case reference must belong to this LGU.');
            $caseReference = $case->case_number;
        }
        $id = DB::transaction(function () use ($data, $circle, $request, $caseReference) {
            DB::table('lgu_coordination_circles')->where('id', $circle)->lockForUpdate()->first();
            $id = DB::table('lgu_coordination_messages')->insertGetId([
                'circle_id' => $circle, 'author_id' => $request->user()->id, 'parent_id' => $data['parent_id'] ?? null,
                'case_id' => $data['case_id'] ?? null, 'case_reference' => $caseReference, 'body' => $data['body'],
                'is_urgent' => $data['is_urgent'] ?? false, 'follow_up' => $data['follow_up'] ?? false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $mentions = array_values(array_unique($data['mention_user_ids'] ?? []));
            foreach ($mentions as $userId) {
                abort_unless($this->isMember($userId, $circle), 422, 'Mentions must be active members of this LGU circle.');
                DB::table('lgu_coordination_message_mentions')->insert(['message_id' => $id, 'user_id' => $userId, 'created_at' => now()]);
                if ((int) $userId !== $request->user()->id) {
                    $this->notifications->send((int) $userId, 'cpu_chat_mention', 'CPU coordination mention', 'A coordination message needs your attention.', '/cpu/coordination');
                }
            }
            // Final eligibility check after every dependent write: a membership,
            // account or assignment change during the write prevents the commit.
            $this->assertFreshMember($request->user()->id, $circle);
            foreach ($mentions as $userId) {
                abort_unless($this->isMember((int) $userId, $circle), 422, 'Mentions must be active members of this LGU circle.');
            }
            $this->audit($request, 'cpu_chat.message_posted', $id, ['circle_id' => $circle, 'case_tagged' => $caseReference !== null, 'urgent' => (bool) ($data['is_urgent'] ?? false)]);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function search(Request $request, int $circle)
    {
        $this->authorize('cpu.chat.search');
        $this->membership($request->user(), $circle);
        $data = $request->validate(['q' => 'required|string|min:2|max:100', 'before' => 'nullable|integer|min:1']);
        $term = trim((string) $data['q']);
        $messages = $this->messageRows($circle, $request->user(), $term, $data['before'] ?? null);

        return response()->json(['messages' => $messages, 'pagination' => $this->pagination($circle, $messages, $term)]);
    }

    public function capture(Request $request, int $circle, int $message)
    {
        $this->authorize('cpu.chat.capture');
        $this->membership($request->user(), $circle);
        $source = DB::table('lgu_coordination_messages')->where('circle_id', $circle)->find($message);
        abort_unless($source, 404);
        $data = $request->validate([
            'case_id' => 'required|integer', 'record_type' => 'required|in:progress_note,mdt_note',
            'professional_summary' => 'required|string|max:5000', 'confirmed' => 'accepted',
        ]);
        $case = DB::table('cases')->whereNull('deleted_at')->find($data['case_id']);
        abort_unless($case && $this->caseAccess($request->user(), $case), 403, 'You are not authorized to record a note for this case.');
        abort_unless($this->caseIsInCircle($case, $circle), 422, 'The selected case is outside this LGU coordination circle.');

        $id = DB::transaction(function () use ($data, $request, $source, $circle, $message) {
            $locked = DB::table('cases')->where('id', $data['case_id'])->lockForUpdate()->first();
            abort_unless($locked && !$locked->deleted_at, 403, 'You are not authorized to record a note for this case.');
            if ($data['record_type'] === 'progress_note') {
                abort_unless($request->user()->hasPermission('cases.note'), 403);
                $id = DB::table('case_notes')->insertGetId([
                    'case_id' => $data['case_id'], 'body' => $data['professional_summary'], 'visibility' => 'CASE_NOTES',
                    'record_type' => 'progress_note', 'note_type' => 'coordination', 'occurred_at' => now(),
                    'source_type' => 'cpu_coordination_message', 'source_id' => $source->id,
                    'provenance_summary' => 'Captured from an LGU coordination message after professional review.',
                    'captured_at' => now(), 'finalized_at' => now(), 'finalized_by' => $request->user()->id,
                    'is_current' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                abort_unless($request->user()->role === 'cpu_member', 403, 'Only an authorized CPU member can create an MDT note.');
                $grant = $this->activeGrant($request->user()->id, (int) $data['case_id']);
                abort_unless($grant, 403, 'An active case-specific MDT grant is required.');
                $id = DB::table('mdt_case_notes')->insertGetId([
                    'case_id' => $data['case_id'], 'mdt_access_id' => $grant->id, 'category' => 'CPU coordination contribution',
                    'findings' => $data['professional_summary'], 'status' => 'draft', 'source_type' => 'cpu_coordination_message',
                    'source_id' => $source->id, 'provenance_summary' => 'Captured from an LGU coordination message after professional review.',
                    'captured_at' => now(), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Re-read account, membership, case and grant after the formal write.
            $actor = $this->assertFreshMember($request->user()->id, $circle);
            $case = DB::table('cases')->find($data['case_id']);
            abort_unless($case && !$case->deleted_at && $this->caseAccess($actor, $case), 403, 'You are not authorized to record a note for this case.');
            if ($data['record_type'] === 'progress_note') {
                abort_unless($actor->hasPermission('cases.note') && $actor->hasPermission('cpu.chat.capture'), 403);
            } else {
                abort_unless($actor->role === 'cpu_member' && $this->activeGrant($actor->id, (int) $data['case_id']), 403, 'An active case-specific MDT grant is required.');
            }
            DB::table('cases')->where('id', $data['case_id'])->update(['offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            $this->audit($request, 'cpu_chat.record_captured', $id, ['circle_id' => $circle, 'message_id' => $message, 'case_id' => $data['case_id'], 'record_type' => $data['record_type']]);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    /**
     * Minimum-necessary case-reference lookup for chat tags and reviewed capture.
     * Returns only id, case number and status for cases in this circle's LGU that
     * the user may already open (own assignment or a current MDT grant). It never
     * grants access, never returns client fields, and keeps the query out of URLs.
     */
    public function caseReferences(Request $request, int $circle)
    {
        $this->authorize('cpu.chat.view');
        $this->membership($request->user(), $circle);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'selected_id' => 'nullable|integer|min:1']);
        $lgu = DB::table('lgu_units')->find(DB::table('lgu_coordination_circles')->where('id', $circle)->value('lgu_id'));
        abort_unless($lgu, 404);
        $user = $request->user();
        $base = fn () => DB::table('cases')->join('clients', 'clients.id', '=', 'cases.client_id')->whereNull('cases.deleted_at')
            ->where(fn ($scope) => $scope->where('cases.lgu_id', $lgu->id)->orWhere(fn ($legacy) => $legacy->whereNull('cases.lgu_id')
                ->where('clients.municipality', $lgu->name)->where('clients.province', $lgu->province)))
            ->select('cases.*');
        $authorized = fn (object $case) => $user->role !== 'system_admin' && $this->caseAccess($user, $case);
        $term = trim((string) ($data['q'] ?? ''));
        $cases = [];
        $hasMore = false;
        $base()->when($term !== '', fn ($query) => $query->whereRaw('LOWER(cases.case_number) LIKE ?', ['%' . strtolower($term) . '%']))
            ->orderBy('cases.case_number')->orderBy('cases.id')
            ->chunk(200, function ($chunk) use (&$cases, &$hasMore, $authorized) {
                foreach ($chunk as $case) {
                    if (!$authorized($case)) {
                        continue;
                    }
                    if (count($cases) === 20) {
                        $hasMore = true;

                        return false;
                    }
                    $cases[] = ['id' => (int) $case->id, 'case_number' => $case->case_number, 'status' => $case->status];
                }
            });
        $selected = null;
        if (!empty($data['selected_id'])) {
            $case = $base()->where('cases.id', $data['selected_id'])->first();
            if ($case && $authorized($case)) {
                $selected = ['id' => (int) $case->id, 'case_number' => $case->case_number, 'status' => $case->status];
            }
        }

        return response()->json(['cases' => $cases, 'has_more' => $hasMore, 'selected_case' => $selected])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function createCircle(Request $request)
    {
        $this->authorize('cpu.chat.manage');
        $data = $request->validate(['lgu_id' => 'required|exists:lgu_units,id', 'name' => 'required|string|max:180']);
        $id = DB::table('lgu_coordination_circles')->insertGetId($data + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'cpu_chat.circle_created', $id, ['lgu_id' => $data['lgu_id']]);

        return response()->json(['id' => $id], 201);
    }

    public function addMember(Request $request, int $circle)
    {
        $this->authorize('cpu.chat.manage');
        $data = $request->validate(['user_id' => 'required|exists:users,id', 'membership_role' => 'nullable|string|max:80']);
        $circleRow = DB::table('lgu_coordination_circles')->find($circle);
        abort_unless($circleRow && $circleRow->active, 404);
        $user = User::findOrFail($data['user_id']);
        abort_unless($this->userMatchesCircleLgu($user, $circle), 422, 'The user needs an active assignment to this LGU.');
        DB::table('lgu_coordination_members')->updateOrInsert(['circle_id' => $circle, 'user_id' => $user->id], ['membership_role' => $data['membership_role'] ?? 'member', 'joined_at' => now(), 'left_at' => null, 'configured_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        $this->audit($request, 'cpu_chat.member_configured', $user->id, ['circle_id' => $circle]);

        return response()->noContent();
    }

    private function circlesFor(User $user)
    {
        return DB::table('lgu_coordination_circles as circles')->join('lgu_coordination_members as members', 'members.circle_id', '=', 'circles.id')
            ->join('lgu_units as lgu', 'lgu.id', '=', 'circles.lgu_id')->where('members.user_id', $user->id)->whereNull('members.left_at')->where('circles.active', true)
            ->where('lgu.active', true)->whereIn('circles.lgu_id', $this->lgus->activeLguIds($user))
            ->where(fn ($dates) => $dates->whereNull('members.joined_at')->orWhere('members.joined_at', '<=', now()))
            ->orderBy('lgu.name')->get(['circles.id', 'circles.lgu_id', 'circles.name', 'lgu.name as lgu_name']);
    }

    /** Conversation-list preview: member count, latest message (short excerpt) and unread count per circle the user already belongs to. */
    private function withConversationPreview($circles, int $userId)
    {
        $ids = $circles->pluck('id');
        if ($ids->isEmpty()) {
            return $circles;
        }
        $latest = DB::table('lgu_coordination_messages as message')->join('users as author', 'author.id', '=', 'message.author_id')
            ->whereIn('message.id', DB::table('lgu_coordination_messages')->whereIn('circle_id', $ids)->groupBy('circle_id')->selectRaw('MAX(id)'))
            ->get(['message.circle_id', 'message.body', 'message.created_at', 'author.name as author'])->keyBy('circle_id');

        return $circles->map(function ($circle) use ($latest, $userId) {
            $last = $latest[$circle->id] ?? null;
            $circle->member_count = $this->eligibleMembers((int) $circle->id)->count();
            $circle->last_author = $last?->author;
            $circle->last_excerpt = $last ? mb_strimwidth(preg_replace('/\s+/', ' ', (string) $last->body), 0, 80, '...') : null;
            $circle->last_at = $last?->created_at;
            $circle->unread = $this->unreadCount((int) $circle->id, $userId);

            return $circle;
        })->values();
    }

    /** Fresh account and membership read inside the current transaction. */
    private function assertFreshMember(int $userId, int $circle): User
    {
        $actor = User::query()->whereKey($userId)->where('active', true)->first();
        abort_unless($actor && $this->isMember($actor->id, $circle) && $this->userMatchesCircleLgu($actor, $circle), 403, 'Active LGU circle membership is required.');

        return $actor;
    }

    private function membership(User $user, int $circle): void
    {
        abort_unless($this->isMember($user->id, $circle) && $this->userMatchesCircleLgu($user, $circle), 403, 'Active LGU circle membership is required.');
    }

    private function isMember(int $userId, int $circle): bool
    {
        return $this->eligibleMembers($circle)->where('users.id', $userId)->exists();
    }

    private function eligibleMembers(int $circle)
    {
        return app(CoordinationAccess::class)->members($circle);
    }

    private function members(int $circle)
    {
        return $this->eligibleMembers($circle)->orderBy('users.name')->get(['users.id', 'users.name', 'users.role', 'users.cpu_discipline', 'member.membership_role']);
    }

    private function circleMessages(int $circle, User $user, ?int $before = null)
    {
        $messages = $this->messageRows($circle, $user, null, $before);
        $this->markRead($user->id, $messages->pluck('id'));

        return $messages;
    }

    private function messageRows(int $circle, User $user, ?string $term = null, ?int $before = null)
    {
        $rows = DB::table('lgu_coordination_messages as message')->join('users as author', 'author.id', '=', 'message.author_id')
            ->where('message.circle_id', $circle)->when($term, fn ($query) => $query->whereRaw('LOWER(message.body) LIKE ?', ['%' . strtolower($term) . '%']))
            ->when($before, fn ($query) => $query->where('message.id', '<', $before))
            ->orderByDesc('message.id')->limit(50)->get(['message.id', 'message.parent_id', 'message.case_id', 'message.case_reference', 'message.body', 'message.is_urgent', 'message.follow_up', 'message.created_at', 'message.author_id', 'author.name as author', 'author.role as author_role'])->reverse()->values();
        $mentions = DB::table('lgu_coordination_message_mentions as mention')->join('users', 'users.id', '=', 'mention.user_id')
            ->whereIn('mention.message_id', $rows->pluck('id'))->get(['mention.message_id', 'users.id', 'users.name'])->groupBy('message_id');
        $caseAccess = DB::table('cases')->whereIn('id', $rows->pluck('case_id')->filter()->unique())->get()->mapWithKeys(fn ($case) => [$case->id => $this->caseAccess($user, $case)]);

        return $rows->map(function ($message) use ($mentions, $caseAccess, $user) {
            $message->can_open_case = $caseAccess[$message->case_id] ?? false;
            $message->is_own = (int) $message->author_id === (int) $user->id;
            unset($message->author_id);
            $message->mentions = ($mentions[$message->id] ?? collect())->map(fn ($mention) => ['id' => $mention->id, 'name' => $mention->name]);

            return $message;
        });
    }

    private function pagination(int $circle, $messages, ?string $term = null): array
    {
        $before = $messages->first()?->id;

        return ['before' => $before, 'has_more' => $before !== null && DB::table('lgu_coordination_messages')->where('circle_id', $circle)
            ->when($term, fn ($query) => $query->whereRaw('LOWER(body) LIKE ?', ['%' . strtolower($term) . '%']))->where('id', '<', $before)->exists()];
    }

    private function markRead(int $userId, $allIds): void
    {
        if ($allIds->isEmpty()) {
            return;
        }

        $alreadyRead = DB::table('lgu_coordination_message_reads')
            ->where('user_id', $userId)
            ->whereIn('message_id', $allIds)
            ->pluck('message_id');

        $unread = $allIds->diff($alreadyRead);
        if ($unread->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $unread->map(fn ($id) => ['message_id' => $id, 'user_id' => $userId, 'read_at' => $now])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('lgu_coordination_message_reads')->insert($chunk);
        }
    }

    private function unreadCount(int $circle, int $userId): int
    {
        return DB::table('lgu_coordination_messages as message')
            ->leftJoin('lgu_coordination_message_reads as read', fn ($join) => $join->on('read.message_id', '=', 'message.id')->where('read.user_id', $userId))
            ->where('message.circle_id', $circle)->where('message.author_id', '!=', $userId)->whereNull('read.read_at')->count();
    }

    private function caseIsInCircle(object $case, int $circle): bool
    {
        $lgu = DB::table('lgu_coordination_circles')->where('id', $circle)->value('lgu_id');
        if ((int) ($case->lgu_id ?? 0) > 0) {
            return (int) $case->lgu_id === (int) $lgu;
        }
        $municipality = DB::table('clients')->where('id', $case->client_id)->value('municipality');

        return DB::table('lgu_units')->where('id', $lgu)->where('name', $municipality)->exists();
    }

    private function userMatchesCircleLgu(User $user, int $circle): bool
    {
        $lgu = (int) DB::table('lgu_coordination_circles')->where('id', $circle)->value('lgu_id');

        return in_array($lgu, $this->lgus->activeLguIds($user), true);
    }

    private function caseAccess(User $user, ?object $case): bool
    {
        if (!$case || $case->deleted_at) {
            return false;
        }
        $client = DB::table('clients')->find($case->client_id, ['municipality', 'province']);
        if ($client && $this->cases->allowsCase($case, $user, $client->municipality, $client->province)) {
            return true;
        }

        return $this->grants->forUser($user)->where('cases.id', $case->id)->exists();
    }

    private function activeGrant(int $userId, int $caseId): ?object
    {
        $user = User::find($userId);

        return $user ? $this->grants->forUser($user)->where('cases.id', $caseId)->latest('mdt_case_access.id')->first(['mdt_case_access.*']) : null;
    }

    private function audit(Request $request, string $event, int $id, array $changes = []): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'cpu_coordination', 'auditable_id' => $id, 'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);
    }
}
