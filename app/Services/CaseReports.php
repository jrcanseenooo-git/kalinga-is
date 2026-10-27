<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the printable case reports from the case record.
 *
 * Report types (key => title):
 *  - intake  Kalinga Intake Sheet (General Intake Sheet)
 *  - notes   Progress Notes
 *  - plan    Intervention Plan
 *  - summary Case Summary Report
 *  - study   Social Case Study Report
 *
 * Privacy rules applied to every report (CANE+D minimum necessary):
 *  - Only progress notes with case-team visibility are included (legacy notes
 *    without a contact time are dated by when they were recorded); MDT notes,
 *    restricted assessment sections and conference narratives are left out.
 *  - Health, sexual and reproductive health and mental health content
 *    (pregnancy status, disability evidence, and care-plan goals in those
 *    domains) is not printed. The report shows that the item exists and that
 *    details stay in the case record.
 *  - The page shows initials by default; the worker may reveal the full name
 *    and contact numbers for a copy that goes into the official case folder.
 *
 * OWNER REVIEW: which sensitive items a CPU or Central Office copy may carry.
 */
class CaseReports
{
    public const TYPES = [
        'intake' => 'Kalinga Intake Sheet',
        'summary' => 'Case Summary Report',
        'notes' => 'Progress Notes',
        'plan' => 'Intervention Plan',
        'study' => 'Social Case Study Report',
    ];

    /** Report number prefixes, for example GIS-KLG-2026-00001-20261022. */
    public const PREFIX = ['intake' => 'GIS', 'notes' => 'PN', 'plan' => 'IP', 'summary' => 'CSR', 'study' => 'SCSR'];

    /** Care-plan domains whose content is health information and is not printed. */
    public const SENSITIVE_DOMAINS = ['health', 'reproductive_health', 'mental_health_psychosocial'];

    private const ASSESSMENT_SECTIONS = ['presenting_situation' => 'Presenting situation', 'family_relationships' => 'Family relationships', 'cefmu_context' => 'CEFMU context',
        'economic_education' => 'Economic and education', 'psychosocial_environment' => 'Psychosocial environment', 'strengths_support' => 'Strengths and support',
        'professional_assessment' => 'Professional assessment', 'case_formulation' => 'Case formulation', 'recommendations' => 'Recommendations'];

    public function __construct(private readonly NoteReferrals $noteReferrals) {}

    /** Everything one report needs. $period = ['from' => 'Y-m-d'|null, 'to' => 'Y-m-d'|null] for progress notes. */
    public function build(object $case, object $client, string $type, object $viewer, array $period = []): array
    {
        $lgu = $case->lgu_id ? DB::table('lgu_units')->find($case->lgu_id) : null;
        $profile = $case->lgu_id ? DB::table('lgu_profiles')->where('lgu_id', $case->lgu_id)->first() : null;
        $preparer = DB::table('users')->find($case->assigned_to ?: $viewer->id) ?? $viewer;

        $data = [
            'type' => $type,
            'title' => self::TYPES[$type],
            'reportNo' => sprintf('%s-%s-%s', self::PREFIX[$type], $case->case_number, now('Asia/Manila')->format('Ymd')),
            'generatedAt' => now()->toIso8601String(),
            'caseId' => $case->id,
            'office' => [
                'lgu' => $lgu?->name, 'province' => $lgu?->province, 'region' => $lgu?->region,
                'name' => $profile?->office_name, 'short' => $profile?->office_short_name, 'address' => $profile?->address, 'contact' => $profile?->contact_number,
                'logo' => $profile?->logo_path ? route('lgu-profile.logo', ['lgu' => $case->lgu_id, 'v' => strtotime((string) $profile->updated_at)]) : null,
            ],
            'preparedBy' => ['name' => $preparer->name, 'position' => $preparer->position ?? null],
            'notedBy' => $profile?->head_name ? ['name' => $profile->head_name, 'position' => $profile->head_position, 'designation' => $profile->head_designation] : null,
            'caseInfo' => $this->caseInfo($case),
            'client' => $this->client($client),
            'family' => DB::table('relationships')->where('client_id', $case->client_id)->orderBy('id')
                ->get(['name', 'relationship_type', 'sex', 'civil_status', 'birthdate', 'education', 'occupation', 'same_address_as_client', 'house_no_street', 'barangay', 'municipality', 'province'])
                ->map(fn ($member) => ['name' => $member->name, 'relationship_type' => $member->relationship_type, 'sex' => $member->sex, 'civil_status' => $member->civil_status,
                    'birthdate' => $member->birthdate, 'education' => $member->education, 'occupation' => $member->occupation,
                    'area' => $member->same_address_as_client ? 'Same as the child' : (implode(', ', array_filter([$member->barangay, $member->municipality, $member->province])) ?: null),
                    'street' => $member->same_address_as_client ? null : $member->house_no_street]),
        ];

        return $data + $this->workingForm($case->id, $type) + match ($type) {
            'intake' => $this->intake($case),
            'notes' => $this->notes($case->id, $period),
            'plan' => $this->plan($case->id),
            default => $this->summary($case->id),
        };
    }

