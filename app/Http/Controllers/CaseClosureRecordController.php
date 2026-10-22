<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CaseAssignmentEligibility;
use App\Services\CaseLifecyclePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaseClosureRecordController extends Controller
{
    public function __construct(private readonly CaseAssignmentEligibility $access, private readonly CaseLifecyclePolicy $policy) {}

    private function read(Request $request, int $case, callable $read): JsonResponse
    {
        $this->authorize('cases.view');

        return DB::transaction(function () use ($request, $case, $read) {
            $record = DB::table('cases')->where('id', $case)->whereNull('deleted_at')->sharedLock()->first() ?? abort(404);
            $actor = User::query()->where('id', $request->user()->id)->sharedLock()->first();
            DB::table('user_lgu_assignments')->where('user_id', $request->user()->id)->orderBy('id')->sharedLock()->get(['id']);
            abort_unless($actor && $this->access->canRead($actor, $record), 403);
            $data = $read($record, $actor);
            abort_unless($this->access->canRead($actor, $record), 403);

            return response()->json($data)->header('Cache-Control', 'private, no-store, max-age=0');
        }, 3);
    }

    private function records(int $case)
    {
        return DB::table('case_closure_records as closure')
            ->leftJoin('users as author', 'author.id', '=', 'closure.recorded_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'closure.reviewed_by')
            ->where('closure.case_id', $case);
    }

    private function data(object $record, bool $includeSummary): array
    {
        $data = [];
        foreach (['id', 'version', 'source', 'recorded_by', 'recorded_at', 'reviewed_by', 'reviewed_at', 'closed_at', 'superseded_at', 'created_at', 'author_name', 'reviewer_name'] as $field) {
            $data[$field] = $record->{$field} ?? null;
        }
        if ($includeSummary) {
            $data['summary'] = $record->summary;
        }

        return $data;
    }

    public function index(Request $request, int $case): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1|max:1000000']);

        return $this->read($request, $case, function ($record, $actor) use ($case) {
            $current = $this->records($case)->where('closure.current_slot', 1)->first(['closure.*', 'author.name as author_name', 'reviewer.name as reviewer_name']);
            $author = DB::table('audit_logs')->where('event', 'case.closure_recorded')->where('auditable_type', 'case')->where('auditable_id', $case)->latest('created_at')->orderByDesc('id')->value('user_id');
            $drift = $current && $current->summary !== $record->closure_summary;
            $legacy = null;
            if (!$current || $drift) {
                $hasHistory = DB::table('case_closure_records')->where('case_id', $case)->exists();
                if ($record->closure_summary !== null || $record->closure_reviewed_by || $record->closure_reviewed_at || (!$hasHistory && $record->closed_at)) {
                    $legacy = ['id' => null, 'version' => null, 'source' => 'legacy_case_summary', 'summary' => $record->closure_summary, 'recorded_by' => $drift ? null : $author, 'recorded_at' => null, 'reviewed_by' => $record->closure_reviewed_by, 'reviewed_at' => $record->closure_reviewed_at, 'closed_at' => $record->closed_at, 'created_at' => null];
                }
            }
            $readiness = $this->policy->readiness($case);
            $history = $this->records($case)->orderByDesc('closure.version')->paginate(10, ['closure.id', 'closure.version', 'closure.source', 'closure.recorded_by', 'closure.recorded_at', 'closure.reviewed_by', 'closure.reviewed_at', 'closure.closed_at', 'closure.superseded_at', 'closure.created_at', 'author.name as author_name', 'reviewer.name as reviewer_name'])->through(fn ($item) => $this->data($item, false));

            return [
                'case' => collect($record)->only(['id', 'case_number', 'status', 'offline_version', 'closed_at', 'retention_review_at'])->all(),
                'current' => $current ? $this->data($current, true) : $legacy,
                'surviving_projection' => $drift ? $legacy : null, 'projection_drift' => (bool) $drift,
                'history' => $history, 'readiness' => $readiness,
                'aftercare' => DB::table('case_aftercare_plans')->where('case_id', $case)->orderByDesc('id')->limit(5)->get(['id', 'required', 'status', 'responsible_office', 'initial_follow_up_at']),
                'reintegration' => DB::table('case_reintegrations')->where('case_id', $case)->orderByDesc('id')->limit(5)->get(['id', 'applicable', 'status', 'responsible_office', 'target_date', 'review_at']),
                'allowed_actions' => [
                    'record' => $actor->can('cases.update') && $record->status === 'FOR_CLOSURE_REVIEW',
                    'review' => !$drift && $actor->can('cases.close') && $record->status === 'FOR_CLOSURE_REVIEW' && !empty($record->closure_summary) && (!$author || (int) $author !== (int) $actor->id),
                    'close' => !$drift && $actor->can('cases.close') && $record->status === 'FOR_CLOSURE_REVIEW' && !empty($record->closure_summary) && !empty($record->closure_reviewed_at) && (int) $record->closure_reviewed_by === (int) $actor->id && count($readiness['critical_blockers']) === 0,
                    'reopen' => $actor->can('cases.reopen') && $record->status === 'CLOSED' && !$drift,
                ],
            ];
        });
    }

    public function show(Request $request, int $case, int $record): JsonResponse
    {
        return $this->read($request, $case, function () use ($case, $record) {
            $item = $this->records($case)->where('closure.id', $record)->first(['closure.*', 'author.name as author_name', 'reviewer.name as reviewer_name']) ?? abort(404);

            return ['record' => $this->data($item, true)];
        });
    }
}
