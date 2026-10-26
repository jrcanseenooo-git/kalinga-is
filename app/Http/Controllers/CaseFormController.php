<?php

namespace App\Http\Controllers;

use App\Services\CaseAssignmentEligibility;
use App\Services\CaseForms;
use App\Services\CaseGrants;
use App\Services\Kavat;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The case manager's working forms (Case Summary, Intervention Plan, Social
 * Case Study Report) and the KaVAT. Saved as the case manager types; JSON
 * endpoints so the page (and field mode, on reconnection) can save without
 * reloading.
 */
class CaseFormController extends Controller
{
    public function __construct(private readonly CaseAssignmentEligibility $eligibility) {}

    /** The case, if this user may write its forms (the assigned case manager on an open case). */
    private function writable(Request $request, int $case): object
    {
        $this->authorize('cases.update');
        $record = DB::table('cases')->whereNull('deleted_at')->find($case);
        abort_unless($record && $this->eligibility->canRead($request->user(), $record), 404);
        abort_if($record->status === 'CLOSED', 422, 'This case is closed. Reopen it before changing its forms.');

        return $record;
    }

    public function save(Request $request, int $case, string $type)
    {
        abort_unless(isset(CaseForms::TYPES[$type]), 404);
        $this->writable($request, $case);
        $data = $request->validate(['content' => 'required|array', 'revision' => 'required|integer|min:0']);
        $result = CaseForms::save($case, $type, $data['content'], (int) $data['revision'], $request->user()->id);

        return response()->json($result, $result['saved'] ? 200 : 409, ['Cache-Control' => 'private, no-store']);
    }

    public function finalize(Request $request, int $case, string $type)
    {
        abort_unless(isset(CaseForms::TYPES[$type]), 404);
        $this->writable($request, $case);
        $version = CaseForms::finalize($case, $type, $request->user()->id);

        return back()->with('success', CaseForms::TYPES[$type] . " finalized as version {$version}. You can keep updating it; the next finalize makes a new version.");
    }