    /**
     * The Kalinga form the case manager wrote for this report (Case Summary,
     * Intervention Plan matrix, Social Case Study Report), with its section
     * titles. The report prints the form when it has content and falls back
     * to the record otherwise. The case study also prints the plan matrix.
     */
    private function workingForm(int $caseId, string $type): array
    {
        if (!isset(CaseForms::TYPES[$type])) {
            return [];
        }
        $forms = CaseForms::forCase($caseId);
        $definition = CaseForms::definition();
        $form = $forms[$type];
        $latest = DB::table('kavat_assessments')->where('case_id', $caseId)->where('status', 'final')->orderByDesc('administered_at')->orderByDesc('id')
            ->first(['administered_at', 'occasion', 'empowerment_rate', 'safety_rate', 'final_score', 'priority']);

        return [
            'form' => ['status' => $form['status'], 'version' => $form['versions'][0]->version ?? null, 'content' => $form['content'], 'hasContent' => CaseForms::hasContent($type, $form['content']),
                'sections' => $definition['sections'][$type] ?? [], 'planColumns' => CaseForms::PLAN_COLUMNS],
            'planMatrix' => $type === 'study' ? $forms['plan']['content']['rows'] : null,
            'kavat' => $latest ? (array) $latest + ['priority_label' => Kavat::priorityLabel($latest->priority)] : null,
            'grants' => in_array($type, ['summary', 'study'], true) ? CaseGrants::forCase($caseId) : null,
        ];
    }

    private function caseInfo(object $case): array
    {
        return [
            'number' => $case->case_number, 'status' => $case->status, 'stage' => $case->stage, 'priority' => $case->priority,
            'opened_at' => $case->opened_at, 'intake_at' => $case->intake_completed_at ?? $case->opened_at, 'closed_at' => $case->closed_at ?? null,
            'classifications' => json_decode($case->cefmu_classifications ?? '[]', true) ?: [], 'union_type' => $case->union_type, 'partner_age_band' => $case->partner_age_band,
            'union_forms' => json_decode($case->union_forms ?? '[]', true) ?: [], 'union_status' => $case->union_status ?? null, 'partner_current_age' => $case->partner_current_age ?? null,
            'union_duration_months' => $case->union_duration_months ?? null, 'current_residence' => $case->current_residence ?? null,
            'education_status' => $case->education_status_at_intake, 'mode_of_admission' => $case->mode_of_admission, 'referred_by' => $case->referred_by, 'referral_date' => $case->referral_date,
            'problem_presented' => $case->problem_presented, 'cefmu_context' => $case->cefmu_context, 'initial_assessment' => $case->initial_assessment,
            'initial_risk_level' => $case->initial_risk_level, 'current_risk_level' => $case->current_risk_level ?? null, 'immediate_actions' => $case->immediate_actions,
            'plan_recommendations' => $case->plan_recommendations,
        ];
    }

