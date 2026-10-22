<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAccessScope;
use App\Services\LguAssignmentScope;
use App\Services\MdtGrantScope;
use App\Services\OperationalNotification;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/** Case-specific MDT coordination. It deliberately is not a general messenger. */
class MdtCollaborationController extends Controller
{
    private const CATEGORIES = ['case_summary', 'case_stage', 'case_plan', 'referral_service', 'selected_case_notes', 'selected_documents'];

    private const ACCESS_STATUSES = ['assigned', 'received', 'in_review', 'action_required', 'responded', 'completed', 'returned', 'revoked'];

    public function __construct(private readonly CaseAccessScope $scope, private readonly LguAssignmentScope $lgus, private readonly OperationalNotification $notifications, private readonly MdtGrantScope $grants) {}

    private function record(int $case): object
    {
        return DB::table('cases')->whereNull('deleted_at')->find($case) ?? abort(404);
    }

    private function manager(Request $request, int $case): void
    {
        abort_unless($request->user()->hasPermission('mdt.assign'), 403);
        $record = $this->record($case);
        $client = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
        abort_unless($client && $this->scope->allowsCase($record, $request->user(), $client->municipality, $client->province), 403);
    }

    private function activeGrant(Request $request, int $case): object
    {
        $this->record($case);
        $grant = $this->grants->forUser($request->user())->where('cases.id', $case)
            ->latest('mdt_case_access.id')->first(['mdt_case_access.*']);
        abort_unless($grant, 403, 'An active case-specific MDT assignment is required.');

        return $grant;
    }

    private function canViewDiscussion(Request $request, int $case): bool
    {
        if ($request->user()->hasPermission('mdt.assign')) {
            $this->manager($request, $case);

            return true;
        }
        $this->activeGrant($request, $case);

        return true;
    }

    private function markDiscussionRead(int $case, int $userId): void
    {
        $ids = DB::table('mdt_discussions')->where('case_id', $case)->pluck('id');
        foreach ($ids as $id) {
            DB::table('mdt_discussion_reads')->updateOrInsert(['discussion_id' => $id, 'user_id' => $userId], ['read_at' => now()]);
        }
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'cpu_member', 403);
        $grants = $this->grants->forUser($request->user())
            ->latest('mdt_case_access.assigned_at')->get(['mdt_case_access.id', 'mdt_case_access.case_id', 'mdt_case_access.professional_role', 'mdt_case_access.organization', 'mdt_case_access.purpose', 'mdt_case_access.status', 'mdt_case_access.access_expires_at', 'cases.case_number']);
        $lguCaseIds = $this->lgus->dashboardCaseIds($request->user());
        $lguSummary = [
            'referred_cases' => $grants->pluck('case_id')->unique()->count(),
            'pending_actions' => DB::table('mdt_actions')->whereIn('mdt_access_id', $grants->pluck('id'))->whereNotIn('status', ['completed'])->count(),
            'due_actions' => DB::table('mdt_actions')->whereIn('mdt_access_id', $grants->pluck('id'))->whereNotIn('status', ['completed'])->where('due_at', '<', now())->count(),
            'unread_discussions' => DB::table('mdt_discussions as discussion')->whereIn('discussion.case_id', $grants->pluck('case_id'))->whereNotExists(fn ($query) => $query->selectRaw('1')->from('mdt_discussion_reads as reads')->whereColumn('reads.discussion_id', 'discussion.id')->where('reads.user_id', $request->user()->id))->count(),
            'lgu_active_cases' => count($lguCaseIds),
        ];