    public function startKavat(Request $request, int $case)
    {
        $this->writable($request, $case);
        $data = $request->validate(['occasion' => ['required', Rule::in(array_keys(Kavat::OCCASIONS))], 'administered_at' => 'required|date|before_or_equal:today']);
        abort_if(DB::table('kavat_assessments')->where('case_id', $case)->where('status', 'draft')->exists(), 422, 'Finish or discard the KaVAT already in progress first.');
        $id = DB::table('kavat_assessments')->insertGetId(['case_id' => $case, 'instrument_version' => Kavat::VERSION, 'occasion' => $data['occasion'], 'administered_at' => $data['administered_at'],
            'administered_by' => $request->user()->id, 'status' => 'draft', 'answers' => json_encode(['part1' => (object) [], 'part2' => (object) [], 'details' => (object) []]),
            'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        AuditLog::record('kavat.created', 'case', $case, ['case_id' => $case, 'occasion' => $data['occasion']], $request->user()->id, 'success', ['description' => 'KaVAT started']);

        return back()->with('success', 'KaVAT started. Answers are saved as you go.');
    }

    public function saveKavat(Request $request, int $case, int $kavat)
    {
        $this->writable($request, $case);
        $data = $request->validate([
            'revision' => 'required|integer|min:1', 'answers' => 'required|array',
            'notes' => 'nullable|string|max:5000', 'occasion' => ['nullable', Rule::in(array_keys(Kavat::OCCASIONS))], 'administered_at' => 'nullable|date|before_or_equal:today',
        ]);
        $result = self::applyKavatSave($case, $kavat, Kavat::cleanAnswers($data['answers']), (int) $data['revision'], $data);

        return response()->json($result, $result['saved'] ? 200 : 409, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Saves KaVAT answers when the caller edited the latest revision (the page
     * and field mode both use this).
     *
     * @param  array{part1: object, part2: object, details: object}  $answers
     */
    public static function applyKavatSave(int $case, int $kavat, array $answers, int $revision, array $extra = []): array
    {
        return DB::transaction(function () use ($case, $kavat, $answers, $revision, $extra) {
            $row = DB::table('kavat_assessments')->where('case_id', $case)->lockForUpdate()->find($kavat);
            abort_unless($row, 404);
            abort_unless($row->status === 'draft', 422, 'A finalized KaVAT cannot be changed. Start a new one instead.');
            if ((int) $row->revision !== $revision) {
                return ['saved' => false, 'revision' => (int) $row->revision, 'answers' => json_decode($row->answers, true)];
            }
            DB::table('kavat_assessments')->where('id', $kavat)->update([
                'answers' => json_encode($answers), 'notes' => array_key_exists('notes', $extra) ? $extra['notes'] : $row->notes,
                'occasion' => $extra['occasion'] ?? $row->occasion, 'administered_at' => $extra['administered_at'] ?? $row->administered_at,
                'revision' => $row->revision + 1, 'updated_at' => now(),
            ]);

            return ['saved' => true, 'kavat_id' => $kavat, 'revision' => $row->revision + 1, 'score' => Kavat::score(json_decode(json_encode($answers), true))];
        });
    }

    public function finalizeKavat(Request $request, int $case, int $kavat)
    {
        $this->writable($request, $case);
        DB::transaction(function () use ($case, $kavat, $request) {
            $row = DB::table('kavat_assessments')->where('case_id', $case)->lockForUpdate()->find($kavat);
            abort_unless($row && $row->status === 'draft', 404);
            $score = Kavat::score(json_decode($row->answers, true) ?: []);
            abort_unless($score['complete'], 422, "Answer every statement and question first ({$score['answered']} of {$score['questions']} answered).");
            DB::table('kavat_assessments')->where('id', $kavat)->update([
                'status' => 'final', 'finalized_at' => now(), 'part1_score' => $score['part1'], 'part2_score' => $score['part2'], 'empowerment_rate' => $score['empowerment_rate'],
                'objective_risk_rate' => $score['objective_risk_rate'], 'safety_rate' => $score['safety_rate'], 'final_score' => $score['final_score'], 'priority' => $score['priority'],
                'red_flags' => json_encode($score['red_flags']), 'updated_at' => now(),
            ]);
            DB::table('cases')->where('id', $case)->update(['last_activity_at' => now(), 'offline_version' => DB::raw('offline_version + 1'), 'updated_at' => now()]);
            AuditLog::record('kavat.finalized', 'case', $case, ['case_id' => $case, 'kavat_id' => $kavat, 'priority' => $score['priority']], $request->user()->id, 'success',
                ['description' => 'KaVAT finalized: protection score ' . $score['final_score'] . ' (' . Kavat::priorityLabel($score['priority']) . ')', 'new_value' => (string) $score['final_score']]);
        });

        return back()->with('success', 'KaVAT finalized.');
    }

    public function discardKavat(Request $request, int $case, int $kavat)
    {
        $this->writable($request, $case);
        $deleted = DB::table('kavat_assessments')->where('case_id', $case)->where('id', $kavat)->where('status', 'draft')->delete();
        abort_unless($deleted, 404);
        AuditLog::record('kavat.discarded', 'case', $case, ['case_id' => $case], $request->user()->id, 'success', ['description' => 'Unfinished KaVAT discarded']);

        return back()->with('success', 'Unfinished KaVAT discarded.');
    }

    public function storeGrant(Request $request, int $case)
    {
        $this->writable($request, $case);
        $data = $request->validate([
            'grant_type' => ['required', Rule::in(array_keys(CaseGrants::TYPES))], 'amount' => 'required|numeric|min:1|max:9999999',
            'released_at' => 'required|date|before_or_equal:today', 'fund_source' => 'nullable|string|max:160', 'purpose' => 'nullable|string|max:255', 'received_by' => 'nullable|string|max:160',
        ]);
        $id = DB::table('case_grants')->insertGetId($data + ['case_id' => $case, 'recorded_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cases')->where('id', $case)->update(['last_activity_at' => now(), 'updated_at' => now()]);
        AuditLog::record('grant.created', 'case', $case, ['case_id' => $case, 'grant_id' => $id, 'grant_type' => $data['grant_type']], $request->user()->id, 'success',
            ['description' => CaseGrants::TYPES[$data['grant_type']] . ' recorded', 'new_value' => number_format((float) $data['amount'], 2)]);

        return back()->with('success', 'Grant recorded.');
    }

    public function voidGrant(Request $request, int $case, int $grant)
    {
        $this->writable($request, $case);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $row = DB::table('case_grants')->where('case_id', $case)->whereNull('voided_at')->find($grant);
        abort_unless($row, 404);
        DB::table('case_grants')->where('id', $grant)->update(['voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason'], 'updated_at' => now()]);
        AuditLog::record('grant.voided', 'case', $case, ['case_id' => $case, 'grant_id' => $grant], $request->user()->id, 'success',
            ['description' => 'Grant entry voided: ' . $data['reason'], 'old_value' => number_format((float) $row->amount, 2), 'new_value' => 'Voided']);

        return back()->with('success', 'Grant entry voided.');
    }

    /** All KaVAT administrations of a case, newest first, with their scores. */
    public static function kavatHistory(int $case): array
    {
        return DB::table('kavat_assessments')->leftJoin('users', 'users.id', '=', 'kavat_assessments.administered_by')->where('case_id', $case)
            ->orderByDesc('kavat_assessments.administered_at')->orderByDesc('kavat_assessments.id')
            ->get(['kavat_assessments.*', 'users.name as administered_by_name'])
            ->map(fn ($row) => [
                'id' => $row->id, 'occasion' => $row->occasion, 'administered_at' => $row->administered_at, 'administered_by' => $row->administered_by_name, 'status' => $row->status,
                'revision' => (int) $row->revision, 'answers' => json_decode($row->answers, true), 'notes' => $row->notes, 'finalized_at' => $row->finalized_at,
                'score' => Kavat::score(json_decode($row->answers, true) ?: []),
            ])->all();
    }
}
