<?php

namespace App\Http\Controllers;

use App\Services\CaseAccessScope;
use App\Support\AuditLog;
use App\Support\PhilippineDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildParticipationController extends Controller
{
    private const OCCASIONS = 'initial_case_planning,safety_planning,referral_service,placement_living,education,case_conference,transfer,reintegration,closure,other';

    private const PARTICIPATION_STATUSES = 'participated,partially_participated,child_declined,deferred,not_appropriate_unsafe,child_unable_to_participate,not_yet_conducted';

    // Existing migration VARCHAR widths; SQLite does not enforce these limits.
    private const STRING_LIMITS = ['preferred_language' => 120, 'location_environment' => 255, 'communication_method' => 255, 'interpreter_or_support' => 255, 'trusted_adult_or_support_person' => 255, 'decision_title' => 255, 'feedback_by' => 255, 'feedback_method_language' => 255];

    private const SECTION_FIELDS = [
        'participation_context' => ['developmental_considerations', 'preferred_language', 'location_environment', 'privacy_reasonably_provided', 'comfortable_to_continue'],
        'child_views' => ['child_views', 'direct_quote', 'worker_observation', 'reported_information'],
        'communication_accommodations' => ['communication_method', 'accessibility_accommodation', 'interpreter_or_support', 'trusted_adult_or_support_person'],
        'decision_under_consideration' => ['decision_title', 'child_preference'],
        'how_views_were_considered' => ['factors_considered', 'view_influence', 'decision_alignment', 'professional_consideration'],
        'best_interest_considerations' => ['best_interest_rationale', 'safeguards_mitigating_actions'],
        'feedback_to_child' => ['outcome_explained_to_child', 'feedback_date', 'feedback_by', 'feedback_method_language', 'child_response_questions', 'follow_up_needed'],
        'safeguards_follow_up' => ['sharing_preference', 'sharing_professional_consideration', 'necessary_disclosure_basis'],
    ];

    public function __construct(private readonly CaseAccessScope $scope) {}

    private function participationResponse(Request $request, string $message, array $acknowledgement, int $status = 200)
    {
        if ($request->expectsJson() && !$request->headers->has('X-Inertia')) {
            return response()->json($acknowledgement, $status);
        }

        return back()->with('success', $message);
    }

    private function access(Request $request, int $caseId): void
    {
        $case = DB::table('cases')->find($caseId);
        abort_unless($case, 404);
        $this->authorize('cases.view');
        $location = DB::table('clients')->find($case->client_id, ['municipality', 'province']);
        abort_unless($location && $this->scope->allowsCase($case, $request->user(), $location->municipality, $location->province), 403);
    }

    public function store(Request $request, int $case)
    {
        $this->access($request, $case);
        $this->authorize('participation.manage');
        $data = $request->validate(['occasion_type' => 'required|in:' . self::OCCASIONS, 'decision_title' => 'required|string|max:255', 'participation_date' => 'required|date|before_or_equal:' . PhilippineDate::today(), 'participation_status' => 'required|in:' . self::PARTICIPATION_STATUSES, 'non_participation_reason' => 'nullable|string|max:5000', 'previous_record_id' => 'nullable|integer', 'record_type' => 'required|in:formal_record,full_revision,addendum', 'amendment_reason' => 'nullable|string|max:2000']);
        $needsReason = in_array($data['participation_status'], ['child_declined', 'deferred', 'not_appropriate_unsafe', 'child_unable_to_participate', 'not_yet_conducted'], true);
        if ($needsReason && empty($data['non_participation_reason'])) {
            throw ValidationException::withMessages(['non_participation_reason' => 'Record a neutral reason for this participation status.']);
        }
        $previousId = $data['previous_record_id'] ?? null;
        $prior = !empty($previousId) ? DB::table('child_participation_records')->where('case_id', $case)->where('status', 'final')->find($previousId) : null;
        abort_if($previousId && !$prior, 422, 'Choose a finalized record for a correction or addendum.');
        abort_if(!$prior && $data['record_type'] !== 'formal_record', 422, 'Only a new decision/occasion may start as a formal participation record.');
        abort_if($prior && !in_array($data['record_type'], ['full_revision', 'addendum'], true), 422, 'A finalized record may only be corrected or supplemented through a new version or addendum.');
        abort_if($prior && ($prior->occasion_type !== $data['occasion_type'] || $prior->decision_title !== $data['decision_title']), 422, 'A correction or addendum must remain attached to the same decision/participation occasion.');
        abort_if($prior && empty($data['amendment_reason']), 422, 'State why this finalized record is being corrected or supplemented.');
        $id = DB::transaction(function () use ($data, $prior, $case, $request) {
            $record = collect($data)->except(['previous_record_id'])->merge(['case_id' => $case, 'previous_record_id' => $prior?->id, 'lineage_key' => $prior?->lineage_key ?? (string) Str::uuid(), 'version' => $prior ? ((int) DB::table('child_participation_records')->where('lineage_key', $prior->lineage_key)->max('version') + 1) : 1, 'amendment_reason' => $prior ? $data['amendment_reason'] : null, 'status' => 'draft', 'authored_by' => $request->user()->id, 'is_current' => false, 'created_at' => now(), 'updated_at' => now()]);
            if ($prior && $data['record_type'] === 'full_revision') {
                $copy = collect((array) $prior)->only(array_merge(...array_values(self::SECTION_FIELDS)))->all();
                $record = $record->merge($copy);
            }

            return DB::table('child_participation_records')->insertGetId($record->all());
        });
        $this->audit($request, $case, $id, 'child_participation.created');

        return $this->participationResponse($request, 'Child Participation draft created.', ['id' => $id, 'status' => 'draft'], 201);
    }

    public function index(Request $request, int $case)
    {
        $this->access($request, $case);
        $columns = ['child_participation_records.id', 'child_participation_records.previous_record_id', 'child_participation_records.version', 'child_participation_records.record_type', 'child_participation_records.participation_date', 'child_participation_records.status', 'child_participation_records.is_current', 'child_participation_records.finalized_at'];
        if ($request->user()->hasPermission('participation.restricted_view')) {
            $columns = array_merge($columns, ['child_participation_records.occasion_type', 'child_participation_records.decision_title', 'child_participation_records.participation_status', 'authors.name as author_name', 'reviewers.name as reviewer_name']);
        }

        return response()->json(['records' => DB::table('child_participation_records')->leftJoin('users as authors', 'authors.id', '=', 'child_participation_records.authored_by')->leftJoin('users as reviewers', 'reviewers.id', '=', 'child_participation_records.reviewed_by')->where('child_participation_records.case_id', $case)->orderByDesc('child_participation_records.participation_date')->orderByDesc('child_participation_records.id')->get($columns)]);
    }

    public function show(Request $request, int $case, int $record)
    {
        $this->access($request, $case);
        $item = $this->record($case, $record);
        $safe = collect((array) $item)->only(['id', 'case_id', 'previous_record_id', 'lineage_key', 'version', 'record_type', 'participation_date', 'status', 'is_current', 'reviewed_at', 'returned_at', 'finalized_at'])->all();
        $canRead = $request->user()->hasPermission('participation.restricted_view');
        if ($canRead) {
            $safe = (array) $item;
        }
        $history = DB::table('child_participation_workflow_events')->leftJoin('users', 'users.id', '=', 'child_participation_workflow_events.actor_id')->where('child_participation_record_id', $record)->orderBy('child_participation_workflow_events.created_at')->get(['child_participation_workflow_events.event', 'child_participation_workflow_events.comments', 'child_participation_workflow_events.created_at', 'users.name as actor_name', 'users.role as actor_role']);
        if (!$canRead) {
            $history = $history->map(fn ($event) => collect($event)->only(['event', 'created_at'])->all());
        }

        return response()->json(['record' => $safe, 'restricted' => !$canRead, 'placeholder' => $canRead ? null : 'Restricted Child Participation record', 'history' => $history, 'allowed_actions' => ['save_sections' => $canRead && $item->status === 'draft' && (int) $item->authored_by === $request->user()->id && $request->user()->hasPermission('participation.manage'), 'submit' => $item->status === 'draft' && (int) $item->authored_by === $request->user()->id && $request->user()->hasPermission('participation.manage'), 'review' => $item->status === 'for_review' && (int) $item->authored_by !== $request->user()->id && $request->user()->hasPermission('participation.review'), 'return' => $item->status === 'for_review' && (int) $item->authored_by !== $request->user()->id && $request->user()->hasPermission('participation.review'), 'finalize' => $item->status === 'reviewed' && (int) $item->authored_by !== $request->user()->id && $request->user()->hasPermission('participation.review'), 'new_version' => $item->status === 'final' && $request->user()->hasPermission('participation.manage')]]);
    }

    public function saveSection(Request $request, int $case, int $record)
    {
        $this->access($request, $case);
        $this->authorize('participation.manage');
        $this->authorize('participation.restricted_view');
        $item = $this->record($case, $record);
        abort_unless($item->status === 'draft' && (int) $item->authored_by === $request->user()->id, 422, 'Only the author may edit a draft.');
        $section = $request->validate(['section_key' => 'required|in:' . implode(',', array_keys(self::SECTION_FIELDS))])['section_key'];
        $rules = ['section_key' => 'required'];
        foreach (self::SECTION_FIELDS[$section] as $field) {
            $rules[$field] = match (true) {
                in_array($field, ['privacy_reasonably_provided', 'comfortable_to_continue', 'outcome_explained_to_child'], true) => 'nullable|boolean',
                $field === 'feedback_date' => 'nullable|date',
                $field === 'decision_alignment' => 'nullable|in:aligns,partially_aligns,does_not_align,not_yet_decided',
                default => 'nullable|string|max:' . (self::STRING_LIMITS[$field] ?? 5000),
            };
        }
        $data = $request->validate($rules);
        unset($data['section_key']);
        DB::table('child_participation_records')->where('id', $record)->update($data + ['updated_at' => now()]);
        $this->audit($request, $case, $record, 'child_participation.section_saved', ['section' => $section]);

        return $this->participationResponse($request, 'Child Participation section saved.', ['status' => 'saved']);
    }

    public function transition(Request $request, int $case, int $record)
    {
        $this->access($request, $case);
        $item = $this->record($case, $record);
        $input = $request->validate(['status' => 'required|in:for_review,reviewed,returned_for_revision,final', 'review_comments' => 'nullable|string|max:5000']);
        $to = $input['status'];
        $comments = $input['review_comments'] ?? null;
        if ($to === 'for_review') {
            $this->authorize('participation.manage');
            abort_unless($item->status === 'draft' && (int) $item->authored_by === $request->user()->id, 422);
            DB::table('child_participation_records')->where('id', $record)->update(['status' => 'for_review', 'updated_at' => now()]);
        } elseif ($to === 'reviewed') {
            $this->reviewer($request, $item);
            abort_unless($item->status === 'for_review', 422);
            DB::table('child_participation_records')->where('id', $record)->update(['status' => 'reviewed', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_comments' => $comments, 'updated_at' => now()]);
        } elseif ($to === 'returned_for_revision') {
            $this->reviewer($request, $item);
            abort_unless($item->status === 'for_review', 422);
            if (empty($comments)) {
                throw ValidationException::withMessages(['review_comments' => 'A reviewer comment is required when returning a record.']);
            } DB::table('child_participation_records')->where('id', $record)->update(['status' => 'draft', 'returned_by' => $request->user()->id, 'returned_at' => now(), 'review_comments' => $comments, 'updated_at' => now()]);
        } else {
            $this->reviewer($request, $item);
            abort_unless($item->status === 'reviewed', 422);
            DB::transaction(function () use ($item, $record) {
                DB::table('child_participation_records')->where('lineage_key', $item->lineage_key)->where('status', 'final')->update(['is_current' => false, 'updated_at' => now()]);
                DB::table('child_participation_records')->where('id', $record)->update(['status' => 'final', 'finalized_by' => auth()->id(), 'finalized_at' => now(), 'is_current' => true, 'updated_at' => now()]);
            });
        }
        DB::table('child_participation_workflow_events')->insert(['child_participation_record_id' => $record, 'event' => $to, 'actor_id' => $request->user()->id, 'comments' => $comments, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $case, $record, 'child_participation.' . $to);

        return $this->participationResponse($request, 'Child Participation workflow updated.', ['status' => $to]);
    }

    private function reviewer(Request $request, object $item): void
    {
        $this->authorize('participation.review');
        abort_unless((int) $item->authored_by !== $request->user()->id, 403, 'A different authorized reviewer is required.');
    }

    private function record(int $case, int $record): object
    {
        $item = DB::table('child_participation_records')->where('case_id', $case)->find($record);
        abort_unless($item, 404);

        return $item;
    }

    private function audit(Request $request, int $case, int $record, string $event, array $changes = []): void
    {
        AuditLog::insert(['user_id' => $request->user()->id, 'event' => $event, 'auditable_type' => 'child_participation_record', 'auditable_id' => $record, 'changes' => json_encode(['case_id' => $case] + $changes), 'created_at' => now(), 'updated_at' => now()]);
    }
}
