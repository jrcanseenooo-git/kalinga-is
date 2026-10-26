<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Remarks on a case from the LGU Head, Field Office or Project Manager, and
 * the case manager's replies. Remarks are kept as written (no edit or delete).
 */
final class CaseRemarks
{
    public const KINDS = ['remark', 'reply', 'closure_approved', 'closure_returned'];

    public function __construct(private readonly OperationalNotification $notifications) {}

    public function add(object $case, User $author, string $body, string $kind = 'remark', ?int $parentId = null): int
    {
        $id = DB::table('case_remarks')->insertGetId(['case_id' => $case->id, 'author_id' => $author->id, 'author_role' => $author->role, 'parent_id' => $parentId,
            'kind' => $kind, 'body' => trim($body), 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('case_remark.created', 'case', $case->id, ['case_id' => $case->id, 'kind' => $kind], $author->id, 'success',
            ['description' => $kind === 'reply' ? 'Replied to a remark' : 'Remark added by ' . (RolePermissions::ROLES[$author->role][0] ?? $author->role)]);

        if ($kind === 'reply' && $parentId) {
            $to = DB::table('case_remarks')->where('id', $parentId)->value('author_id');
            if ($to && (int) $to !== $author->id) {
                $this->notifications->send((int) $to, 'case.remark_reply', 'Reply to your remark', "The case manager replied to your remark on {$case->case_number}.", route('monitoring.cases.show', $case->id));
            }
        } elseif ($case->assigned_to && (int) $case->assigned_to !== $author->id) {
            $title = ['closure_approved' => 'Closure approved', 'closure_returned' => 'Closure returned'][$kind] ?? 'New remark on your case';
            $this->notifications->send((int) $case->assigned_to, 'case.remark', $title, "{$title} on {$case->case_number}.", route('cases.show', $case->id) . '?section=overview#case-remarks');
        }

        return $id;
    }

    /** @return list<array<string, mixed>> remarks with their replies, newest first */
    public function thread(int $caseId): array
    {
        $rows = DB::table('case_remarks')->leftJoin('users', 'users.id', '=', 'case_remarks.author_id')->where('case_id', $caseId)
            ->orderBy('case_remarks.created_at')->orderBy('case_remarks.id')
            ->get(['case_remarks.id', 'case_remarks.parent_id', 'case_remarks.kind', 'case_remarks.body', 'case_remarks.author_role', 'case_remarks.acknowledged_at', 'case_remarks.created_at', 'users.name as author']);
        $present = fn ($row) => ['id' => $row->id, 'kind' => $row->kind, 'body' => $row->body, 'author' => $row->author, 'role' => RolePermissions::ROLES[$row->author_role][0] ?? $row->author_role,
            'acknowledged_at' => $row->acknowledged_at, 'created_at' => $row->created_at];

        return $rows->whereNull('parent_id')->sortByDesc('created_at')->values()
            ->map(fn ($row) => $present($row) + ['replies' => $rows->where('parent_id', $row->id)->values()->map($present)->all()])->all();
    }
}