    private function client(object $client): array
    {
        $address = fn (?string $street, ?string $barangay, ?string $municipality, ?string $province) => implode(', ', array_filter([$street, $barangay, $municipality, $province]));

        return [
            'first_name' => $client->first_name, 'middle_name' => $client->middle_name ?? null, 'last_name' => $client->last_name, 'suffix' => $client->suffix ?? null, 'alias' => $client->alias ?? null,
            'birthdate' => $client->birthdate, 'sex' => $client->sex, 'civil_status' => $client->civil_status ?? null, 'religion' => $client->religion ?? null,
            'ip_category' => $client->ip_category ?? null, 'education' => $client->education_attainment ?? null,
            'address' => $address($client->house_no_street ?? null, $client->barangay, $client->municipality, $client->province),
            'permanent_address' => $address($client->permanent_house_no_street ?? null, $client->permanent_barangay ?? null, $client->permanent_municipality ?? null, $client->permanent_province ?? null),
            'contact' => $client->phone ?? null, 'safe_contact_method' => $client->safe_contact_method ?? null,
            'caregiver' => $client->primary_caregiver_name ?? null, 'caregiver_relationship' => $client->primary_caregiver_relationship ?? null,
            'caregiver_contact' => $client->primary_caregiver_contact ?? null,
            'caregiver_address' => ($client->caregiver_same_address ?? null) ? 'Same as the child' : ($address($client->caregiver_house_no_street ?? null, $client->caregiver_barangay ?? null, $client->caregiver_municipality ?? null, $client->caregiver_province ?? null) ?: null),
        ];
    }

    private function intake(object $case): array
    {
        return ['intake' => [
            'privacy_notice_method' => $case->privacy_notice_method, 'privacy_notice_at' => $case->privacy_notice_provided_at,
            'consent_status' => $case->consent_status, 'data_sharing_basis' => $case->data_sharing_basis,
            'immediate_safety_concern' => (bool) $case->immediate_safety_concern, 'risk_indicators' => json_decode($case->risk_indicators ?? '[]', true) ?: [],
            'next_review_at' => $case->next_review_at, 'remarks' => $case->intake_remarks,
            // Health items are reported only as recorded / not recorded.
            'health_recorded' => (bool) ($case->pregnancy_parenting_status || $case->pregnancy_status || $case->disability_evidence || $case->functional_difficulty),
        ]];
    }

    /** Progress notes with their addenda, oldest first, inside the optional period. */
    private function notes(int $caseId, array $period): array
    {
        $from = !empty($period['from']) ? Carbon::parse($period['from'], 'Asia/Manila')->startOfDay()->utc() : null;
        $to = !empty($period['to']) ? Carbon::parse($period['to'], 'Asia/Manila')->endOfDay()->utc() : null;
        $when = DB::raw('COALESCE(case_notes.occurred_at, case_notes.created_at)');
        $rows = DB::table('case_notes')->leftJoin('users', 'users.id', '=', 'case_notes.created_by')
            ->where('case_notes.case_id', $caseId)->where('case_notes.visibility', 'CASE_NOTES')
            ->where(fn ($q) => $q->whereIn('case_notes.record_type', ['progress_note', 'addendum'])->orWhereNull('case_notes.record_type'))
            ->when($from, fn ($q) => $q->where($when, '>=', $from))
            ->when($to, fn ($q) => $q->where($when, '<=', $to))
            ->orderBy($when)->orderBy('case_notes.id')
            ->get(['case_notes.id', 'case_notes.record_type', 'case_notes.note_type', DB::raw('COALESCE(case_notes.occurred_at, case_notes.created_at) as occurred_at'), 'case_notes.body', 'case_notes.action_taken',
                'case_notes.next_steps', 'case_notes.previous_note_id', 'case_notes.addendum_reason', 'users.name as author', 'users.position as author_position']);

        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $addenda = $rows->where('record_type', 'addendum')->groupBy('previous_note_id');
        $notes = $rows->where('record_type', '!=', 'addendum')->values()->map(fn ($note) => [
            'id' => $note->id, 'type' => $note->note_type, 'at' => $iso($note->occurred_at), 'body' => $note->body, 'action' => $note->action_taken,
            'next' => $note->next_steps, 'author' => $note->author, 'author_position' => $note->author_position,
            'addenda' => ($addenda[$note->id] ?? collect())->values()->map(fn ($item) => ['at' => $iso($item->occurred_at), 'reason' => $item->addendum_reason, 'body' => $item->body, 'author' => $item->author]),
        ]);

        return [
            'period' => ['from' => $period['from'] ?? null, 'to' => $period['to'] ?? null],
            'progressNotes' => $notes,
            'referrals' => $this->referrals($caseId),
            'services' => $this->noteReferrals->servicesFor($caseId)->sortBy('date_provided')->values(),
        ];
    }