        return Inertia::render('Mdt/Workspace', ['assignments' => $grants, 'lguSummary' => $lguSummary]);
    }

    public function caseData(Request $request, int $case)
    {
        $grant = $this->activeGrant($request, $case);
        $categories = json_decode($grant->allowed_categories, true) ?: [];
        $record = $this->record($case);
        $safe = ['case_number' => $record->case_number, 'status' => $record->status, 'stage' => $record->stage, 'grant' => $grant];
        if (in_array('case_summary', $categories, true)) {
            $safe['summary'] = ['category' => $record->category, 'priority' => $record->priority, 'opened_at' => $record->opened_at];
        }
        if (in_array('case_plan', $categories, true)) {
            $safe['plans'] = DB::table('case_plans')->where('case_id', $case)->get(['id', 'summary', 'status', 'review_due_at']);
        }
        if (in_array('referral_service', $categories, true)) {
            $safe['referrals'] = DB::table('referrals')->where('case_id', $case)->get(['id', 'service_category', 'status', 'referred_at']);
        }
        $safe['actions'] = DB::table('mdt_actions')->where('case_id', $case)->where('mdt_access_id', $grant->id)->orderBy('due_at')->get(['id', 'title', 'instructions', 'status', 'due_at', 'responded_at', 'completed_at']);
        $safe['notes'] = DB::table('mdt_case_notes')->where('case_id', $case)->where('mdt_access_id', $grant->id)->latest('created_at')->get(['id', 'previous_note_id', 'record_type', 'addendum_reason', 'category', 'findings', 'recommendation', 'status', 'finalized_at', 'created_at']);
        $safe['attachments'] = DB::table('mdt_attachments')->where('case_id', $case)->where('mdt_access_id', $grant->id)->whereNull('revoked_at')->latest()->get(['id', 'mdt_action_id', 'mdt_note_id', 'purpose_category', 'name', 'mime_type', 'size_bytes', 'created_at']);
        $safe['unread_discussions'] = DB::table('mdt_discussions as discussion')->where('discussion.case_id', $case)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('mdt_discussion_reads as reads')
                ->whereColumn('reads.discussion_id', 'discussion.id')->where('reads.user_id', $request->user()->id))->count();

        // No Health/SRH, Child Participation, assessments, client identity, or documents are exposed here.
        return response()->json($safe);
    }

    public function workspace(Request $request, int $case)
    {
        $this->activeGrant($request, $case);

        return Inertia::render('Mdt/CaseWorkspace', ['caseId' => $case]);
    }

    public function storeAccess(Request $request, int $case)
    {
        return $this->atomicWrite($request, $case, 'mdt.assign', true, false, function () use ($request, $case) {
            $this->manager($request, $case);
            $data = $request->validate(['user_id' => 'required|exists:users,id', 'professional_role' => 'required|string|max:120', 'organization' => 'required|string|max:180', 'purpose' => 'required|string|max:255', 'allowed_categories' => 'required|array|min:1', 'allowed_categories.*' => 'in:case_summary,case_stage,case_plan,referral_service,selected_case_notes,selected_documents', 'access_expires_at' => 'required|date|after:now', 'remarks' => 'nullable|string|max:2000', 'lawful_basis' => 'nullable|string|max:180']);
            $member = DB::table('users')->where('id', $data['user_id'])->where('role', 'cpu_member')->where('active', true)->first();
            abort_unless($member, 422, 'Member must be an active CPU member.');
            $record = $this->record($case);
            $client = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
            abort_unless($client && $this->memberMatchesCaseLgu($member, $record, $client->municipality, $client->province), 422, 'The MDT member must have an active assignment to this LGU.');
            $categories = $data['allowed_categories'];
            $data['allowed_categories'] = json_encode($categories, JSON_THROW_ON_ERROR);
            $id = DB::table('mdt_case_access')->insertGetId($data + ['case_id' => $case, 'status' => 'assigned', 'assigned_by' => $request->user()->id, 'assigned_at' => now(), 'access_starts_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->notifications->send($member->id, 'mdt_assignment', 'MDT assignment received', 'A case coordination assignment requires acknowledgement.', '/mdt/collaboration');
            $this->audit($request, $case, 'mdt.access_granted', $id, ['categories' => $categories, 'expires_at' => $data['access_expires_at']]);

            return response()->json(['id' => $id], 201);
        }, 'user_id');
    }

    public function acknowledge(Request $request, int $case, int $access)
    {
        return $this->atomicWrite($request, $case, 'mdt.access', false, false, function () use ($request, $case, $access) {
            $grant = $this->activeGrant($request, $case);
            abort_unless($grant->id === $access, 403);
            DB::table('mdt_case_access')->where('id', $access)->update(['status' => 'received', 'acknowledged_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.assignment_acknowledged', $access);

            return response()->noContent();
        });
    }

    public function revoke(Request $request, int $case, int $access)
    {
        return $this->atomicWrite($request, $case, 'mdt.assign', true, false, function () use ($request, $case, $access) {
            $this->manager($request, $case);
            $grant = DB::table('mdt_case_access')->where('case_id', $case)->find($access);
            abort_unless($grant, 404);
            DB::table('mdt_case_access')->where('id', $access)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.access_revoked', $access);

            return response()->noContent();
        });
    }

    public function storeAction(Request $request, int $case)
    {
        return $this->atomicWrite($request, $case, 'mdt.assign', true, false, function () use ($request, $case) {
            $this->manager($request, $case);
            $data = $request->validate(['mdt_access_id' => 'required|integer', 'title' => 'required|string|max:255', 'instructions' => 'nullable|string|max:3000', 'due_at' => 'nullable|date']);
            $memberId = DB::table('mdt_case_access')->where('case_id', $case)->where('id', $data['mdt_access_id'])->value('user_id');
            $grant = $memberId ? $this->grants->forUserId((int) $memberId)->where('cases.id', $case)->where('mdt_case_access.id', $data['mdt_access_id'])->first(['mdt_case_access.*']) : null;
            abort_unless($grant, 422, 'A current MDT assignment is required.');
            $id = DB::table('mdt_actions')->insertGetId($data + ['case_id' => $case, 'status' => 'action_required', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('mdt_case_access')->where('id', $grant->id)->update(['status' => 'action_required', 'updated_at' => now()]);
            $this->notifications->send($grant->user_id, 'mdt_action_due', 'MDT action assigned', 'A case coordination action requires your response.', '/mdt/collaboration');
            $this->audit($request, $case, 'mdt.action_assigned', $id, ['due_at' => $data['due_at'] ?? null]);

            return response()->json(['id' => $id], 201);
        }, 'mdt_access_id');
    }

    public function respondAction(Request $request, int $case, int $action)
    {
        return $this->atomicWrite($request, $case, 'mdt.access', false, $request->input('status') === 'completed', function () use ($request, $case, $action) {
            $grant = $this->activeGrant($request, $case);
            $item = DB::table('mdt_actions')->where('case_id', $case)->where('id', $action)->where('mdt_access_id', $grant->id)->first();
            abort_unless($item, 403);
            $data = $request->validate(['status' => 'required|in:responded,completed']);
            DB::table('mdt_actions')->where('id', $action)->update(['status' => $data['status'], 'responded_at' => now(), 'completed_at' => $data['status'] === 'completed' ? now() : null, 'updated_at' => now()]);
            $expiresOnCompletion = (bool) $grant->expire_on_completion;
            $grantStatus = $data['status'] === 'completed' && $expiresOnCompletion ? 'completed' : $data['status'];
            DB::table('mdt_case_access')->where('id', $grant->id)->update(['status' => $grantStatus, 'completed_at' => $data['status'] === 'completed' ? now() : null, 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.action_' . $data['status'], $action);

            return response()->noContent();
        });
    }

    public function discussion(Request $request, int $case)
    {
        $this->canViewDiscussion($request, $case);
        $this->markDiscussionRead($case, $request->user()->id);

        return response()->json(DB::table('mdt_discussions')->join('users', 'users.id', '=', 'mdt_discussions.author_id')->where('case_id', $case)->orderBy('mdt_discussions.created_at')->get(['mdt_discussions.id', 'mdt_discussions.parent_id', 'mdt_discussions.body', 'mdt_discussions.is_system_event', 'mdt_discussions.created_at', 'users.name as author', 'users.role as author_role']));
    }

    public function postDiscussion(Request $request, int $case)
    {
        return $this->atomicWrite($request, $case, 'mdt.discussion.create', false, false, function () use ($request, $case) {
            $grant = $this->activeGrant($request, $case);
            abort_unless($request->user()->hasPermission('mdt.discussion.create'), 403);
            $data = $request->validate(['body' => 'required|string|max:3000', 'parent_id' => 'nullable|integer']);
            if (!empty($data['parent_id'])) {
                abort_unless(DB::table('mdt_discussions')->where('case_id', $case)->where('id', $data['parent_id'])->exists(), 422);
            }
            $id = DB::table('mdt_discussions')->insertGetId($data + ['case_id' => $case, 'mdt_access_id' => $grant->id, 'author_id' => $request->user()->id, 'is_system_event' => false, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.discussion_posted', $id);

            return response()->json(['id' => $id], 201);
        });
    }

    public function storeNote(Request $request, int $case)
    {
        return $this->atomicWrite($request, $case, 'mdt.note.create', false, false, function () use ($request, $case) {
            $grant = $this->activeGrant($request, $case);
            abort_unless($request->user()->hasPermission('mdt.note.create'), 403);
            $data = $request->validate(['category' => 'required|string|max:100', 'action_performed' => 'nullable|string|max:5000', 'findings' => 'nullable|string|max:5000', 'recommendation' => 'nullable|string|max:5000', 'follow_up_needed' => 'nullable|string|max:3000']);
            $id = DB::table('mdt_case_notes')->insertGetId($data + ['case_id' => $case, 'mdt_access_id' => $grant->id, 'status' => 'draft', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.note_created', $id);

            return response()->json(['id' => $id], 201);
        });
    }

    public function finalizeNote(Request $request, int $case, int $note)
    {
        return $this->atomicWrite($request, $case, 'mdt.note.create', false, false, function () use ($request, $case, $note) {
            $grant = $this->activeGrant($request, $case);
            $item = DB::table('mdt_case_notes')->where('case_id', $case)->where('id', $note)->first();
            abort_unless($item && $item->mdt_access_id === $grant->id && $item->created_by === $request->user()->id && $item->status === 'draft', 403);
            DB::table('mdt_case_notes')->where('id', $note)->update(['status' => 'final', 'finalized_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.note_finalized', $note);

            return response()->noContent();
        });
    }

    public function addendum(Request $request, int $case, int $note)
    {
        return $this->atomicWrite($request, $case, 'mdt.note.create', false, false, function () use ($request, $case, $note) {
            $grant = $this->activeGrant($request, $case);
            $original = DB::table('mdt_case_notes')->where('case_id', $case)->where('id', $note)->where('status', 'final')->first();
            abort_unless($original && $original->mdt_access_id === $grant->id, 403);
            $data = $request->validate(['reason' => 'required|string|max:2000', 'category' => 'required|string|max:100', 'action_performed' => 'nullable|string|max:5000', 'findings' => 'nullable|string|max:5000', 'recommendation' => 'nullable|string|max:5000', 'follow_up_needed' => 'nullable|string|max:3000']);
            $id = DB::table('mdt_case_notes')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant->id, 'previous_note_id' => $original->id, 'record_type' => 'addendum', 'addendum_reason' => $data['reason'], 'category' => $data['category'], 'action_performed' => $data['action_performed'] ?? null, 'findings' => $data['findings'] ?? null, 'recommendation' => $data['recommendation'] ?? null, 'follow_up_needed' => $data['follow_up_needed'] ?? null, 'status' => 'final', 'finalized_at' => now(), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($request, $case, 'mdt.note_addendum_created', $id, ['original_note_id' => $original->id]);

            return response()->json(['id' => $id], 201);
        });
    }

    public function uploadAttachment(Request $request, int $case)
    {
        $grant = $this->activeGrant($request, $case);
        $data = $request->validate(['attachment' => 'required|file|max:10240|mimetypes:application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'purpose_category' => 'required|string|max:100', 'mdt_action_id' => 'nullable|integer', 'mdt_note_id' => 'nullable|integer']);
        if (!empty($data['mdt_action_id'])) {
            abort_unless(DB::table('mdt_actions')->where('case_id', $case)->where('mdt_access_id', $grant->id)->where('id', $data['mdt_action_id'])->exists(), 422);
        }
        if (!empty($data['mdt_note_id'])) {
            abort_unless(DB::table('mdt_case_notes')->where('case_id', $case)->where('mdt_access_id', $grant->id)->where('id', $data['mdt_note_id'])->exists(), 422);
        }
        $file = $data['attachment'];
        $disk = config('filesystems.default');
        $path = $file->store("mdt/$case/{$grant->id}/quarantine", $disk);
        $id = DB::table('mdt_attachments')->insertGetId(['case_id' => $case, 'mdt_access_id' => $grant->id, 'mdt_action_id' => $data['mdt_action_id'] ?? null, 'mdt_note_id' => $data['mdt_note_id'] ?? null, 'purpose_category' => $data['purpose_category'], 'name' => $file->getClientOriginalName(), 'disk' => $disk, 'path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'scan_state' => 'pending_scan', 'uploaded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $case, 'mdt.attachment_uploaded_quarantined', $id, ['purpose_category' => $data['purpose_category'], 'scan_state' => 'pending_scan']);

        return response()->json(['id' => $id], 201);
    }

    public function downloadAttachment(Request $request, int $case, int $attachment)
    {
        $grant = $this->activeGrant($request, $case);
        $file = DB::table('mdt_attachments')->where('case_id', $case)->where('mdt_access_id', $grant->id)->where('id', $attachment)->whereNull('revoked_at')->first();
        abort_unless($file, 404);
        abort_unless($file->scan_state === 'cleared', 423, 'Attachment is not available until scan clearance.');
        $this->audit($request, $case, 'mdt.attachment_downloaded', $file->id);

        return Storage::disk($file->disk)->download($file->path, $file->name);
    }

    private function audit(Request $request, int $case, string $event, int $id, array $changes = []): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'mdt', 'auditable_id' => $id, 'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Parent case, current authority, record changes and audit commit together. */
    private function atomicWrite(Request $request, int $case, string $permission, bool $manager, bool $completing, callable $write, ?string $targetKey = null)
    {
        $initialActor = User::find($request->user()->id);
        abort_unless($initialActor && $initialActor->active && $initialActor->hasPermission($permission), 403);
        $request->setUserResolver(fn () => $initialActor);
        if ($manager) {
            $this->manager($request, $case);
        } else {
            $this->activeGrant($request, $case);
        }

        return DB::transaction(function () use ($request, $case, $permission, $manager, $completing, $write, $targetKey) {
            DB::table('cases')->where('id', $case)->whereNull('deleted_at')->lockForUpdate()->first(['id']) ?? abort(404);
            $userIds = DB::table('mdt_case_access')->where('case_id', $case)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $userIds[] = (int) $request->user()->id;
            $target = $targetKey === 'user_id' ? $request->input('user_id') : null;
            if (is_int($target) || (is_string($target) && ctype_digit($target))) {
                $userIds[] = (int) $target;
            }
            $userIds = array_values(array_unique($userIds));
            sort($userIds);
            DB::table('users')->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('user_lgu_assignments')->whereIn('user_id', $userIds)->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('lgu_units')->whereIn('id', DB::table('user_lgu_assignments')->whereIn('user_id', $userIds)->select('lgu_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('mdt_case_access')->where('case_id', $case)->orderBy('id')->lockForUpdate()->get(['id']);
            $actorId = (int) $request->user()->id;
            $checkActor = function () use ($request, $actorId, $permission) {
                $actor = User::find($actorId);
                abort_unless($actor && $actor->active && $actor->hasPermission($permission), 403);
                $request->setUserResolver(fn () => $actor);

                return $actor;
            };
            $checkActor();
            $grant = $manager ? null : $this->activeGrant($request, $case);
            if ($manager) {
                $this->manager($request, $case);
            }
            $response = $write();
            $actor = $checkActor();
            if ($manager) {
                $this->manager($request, $case);
                if (is_int($target) || (is_string($target) && ctype_digit($target))) {
                    $member = User::find((int) $target);
                    $currentCase = $this->record($case);
                    $client = DB::table('clients')->find($currentCase->client_id, ['municipality', 'province']);
                    abort_unless($member && $member->active && $member->role === 'cpu_member' && $client && $this->memberMatchesCaseLgu($member, $currentCase, $client->municipality, $client->province), 422, 'The MDT member needs current eligibility for this case.');
                    abort_unless(Carbon::parse($request->input('access_expires_at'))->isFuture(), 422, 'The MDT assignment expiry must remain in the future.');
                }
                $actionGrantId = $targetKey === 'mdt_access_id' ? $request->input('mdt_access_id') : null;
                if (is_int($actionGrantId) || (is_string($actionGrantId) && ctype_digit($actionGrantId))) {
                    $memberId = DB::table('mdt_case_access')->where('id', $actionGrantId)->where('case_id', $case)->value('user_id');
                    abort_unless($memberId && $this->grants->forUserId((int) $memberId)->where('mdt_case_access.id', $actionGrantId)->where('cases.id', $case)->exists(), 422, 'A current MDT assignment is required.');
                }
            } else {
                $eligible = $completing ? $this->grants->forCompletingUserId($actor->id) : $this->grants->forUser($actor);
                abort_unless($eligible->where('cases.id', $case)->where('mdt_case_access.id', $grant->id)->exists(), 403, 'The case-specific MDT assignment is no longer eligible.');
            }
            DB::table('cases')->where('id', $case)->update(['offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);

            return $response;
        }, 3);
    }

    private function memberMatchesCaseLgu(object $member, object $case, ?string $municipality, ?string $province): bool
    {
        $user = User::find($member->id);
        if (!$user) {
            return false;
        }

        return $this->lgus->caseMatches($user, $case, $municipality, $province);
    }
}
