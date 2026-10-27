<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CalendarSharing;
use App\Services\CaseAccessScope;
use App\Services\CaseAssignmentEligibility;
use App\Services\NoteReferrals;
use App\Services\OperationalNotification;
use App\Services\ReferenceNumberService;
use App\Services\ReferralTracking;
use App\Services\WorkerTasks;
use App\Support\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OperationsController extends Controller
{
    public function __construct(private readonly ReferenceNumberService $references, private readonly CaseAccessScope $scope, private readonly OperationalNotification $notifications) {}

    /** Legacy report queue: a submitted report is not a case until accepted. */
    private const REPORT_TRANSITIONS = [
        'NEW' => ['FOR_SCREENING'],
        'FOR_SCREENING' => ['UNDER_VALIDATION', 'REJECTED', 'DUPLICATE', 'REFERRED_OUT'],
        'UNDER_VALIDATION' => ['VALIDATED', 'RETURNED', 'REJECTED', 'DUPLICATE', 'REFERRED_OUT'],
        'VALIDATED' => ['ACCEPTED', 'RETURNED', 'REJECTED'],
        'RETURNED' => ['FOR_SCREENING'],
        'ACCEPTED' => ['CASE_CREATED'],
    ];

    public const REFERRAL_TRANSITIONS = ['DRAFT' => ['PENDING'], 'PENDING' => ['SENT', 'WITHDRAWN'], 'SENT' => ['ACCEPTED', 'DECLINED', 'RETURNED', 'UNAVAILABLE'], 'ACCEPTED' => ['IN_PROGRESS', 'WITHDRAWN'], 'IN_PROGRESS' => ['COMPLETED', 'RETURNED', 'UNAVAILABLE']];

    public function reports(Request $r)
    {
        $this->authorize('reports.view');
        $items = DB::table('reports')->leftJoin('clients', 'clients.id', '=', 'reports.client_id');
        $this->scopeFieldWorkerReports($items, $r);

        return Inertia::render('Reports/Index', ['items' => $items->latest('reports.created_at')->get(['reports.*', 'clients.first_name', 'clients.last_name']), 'reportTransitions' => self::REPORT_TRANSITIONS]);
    }

    private function caseAccess(Request $r, int $case): void
    {
        $record = DB::table('cases')->find($case);
        abort_unless($record, 404);
        $this->authorize('cases.view');
        $location = DB::table('clients')->find($record->client_id, ['municipality', 'province']);
        abort_unless($location && $this->scope->allowsCase($record, $r->user(), $location->municipality, $location->province), 403);
    }

    private function scopeFieldWorkerReports($query, Request $r): void
    {
        $municipality = trim((string) $r->user()->municipality);
        if ($municipality === '') {
            $query->whereRaw('1 = 0');

            return;
        } $query->where('reports.is_public_submission', true)->where('reports.endorsed_municipality', $municipality);
    }

    private function reportAccess(Request $r, object $report): void
    {
        $this->authorize('reports.view');
        abort_unless($report->is_public_submission && trim((string) $report->endorsed_municipality) !== '' && $report->endorsed_municipality === $r->user()->municipality, 403);
    }

    private function hasSharingConsent(int $case): bool
    {
        return app(ReferralTracking::class)->hasSharingConsent($case);
    }

    /** The old task list now lives in the calendar. */
    public function tasks(Request $request)
    {
        $this->authorize('activities.manage');

        return redirect()->route('calendar.index');
    }

    public const EVENT_TYPES = ['home_visit', 'monitoring_visit', 'case_conference', 'assessment', 'medical', 'court', 'referral_follow_up', 'family_meeting', 'deadline', 'other'];

    public function calendar(Request $request)
    {
        $this->authorize('activities.manage');
        $user = $request->user();
        $service = app(WorkerTasks::class);
        $own = $service->query($user)->where(fn ($q) => $q->where('tasks.status', 'open')->orWhere('tasks.updated_at', '>=', now()->subDays(90)))
            ->orderBy('tasks.due_at')->get(['tasks.id', 'tasks.title', 'tasks.event_type', 'tasks.due_at', 'tasks.location', 'tasks.details', 'tasks.status', 'tasks.case_id', 'cases.case_number']);
        $events = $service->withIsoDates($own);
        $open = $service->query($user)->where('tasks.status', 'open')->whereNotNull('tasks.due_at');
        $now = now();
        $startOfToday = $now->copy()->timezone('Asia/Manila')->startOfDay()->utc();
        $endOfToday = $now->copy()->timezone('Asia/Manila')->endOfDay()->utc();

        $ownIds = $events->pluck('id')->all();
        $participants = DB::table('task_participants')->join('users', 'users.id', '=', 'task_participants.user_id')->whereIn('task_participants.task_id', $ownIds ?: [0])
            ->get(['task_participants.task_id', 'task_participants.user_id', 'task_participants.response', 'users.name'])->groupBy('task_id');
        $history = DB::table('task_history')->join('users', 'users.id', '=', 'task_history.actor_id')->whereIn('task_history.task_id', $ownIds ?: [0])->orderBy('task_history.id')
            ->get(['task_history.task_id', 'task_history.event', 'task_history.from_due_at', 'task_history.to_due_at', 'task_history.reason', 'task_history.created_at', 'users.name as actor'])->groupBy('task_id');
        $events = $events->map(function ($row) use ($participants, $history) {
            $row->participants = ($participants[$row->id] ?? collect())->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->name, 'response' => $p->response])->values();
            $row->history = ($history[$row->id] ?? collect())->values();
            $row->shared = false;

            return $row;
        });

        return Inertia::render('Calendar/Index', [
            'tasks' => $events->values()->merge($this->invitedActivities($user))->values(),
            'caseEvents' => $this->caseCalendarEvents($user),
            'availableCases' => $service->cases($user)->whereNotIn('cases.status', ['CLOSED', 'ARCHIVED'])->orderByDesc('cases.updated_at')->limit(300)->get(['cases.id', 'cases.case_number']),
            'summary' => ['open' => (clone $open)->count(), 'overdue' => (clone $open)->where('tasks.due_at', '<', $now)->count(),
                'dueToday' => (clone $open)->whereBetween('tasks.due_at', [$startOfToday, $endOfToday])->count()],
        ]);
    }

    /**
     * Activities other users invited this user to. The case number shows only
     * when the user may already open that case; an invitation never grants
     * case access.
     */
    private function invitedActivities($user): Collection
    {
        $eligibility = app(CaseAssignmentEligibility::class);
        $rows = DB::table('task_participants')->join('tasks', 'tasks.id', '=', 'task_participants.task_id')->join('users', 'users.id', '=', 'tasks.assigned_to')
            ->leftJoin('cases', 'cases.id', '=', 'tasks.case_id')
            ->where('task_participants.user_id', $user->id)->where('task_participants.response', '!=', 'removed')
            ->where(fn ($q) => $q->where('tasks.status', 'open')->orWhere('tasks.updated_at', '>=', now()->subDays(90)))
            ->get(['tasks.id', 'tasks.title', 'tasks.event_type', 'tasks.due_at', 'tasks.location', 'tasks.details', 'tasks.status', 'tasks.case_id', 'cases.case_number',
                'cases.lgu_id', 'cases.assigned_to as case_owner', 'cases.deleted_at', 'users.name as organizer', 'task_participants.response']);

        return $rows->map(function ($row) use ($user, $eligibility) {
            $case = $row->case_id && !$row->deleted_at ? DB::table('cases')->find($row->case_id) : null;
            $visible = $case && $eligibility->canRead($user, $case);

            return (object) ['id' => $row->id, 'title' => $row->title, 'event_type' => $row->event_type, 'due_at' => $row->due_at ? Carbon::parse($row->due_at)->toIso8601String() : null,
                'location' => $row->location, 'details' => $row->details, 'status' => $row->status, 'case_id' => $visible ? $row->case_id : null,
                'case_number' => $visible ? $row->case_number : ($row->case_id ? 'Case-linked activity' : null),
                'shared' => true, 'organizer' => $row->organizer, 'my_response' => $row->response, 'participants' => [], 'history' => []];
        });
    }

    /** Users the organizer can invite (name search), for the activity form. */
    public function inviteCandidates(Request $request)
    {
        $this->authorize('activities.manage');
        $data = $request->validate(['q' => 'nullable|string|max:80']);

        return response()->json(['users' => app(CalendarSharing::class)->candidates($request->user(), (string) ($data['q'] ?? ''))], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function respondTask(Request $request, int $task)
    {
        $this->authorize('activities.manage');
        $data = $request->validate(['response' => 'required|in:accepted,declined']);
        $row = DB::table('task_participants')->where('task_id', $task)->where('user_id', $request->user()->id)->where('response', '!=', 'removed')->first();
        abort_unless($row, 404);
        $record = DB::table('tasks')->find($task);
        abort_unless($record && $record->status === 'open', 422, 'This activity is no longer open.');
        DB::table('task_participants')->where('id', $row->id)->update(['response' => $data['response'], 'responded_at' => now(), 'updated_at' => now()]);
        DB::table('task_history')->insert(['task_id' => $task, 'event' => $data['response'], 'actor_id' => $request->user()->id, 'created_at' => now()]);
        $this->notifications->send((int) $record->assigned_to, 'calendar.response', 'Invitation ' . $data['response'], $request->user()->name . ' ' . $data['response'] . ' "' . $record->title . '".', '/calendar');
        AuditLog::record('task.' . $data['response'], 'task', $task, ['response' => $data['response']], $request->user()->id, 'success', ['description' => 'Calendar invitation ' . $data['response']]);

        return back()->with('success', $data['response'] === 'accepted' ? 'You accepted the invitation.' : 'You declined the invitation.');
    }

    /** Invites new participants and removes dropped ones; returns the newly invited ids. */
    private function syncParticipants(Request $r, int $task, array $wanted): array
    {
        $wanted = array_values(array_unique(array_map('intval', $wanted)));
        abort_unless(count($wanted) <= CalendarSharing::MAX_PARTICIPANTS, 422, 'Invite up to ' . CalendarSharing::MAX_PARTICIPANTS . ' people.');
        $current = DB::table('task_participants')->where('task_id', $task)->where('response', '!=', 'removed')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $added = array_values(array_diff($wanted, $current));
        if ($added && !app(CalendarSharing::class)->canInvite($r->user(), $added)) {
            throw ValidationException::withMessages(['participants' => 'You can invite only people who work in your area.']);
        }
        foreach ($added as $userId) {
            DB::table('task_participants')->updateOrInsert(['task_id' => $task, 'user_id' => $userId], ['response' => 'pending', 'invited_by' => $r->user()->id, 'responded_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $task, 'event' => 'invited', 'actor_id' => $r->user()->id, 'reason' => DB::table('users')->where('id', $userId)->value('name'), 'created_at' => now()]);
        }
        $removed = array_values(array_diff($current, $wanted));
        if ($removed) {
            DB::table('task_participants')->where('task_id', $task)->whereIn('user_id', $removed)->update(['response' => 'removed', 'updated_at' => now()]);
        }

        return $added;
    }

    /** Tells participants and the supervisor, Field Office and Project Manager about a moved or cancelled activity. */
    private function announceChange(Request $r, object $record, string $what, string $reason): void
    {
        $title = $what === 'cancelled' ? 'Activity cancelled' : 'Activity rescheduled';
        $participants = DB::table('task_participants')->where('task_id', $record->id)->whereIn('response', ['pending', 'accepted'])->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $owner = DB::table('users')->find($record->assigned_to);
        $watchers = $owner ? app(CalendarSharing::class)->watchers(User::find($owner->id)) : [];
        foreach (array_unique(array_merge($participants, $watchers)) as $userId) {
            if ($userId === (int) $r->user()->id) {
                continue;
            }
            $this->notifications->send($userId, 'calendar.' . $what, $title, ($owner->name ?? 'A user') . ' ' . $what . ' "' . $record->title . '". Reason: ' . mb_strimwidth($reason, 0, 160, '...'), '/calendar');
        }
    }

    /**
     * Read-only dates that come from case records, so the worker sees them without
     * re-typing: scheduled case conferences, safety reviews, care plan reviews and
     * referral follow-ups. Limited to cases the user may open.
     */
    private function caseCalendarEvents($user): array
    {
        $service = app(WorkerTasks::class);
        $caseIds = $service->cases($user)->whereNotIn('cases.status', ['CLOSED', 'ARCHIVED'])->pluck('cases.id');
        if ($caseIds->isEmpty()) {
            return [];
        }
        $from = now()->subDays(45);
        $to = now()->addDays(120);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $events = [];
        foreach (DB::table('case_conferences')->join('cases', 'cases.id', '=', 'case_conferences.case_id')->whereIn('case_conferences.case_id', $caseIds)
            ->whereNotNull('case_conferences.scheduled_at')->whereNull('case_conferences.completed_at')->whereBetween('case_conferences.scheduled_at', [$from, $to])
            ->get(['case_conferences.id', 'case_conferences.case_id', 'case_conferences.scheduled_at', 'case_conferences.conference_type', 'cases.case_number']) as $row) {
            $events[] = ['key' => 'conf-' . $row->id, 'type' => 'case_conference', 'title' => 'Case conference', 'at' => $iso($row->scheduled_at), 'case_id' => $row->case_id, 'case_number' => $row->case_number, 'source' => 'Case conference schedule'];
        }
        foreach (DB::table('cases')->whereIn('id', $caseIds)->whereNotNull('next_review_at')->whereBetween('next_review_at', [$from, $to])->get(['id', 'case_number', 'next_review_at']) as $row) {
            $events[] = ['key' => 'review-' . $row->id, 'type' => 'assessment', 'title' => 'Safety review due', 'at' => $iso($row->next_review_at), 'case_id' => $row->id, 'case_number' => $row->case_number, 'source' => 'Intake safety plan'];
        }
        foreach (DB::table('case_plans')->join('cases', 'cases.id', '=', 'case_plans.case_id')->whereIn('case_plans.case_id', $caseIds)->where('case_plans.is_current', true)
            ->whereNotNull('case_plans.review_due_at')->whereBetween('case_plans.review_due_at', [$from, $to])->get(['case_plans.id', 'case_plans.case_id', 'case_plans.review_due_at', 'cases.case_number']) as $row) {
            $events[] = ['key' => 'plan-' . $row->id, 'type' => 'deadline', 'title' => 'Care plan review due', 'at' => $iso($row->review_due_at), 'case_id' => $row->case_id, 'case_number' => $row->case_number, 'source' => 'Care plan'];
        }
        $targets = app(ReferralTracking::class)->targets();
        foreach (DB::table('referrals')->join('cases', 'cases.id', '=', 'referrals.case_id')->whereIn('referrals.case_id', $caseIds)->whereIn('referrals.status', ['SENT', 'ACCEPTED', 'IN_PROGRESS'])
            ->get(['referrals.id', 'referrals.case_id', 'referrals.status', 'referrals.service_category', 'referrals.referred_at', 'referrals.accepted_at', 'referrals.created_at', 'cases.case_number']) as $row) {
            $accepted = in_array($row->status, ['ACCEPTED', 'IN_PROGRESS'], true) && $row->accepted_at;
            $start = $accepted ? $row->accepted_at : ($row->referred_at ?? $row->created_at);
            $due = Carbon::parse($start)->addDays($accepted ? $targets['completion_days'] : $targets['response_days']);
            if ($due->lt($from) || $due->gt($to)) {
                continue;
            }
            $events[] = ['key' => 'ref-' . $row->id, 'type' => 'referral_follow_up', 'title' => ($accepted ? 'Service completion due: ' : 'Referral response due: ') . $row->service_category, 'at' => $due->toIso8601String(), 'case_id' => $row->case_id, 'case_number' => $row->case_number, 'source' => 'Referral tracking'];
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    public function storeReferral(Request $r)
    {
        $this->authorize('referrals.create');
        $d = $r->validate(['case_id' => 'required|integer', 'service_category' => 'required|string|max:255', 'reason' => 'nullable|string|max:5000', 'lawful_basis' => 'required|in:consent,legal_obligation,vital_interests,public_task']);
        $this->caseAccess($r, (int) $d['case_id']);
        if ($d['lawful_basis'] === 'consent' && !$this->hasSharingConsent((int) $d['case_id'])) {
            throw ValidationException::withMessages(['lawful_basis' => 'This case has no active consent that allows sharing. Record it in the case (Client & Intake, Consent register) or choose another documented basis.']);
        }$id = DB::transaction(function () use ($d, $r) {
            $id = DB::table('referrals')->insertGetId($d + ['sharing_authorized_by' => $r->user()->id, 'sharing_authorized_at' => now(), 'status' => 'DRAFT', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('referral_events')->insert(['referral_id' => $id, 'from_status' => null, 'to_status' => 'DRAFT', 'actor_id' => $r->user()->id, 'created_at' => now()]);

            return $id;
        });
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'referral.created', 'auditable_type' => 'referral', 'auditable_id' => $id, 'changes' => json_encode(['lawful_basis' => $d['lawful_basis']]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Referral draft created.');
    }

    public function storeTask(Request $r)
    {
        $this->authorize('activities.manage');
        $d = $r->validate(['title' => 'required|string|max:255', 'event_type' => 'nullable|in:' . implode(',', self::EVENT_TYPES), 'case_id' => 'nullable|integer',
            'due_at' => 'nullable|date', 'location' => 'nullable|string|max:255', 'details' => 'nullable|string|max:2000',
            'participants' => 'nullable|array|max:' . CalendarSharing::MAX_PARTICIPANTS, 'participants.*' => 'integer']);
        if (!empty($d['case_id'])) {
            $this->caseAccess($r, (int) $d['case_id']);
        }
        $participants = $d['participants'] ?? [];
        unset($d['participants']);
        $d['due_at'] = !empty($d['due_at']) ? Carbon::parse($d['due_at'], 'Asia/Manila')->utc() : null;
        [$id, $invited] = DB::transaction(function () use ($d, $r, $participants) {
            $id = DB::table('tasks')->insertGetId($d + ['assigned_to' => $r->user()->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('task_history')->insert(['task_id' => $id, 'event' => 'created', 'actor_id' => $r->user()->id, 'to_due_at' => $d['due_at'], 'created_at' => now()]);

            return [$id, $this->syncParticipants($r, $id, $participants)];
        });
        foreach ($invited as $userId) {
            $this->notifications->send($userId, 'calendar.invited', 'Calendar invitation', $r->user()->name . ' invited you to "' . $d['title'] . '".', '/calendar');
        }
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'task.created', 'auditable_type' => 'task', 'auditable_id' => $id, 'changes' => json_encode(['participants' => count($invited)]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', $invited ? 'Activity added and invitations sent.' : 'Activity added to your calendar.');
    }

    public function updateTask(Request $r, int $task)
    {
        $this->authorize('activities.manage');
        $record = DB::table('tasks')->where('id', $task)->where('assigned_to', $r->user()->id)->first();
        abort_unless($record, 403);
        abort_unless($record->status === 'open', 422, 'Only open activities can be edited.');
        $d = $r->validate(['title' => 'required|string|max:255', 'event_type' => 'nullable|in:' . implode(',', self::EVENT_TYPES), 'case_id' => 'nullable|integer',
            'due_at' => 'nullable|date', 'location' => 'nullable|string|max:255', 'details' => 'nullable|string|max:2000',
            'participants' => 'nullable|array|max:' . CalendarSharing::MAX_PARTICIPANTS, 'participants.*' => 'integer', 'reason' => 'nullable|string|max:500']);
        if (!empty($d['case_id'])) {
            $this->caseAccess($r, (int) $d['case_id']);
        }
        $d['due_at'] = !empty($d['due_at']) ? Carbon::parse($d['due_at'], 'Asia/Manila')->utc() : null;
        $old = $record->due_at ? Carbon::parse($record->due_at) : null;
        $moved = ($old?->toIso8601String()) !== ($d['due_at']?->toIso8601String());
        $reason = trim((string) ($d['reason'] ?? ''));
        // Owner rule: moving an activity needs a reason, which is kept and shared.
        if ($moved && $old && mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for moving this activity (at least 5 characters).']);
        }
        $participants = $d['participants'] ?? null;
        unset($d['participants'], $d['reason']);
        $invited = DB::transaction(function () use ($d, $r, $task, $moved, $old, $reason, $participants) {
            DB::table('tasks')->where('id', $task)->update($d + ['updated_at' => now()]);
            if ($moved && $old) {
                DB::table('task_history')->insert(['task_id' => $task, 'event' => 'rescheduled', 'actor_id' => $r->user()->id, 'from_due_at' => $old, 'to_due_at' => $d['due_at'], 'reason' => $reason, 'created_at' => now()]);
            }

            return $participants === null ? [] : $this->syncParticipants($r, $task, $participants);
        });
        foreach ($invited as $userId) {
            $this->notifications->send($userId, 'calendar.invited', 'Calendar invitation', $r->user()->name . ' invited you to "' . $d['title'] . '".', '/calendar');
        }
        if ($moved && $old) {
            $this->announceChange($r, (object) ['id' => $task, 'title' => $d['title'], 'assigned_to' => $record->assigned_to], 'rescheduled', $reason);
            AuditLog::record('task.rescheduled', 'task', $task, ['reason' => $reason], $r->user()->id, 'success',
                ['description' => 'Activity rescheduled: ' . mb_strimwidth($reason, 0, 120, '...'), 'old_value' => $old->timezone('Asia/Manila')->format('Y-m-d H:i'), 'new_value' => $d['due_at']?->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') ?? 'No date']);
        } else {
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'task.updated', 'auditable_type' => 'task', 'auditable_id' => $task, 'created_at' => now(), 'updated_at' => now()]);
        }

        return back()->with('success', $moved && $old ? 'Activity rescheduled; the people concerned were notified.' : 'Activity updated.');
    }

    public function transitionTask(Request $r, int $task)
    {
        $this->authorize('activities.manage');
        $d = $r->validate(['status' => 'required|in:open,completed,cancelled', 'reason' => 'nullable|string|max:500']);
        $reason = trim((string) ($d['reason'] ?? ''));
        if ($d['status'] === 'cancelled' && mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => 'Give the reason for cancelling this activity (at least 5 characters).']);
        }
        $record = DB::table('tasks')->where('id', $task)->where('assigned_to', $r->user()->id)->first();
        abort_unless($record, 403);
        if ($record->case_id) {
            $this->caseAccess($r, (int) $record->case_id);
        }abort_unless($record->status === 'open' && in_array($d['status'], ['completed', 'cancelled'], true), 422, 'Invalid task transition.');
        DB::table('tasks')->where('id', $task)->update(['status' => $d['status'], 'updated_at' => now()]);
        DB::table('task_history')->insert(['task_id' => $task, 'event' => $d['status'], 'actor_id' => $r->user()->id, 'reason' => $reason ?: null, 'created_at' => now()]);
        if ($d['status'] === 'cancelled') {
            $this->announceChange($r, $record, 'cancelled', $reason);
            AuditLog::record('task.cancelled', 'task', $task, ['reason' => $reason], $r->user()->id, 'success', ['description' => 'Activity cancelled: ' . mb_strimwidth($reason, 0, 120, '...'), 'old_value' => 'open', 'new_value' => 'cancelled']);
        }
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'task.transitioned', 'auditable_type' => 'task', 'auditable_id' => $task, 'changes' => json_encode(['from' => 'open', 'to' => $d['status']]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Task status updated.');
    }

    public function transitionReport(Request $r, int $report)
    {
        $d = $r->validate(['status' => 'required|string', 'remarks' => 'nullable|string|max:5000', 'decision_reason' => 'nullable|string|max:5000', 'duplicate_of_report_id' => 'nullable|integer|exists:reports,id', 'referred_out_to' => 'nullable|string|max:255']);
        $record = DB::table('reports')->find($report);
        $record && $this->reportAccess($r, $record);
        abort_unless($record && in_array($d['status'], self::REPORT_TRANSITIONS[$record->status] ?? [], true), 422, 'Invalid report transition.');
        $permission = match ($d['status']) {
            'UNDER_VALIDATION','FOR_SCREENING','RETURNED' => 'reports.screen', 'VALIDATED' => 'reports.validate', 'ACCEPTED' => 'reports.accept', default => 'reports.reject'
        };
        $this->authorize($permission);
        $change = ['status' => $d['status'], 'updated_at' => now()];
        if ($d['status'] === 'UNDER_VALIDATION') {
            $change += ['screened_at' => now(), 'screened_by' => $r->user()->id, 'screening_remarks' => $d['remarks'] ?? null];
        }
        if ($d['status'] === 'VALIDATED') {
            $change += ['validated_at' => now(), 'validated_by' => $r->user()->id, 'validation_remarks' => $d['remarks'] ?? null];
        }
        if (in_array($d['status'], ['ACCEPTED', 'REJECTED', 'DUPLICATE', 'REFERRED_OUT'], true)) {
            $change += ['decided_at' => now(), 'decided_by' => $r->user()->id, 'decision_reason' => $d['decision_reason'] ?? $d['remarks'] ?? null];
        }
        if ($d['status'] === 'DUPLICATE') {
            $change['duplicate_of_report_id'] = $d['duplicate_of_report_id'] ?? null;
        }
        if ($d['status'] === 'REFERRED_OUT') {
            $change['referred_out_to'] = $d['referred_out_to'] ?? null;
        }
        DB::table('reports')->where('id', $report)->update($change);
        AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'report.transitioned', 'auditable_type' => 'report', 'auditable_id' => $report, 'changes' => json_encode(['from' => $record->status, 'to' => $d['status'], 'remarks' => $d['remarks'] ?? null]), 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Report status updated.');
    }

    public function createCaseFromReport(Request $r, int $report)
    {
        $this->authorize('reports.accept');
        $this->authorize('cases.create');
        $d = $r->validate(['first_name' => 'required|string|max:80', 'last_name' => 'required|string|max:80', 'birthdate' => 'nullable|date', 'sex' => 'nullable|in:Male,Female', 'region' => 'nullable|string|max:120', 'province' => 'nullable|string|max:120', 'barangay' => 'nullable|string|max:120', 'municipality' => 'nullable|string|max:120', 'category' => 'nullable|string|max:80']);
        $case = DB::transaction(function () use ($d, $r, $report) {
            $record = DB::table('reports')->where('id', $report)->lockForUpdate()->first();
            abort_unless($record, 404);
            $this->reportAccess($r, $record);
            abort_unless($record->status === 'ACCEPTED', 422, 'Only an accepted report without a case folder may be converted.');
            abort_unless(!DB::table('cases')->where('report_id', $report)->exists(), 409, 'A case folder already exists for this report.');
            $clientId = $record->client_id ?: DB::table('clients')->insertGetId(collect($d)->only(['first_name', 'last_name', 'birthdate', 'sex', 'region', 'province', 'barangay', 'municipality'])->merge(['created_at' => now(), 'updated_at' => now()])->all());
            $number = $this->references->nextCase();
            $id = DB::table('cases')->insertGetId(['case_number' => $number, 'client_id' => $clientId, 'report_id' => $report, 'assigned_to' => $r->user()->id, 'status' => 'OPEN', 'stage' => 'intake', 'priority' => $record->priority, 'category' => $d['category'] ?? null, 'opened_at' => now(), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('reports')->where('id', $report)->update(['client_id' => $clientId, 'status' => 'CASE_CREATED', 'updated_at' => now()]);
            DB::table('case_status_history')->insert(['case_id' => $id, 'from_status' => null, 'to_status' => 'OPEN', 'changed_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::insert(['user_id' => $r->user()->id, 'event' => 'report.case_created', 'auditable_type' => 'report', 'auditable_id' => $report, 'changes' => json_encode(['case_id' => $id]), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        return redirect()->route('cases.show', $case)->with('success', 'Accepted report converted into a case folder.');
    }

    public function transitionReferral(Request $request, int $referral)
    {
        $data = $request->validate([
            'status' => 'required|string|max:40', 'expected_status' => 'nullable|string|max:40',
            'remarks' => 'nullable|string|max:2000',
            'outcome' => 'nullable|string|max:5000',
            'reason' => 'nullable|string|max:2000',
        ]);
        $this->authorize($data['status'] === 'COMPLETED' ? 'referrals.close' : 'referrals.respond');
        DB::transaction(function () use ($request, $referral, $data) {
            $record = DB::table('referrals')->where('id', $referral)->lockForUpdate()->first();
            abort_unless($record, 404);
            $case = DB::table('cases')->where('id', $record->case_id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($case, 404);
            $this->caseAccess($request, $record->case_id);
            if (isset($data['expected_status']) && $data['expected_status'] !== $record->status) {
                throw ValidationException::withMessages(['status' => 'This referral changed. Refresh the page and review its current status before continuing.']);
            }
            abort_unless(in_array($data['status'], self::REFERRAL_TRANSITIONS[$record->status] ?? [], true), 422, 'Invalid referral transition.');
            // Checked after the transition itself is known to be allowed.
            if ($data['status'] === 'COMPLETED' && trim((string) ($data['outcome'] ?? '')) === '') {
                throw ValidationException::withMessages(['outcome' => 'Record the service the client actually received.']);
            }
            if (in_array($data['status'], ['DECLINED', 'RETURNED', 'UNAVAILABLE', 'WITHDRAWN'], true) && trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason' => 'Record why the referral did not proceed.']);
            }
            $change = ['status' => $data['status'], 'updated_at' => now()];
            if ($data['status'] === 'SENT') {
                $change['referred_at'] = now();
            }
            if ($data['status'] === 'ACCEPTED') {
                $change['accepted_at'] = now();
            }
            if ($data['status'] === 'COMPLETED') {
                $change['completed_at'] = now();
                $change['outcome'] = $data['outcome'];
            }
            if (!empty($data['reason'])) {
                $change['status_reason'] = $data['reason'];
            }
            DB::table('referrals')->where('id', $referral)->update($change);
            app(NoteReferrals::class)->closeAccessIfFinal($referral, $data['status']);
            DB::table('referral_events')->insert(['referral_id' => $referral, 'from_status' => $record->status, 'to_status' => $data['status'],
                'remarks' => $data['remarks'] ?? null, 'outcome' => $data['status'] === 'COMPLETED' ? $data['outcome'] : null,
                'reason' => $data['reason'] ?? null, 'actor_id' => $request->user()->id, 'created_at' => now()]);
            AuditLog::insert(['user_id' => $request->user()->id, 'event' => 'referral.transitioned',
                'auditable_type' => 'referral', 'auditable_id' => $referral,
                'changes' => json_encode(['from' => $record->status, 'to' => $data['status']]), 'created_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Referral status updated.');
    }
}