    /** The current plan, or the latest version when none is approved yet. */
    private function plan(int $caseId): array
    {
        $plan = DB::table('case_plans')->leftJoin('users', 'users.id', '=', 'case_plans.authored_by')->where('case_plans.case_id', $caseId)
            ->orderByDesc('case_plans.is_current')->orderByDesc('case_plans.version')
            ->first(['case_plans.id', 'case_plans.version', 'case_plans.plan_date', 'case_plans.status', 'case_plans.overall_objective', 'case_plans.summary',
                'case_plans.review_due_at', 'case_plans.is_current', 'users.name as author', 'users.position as author_position']);
        if (!$plan) {
            return ['plan' => null];
        }

        $interventions = DB::table('interventions')->leftJoin('users', 'users.id', '=', 'interventions.responsible_user_id')
            ->leftJoin('service_providers as providers', 'providers.id', '=', 'interventions.service_provider_id')
            ->where('interventions.case_plan_id', $plan->id)->orderBy('interventions.id')
            ->get(['interventions.case_plan_goal_id', 'interventions.activity', 'interventions.service_category', 'interventions.responsible_office',
                'interventions.start_date', 'interventions.target_date', 'interventions.progress', 'interventions.outcome_status', 'interventions.expected_outcome',
                'users.name as responsible_person', 'providers.name as provider'])
            ->groupBy('case_plan_goal_id');

        $goals = DB::table('case_plan_goals')->where('case_plan_id', $plan->id)->orderBy('id')
            ->get(['id', 'domain', 'need_problem', 'goal_statement', 'priority', 'target_date', 'status', 'outcome'])
            ->map(function ($goal) use ($interventions) {
                $sensitive = in_array($goal->domain, self::SENSITIVE_DOMAINS, true);

                return [
                    'domain' => $goal->domain, 'sensitive' => $sensitive, 'priority' => $goal->priority, 'target_date' => $goal->target_date, 'status' => $goal->status,
                    'need_problem' => $sensitive ? null : $goal->need_problem, 'goal_statement' => $sensitive ? null : $goal->goal_statement, 'outcome' => $sensitive ? null : $goal->outcome,
                    'interventions' => $sensitive ? [] : ($interventions[$goal->id] ?? collect())->values()->map(fn ($item) => [
                        'activity' => $item->activity, 'service_category' => $item->service_category, 'responsible' => $item->responsible_person ?: ($item->provider ?: $item->responsible_office),
                        'start_date' => $item->start_date, 'target_date' => $item->target_date, 'status' => $item->outcome_status ?: $item->progress, 'expected_outcome' => $item->expected_outcome,
                    ]),
                ];
            });
        $review = DB::table('case_plan_reviews')->where('case_plan_id', $plan->id)->whereNotNull('finalized_at')->orderByDesc('review_date')
            ->first(['review_date', 'overall_progress', 'recommendation', 'next_review_at']);

        return ['plan' => [
            'version' => $plan->version, 'date' => $plan->plan_date, 'status' => $plan->status, 'current' => (bool) $plan->is_current,
            'objective' => $plan->overall_objective, 'summary' => $plan->summary, 'review_due_at' => $plan->review_due_at,
            'author' => $plan->author, 'author_position' => $plan->author_position, 'goals' => $goals, 'review' => $review,
        ]];
    }

