<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard is a to-do list (owner instruction): only things the user
 * should act on, grouped by kind, each linking to where it is done. Figures
 * and charts live in Analytics. Items carry case numbers only (never names
 * or narratives).
 *
 * @phpstan-type Todo array{tone: string, title: string, text: string, href: string, when?: ?string}
 */
final class DashboardTodos
{
    public function __construct(private readonly MonitoringScope $monitoring) {}

    /** @return list<array{key: string, title: string, items: list<array<string, mixed>>}> */
    public function groups(User $user): array
    {
        $groups = [];
        $add = function (string $key, string $title, array $items) use (&$groups) {
            if ($items) {
                $groups[] = ['key' => $key, 'title' => $title, 'items' => array_values($items)];
            }
        };
        $add('invitations', 'Invitations to answer', $this->invitations($user));
        if ($user->hasPermission('closure.approve')) {
            $add('closures', 'Closures waiting for your approval', $this->closures($user));
        }
        if ($user->hasPermission('cases.monitor')) {
            $add('replies', 'Replies to your remarks', $this->replies($user));
            $add('watch', 'Cases to look at', $this->watchList($user));
        }
        if ($user->hasPermission('users.approve')) {
            $add('accounts', 'Accounts waiting for approval', $this->accounts($user));
        }
        if ($user->hasPermission('audits.view')) {
            $add('security', 'Security', $this->security());
        }
        if ($user->hasPermission('cases.create')) {
            $add('intakes', 'Saved intakes to finish', $this->savedIntakes($user));
        }

        return $groups;
    }

    private function invitations(User $user): array
    {
        return DB::table('task_participants')->join('tasks', 'tasks.id', '=', 'task_participants.task_id')->join('users', 'users.id', '=', 'tasks.assigned_to')
            ->where('task_participants.user_id', $user->id)->where('task_participants.response', 'pending')->where('tasks.status', 'open')
            ->where(fn ($q) => $q->whereNull('tasks.due_at')->orWhere('tasks.due_at', '>=', now()->subDay()))->orderBy('tasks.due_at')->limit(10)
            ->get(['tasks.title', 'tasks.due_at', 'users.name as organizer'])
            ->map(fn ($row) => ['tone' => 'action', 'title' => $row->title, 'text' => 'From ' . $row->organizer . '. Answer Going or Not going.', 'href' => route('calendar.index'),
                'when' => $row->due_at ? Carbon::parse($row->due_at)->toIso8601String() : null])->all();
    }

    private function closures(User $user): array
    {
        return $this->monitoring->scopeCases(DB::table('cases')->whereNull('cases.deleted_at')->where('cases.status', 'FOR_CLOSURE_REVIEW'), $user)
            ->orderBy('cases.updated_at')->limit(20)->get(['cases.id', 'cases.case_number', 'cases.updated_at'])
            ->map(fn ($row) => ['tone' => 'urgent', 'title' => 'Review closure of ' . $row->case_number, 'text' => 'The case manager recommends closing this case.',
                'href' => route('monitoring.cases.show', $row->id), 'when' => Carbon::parse($row->updated_at)->toIso8601String()])->all();
    }

    private function replies(User $user): array
    {
        return DB::table('notifications')->where('user_id', $user->id)->whereNull('read_at')->where('type', 'case.remark_reply')->latest()->limit(10)->get(['title', 'body', 'action_url', 'created_at'])
            ->map(fn ($row) => ['tone' => 'info', 'title' => $row->title, 'text' => $row->body, 'href' => $row->action_url ?: route('notifications.index'), 'when' => Carbon::parse($row->created_at)->toIso8601String()])->all();
    }

    /** Monitors: High Priority KaVAT cases and cases with no activity for 30 days (case numbers only). */
    private function watchList(User $user): array
    {
        $cases = $this->monitoring->scopeCases(DB::table('cases')->whereNull('cases.deleted_at')->where('cases.status', '!=', 'CLOSED'), $user);
        $items = [];
        $high = (clone $cases)->whereExists(fn ($q) => $q->selectRaw('1')->from('kavat_assessments as k')->whereColumn('k.case_id', 'cases.id')->where('k.status', 'final')->where('k.priority', 'high')
            ->whereRaw('k.id = (select max(k2.id) from kavat_assessments k2 where k2.case_id = k.case_id and k2.status = ?)', ['final']))->count();
        if ($high) {
            $items[] = ['tone' => 'urgent', 'title' => $high . ' High Priority ' . ($high === 1 ? 'case' : 'cases') . ' (KaVAT)', 'text' => 'Latest KaVAT protection score below 70.', 'href' => route('monitoring.cases.index')];
        }
        $stale = (clone $cases)->where(fn ($q) => $q->where('cases.last_activity_at', '<', now()->subDays(30))->orWhere(fn ($q) => $q->whereNull('cases.last_activity_at')->where('cases.opened_at', '<', now()->subDays(30))))->count();
        if ($stale) {
            $items[] = ['tone' => 'action', 'title' => $stale . ' ' . ($stale === 1 ? 'case has' : 'cases have') . ' no update in 30 days', 'text' => 'Consider a remark to the case manager.', 'href' => route('monitoring.cases.index')];
        }

        return $items;
    }

    private function accounts(User $user): array
    {
        // Same scope as Administration > Users: national (or system administrator) sees all; others their regions.
        $regions = $user->hasPermission('admin.manage') ? null : $this->monitoring->regions($user);
        $pending = DB::table('users')->where('account_status', 'pending')->when($regions !== null, fn ($q) => $q->whereIn('region', $regions ?: ['-']))->count();

        return $pending ? [['tone' => 'action', 'title' => $pending . ' ' . ($pending === 1 ? 'account is' : 'accounts are') . ' waiting for approval', 'text' => 'Approve or decline the registrations in your area.', 'href' => route('admin.index')]] : [];
    }

    private function security(): array
    {
        $alerts = DB::table('audit_logs')->where('log_type', 'security')->where('created_at', '>=', now()->subDay())->count();

        return $alerts ? [['tone' => 'urgent', 'title' => $alerts . ' security ' . ($alerts === 1 ? 'alert' : 'alerts') . ' in the last 24 hours', 'text' => 'Review them in the audit logs.', 'href' => route('admin.audits', ['type' => 'security'])]] : [];
    }

    private function savedIntakes(User $user): array
    {
        return collect(IntakeDrafts::list($user->id))->take(5)->map(fn ($draft) => ['tone' => 'action', 'title' => 'Finish a saved intake' . ($draft->label ? ': ' . $draft->label : ''),
            'text' => 'Step ' . ($draft->step ?? 1) . ' of 4. Saved intakes are deleted after ' . IntakeDrafts::KEEP_DAYS . ' days without changes.',
            'href' => route('cases.create', ['draft' => $draft->id]), 'when' => $draft->updated_at ? Carbon::parse($draft->updated_at)->toIso8601String() : null])->all();
    }
}