    /** Case Summary and Social Case Study data. */
    private function summary(int $caseId): array
    {
        $notes = DB::table('case_notes')->leftJoin('users', 'users.id', '=', 'case_notes.created_by')->where('case_notes.case_id', $caseId)
            ->where('case_notes.visibility', 'CASE_NOTES')->where(fn ($q) => $q->where('case_notes.record_type', 'progress_note')->orWhereNull('case_notes.record_type'))
            ->orderBy(DB::raw('COALESCE(case_notes.occurred_at, case_notes.created_at)'))
            ->get(['case_notes.id', 'case_notes.note_type', DB::raw('COALESCE(case_notes.occurred_at, case_notes.created_at) as occurred_at'), 'case_notes.body', 'case_notes.action_taken', 'case_notes.next_steps', 'users.name as author']);
        $assessment = DB::table('case_assessments')->where('case_id', $caseId)->whereNotNull('finalized_at')->orderByDesc('version')->first();
        $sections = $assessment ? DB::table('case_assessment_sections')->where('case_assessment_id', $assessment->id)->where('restricted', false)
            ->whereIn('section_key', array_keys(self::ASSESSMENT_SECTIONS))->get(['section_key', 'facts', 'observations', 'analysis'])
            ->map(fn ($row) => ['key' => $row->section_key, 'label' => self::ASSESSMENT_SECTIONS[$row->section_key], 'text' => trim(implode("\n", array_filter([$row->facts, $row->observations, $row->analysis])))])
            ->filter(fn ($row) => $row['text'] !== '')->sortBy(fn ($row) => array_search($row['key'], array_keys(self::ASSESSMENT_SECTIONS), true))->values() : collect();
        $plan = DB::table('case_plans')->where('case_id', $caseId)->where('is_current', true)->first(['id', 'overall_objective', 'summary', 'status', 'review_due_at']);
        $goals = $plan ? DB::table('case_plan_goals')->where('case_plan_id', $plan->id)->whereNotIn('domain', self::SENSITIVE_DOMAINS)->orderBy('id')
            ->get(['domain', 'need_problem', 'goal_statement', 'status', 'target_date']) : collect();

        return [
            'notes' => $notes->map(fn ($note) => ['id' => $note->id, 'type' => $note->note_type, 'at' => $note->occurred_at ? Carbon::parse($note->occurred_at)->toIso8601String() : null,
                'body' => $note->body, 'action' => $note->action_taken, 'next' => $note->next_steps, 'author' => $note->author]),
            'referrals' => $this->referrals($caseId),
            'services' => $this->noteReferrals->servicesFor($caseId)->sortBy('date_provided')->values(),
            'assessment' => ['date' => $assessment?->assessment_date, 'sections' => $sections],
            'risk' => DB::table('risk_assessments')->where('case_id', $caseId)->orderByDesc('assessed_at')->first(['overall_risk_level', 'assessed_at', 'required_action']),
            'plan' => $plan ? ['objective' => $plan->overall_objective, 'summary' => $plan->summary, 'review_due_at' => $plan->review_due_at, 'goals' => $goals] : null,
        ];
    }

    private function referrals(int $caseId): Collection
    {
        return DB::table('referrals')->where('case_id', $caseId)->orderBy('created_at')
            ->get(['id', 'service_category', 'referred_to', 'purpose', 'status', 'referred_at', 'accepted_at', 'completed_at', 'outcome', 'case_note_id', 'created_at']);
    }
}
