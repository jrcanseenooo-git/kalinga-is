<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The Kalinga Intake (4 steps): privacy notice and consent form, child and
 * safe contact, case details (CEFMU incidence), safety triage.
 *
 * One set of rules and one way of saving for the online intake and for
 * intakes made in field mode and synced later. Older field-mode intakes
 * (made before the 4-step intake) still sync: their extra fields are kept.
 */
final class CaseIntake
{
    public const PRIVACY_METHODS = ['explained_verbally', 'written_or_digital_notice', 'trusted_adult_or_guardian', 'deferred_emergency_next_practicable'];

    private const CLIENT_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix', 'alias', 'birthdate', 'sex', 'civil_status', 'religion', 'ip_category', 'education_attainment', 'phone',
        'safe_contact_method', 'safe_contact_notes', 'primary_caregiver_name', 'primary_caregiver_relationship', 'primary_caregiver_contact',
        'house_no_street', 'region', 'province', 'municipality', 'barangay',
        'permanent_same_as_present', 'permanent_house_no_street', 'permanent_region', 'permanent_province', 'permanent_municipality', 'permanent_barangay',
        'caregiver_same_address', 'caregiver_house_no_street', 'caregiver_region', 'caregiver_province', 'caregiver_municipality', 'caregiver_barangay'];

    private const ADDRESS = ['house_no_street' => 255, 'region' => 120, 'province' => 120, 'municipality' => 120, 'barangay' => 120];

    /** Older intake fields still accepted from field-mode intakes saved before the 4-step intake. */
    private const LEGACY_TEXT = ['problem_presented', 'initial_assessment', 'plan_recommendations', 'intake_remarks', 'immediate_actions', 'safety_plan', 'cefmu_context'];

    public function __construct(private readonly ConsentFormImage $consentImages, private readonly IdentityIntegrity $identity, private readonly ReferenceNumberService $references, private readonly OperationalNotification $notifications) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $address = fn (string $prefix) => collect(self::ADDRESS)->mapWithKeys(fn ($max, $field) => [$prefix . $field => "nullable|string|max:{$max}"])->all();

        return [
            'privacy_notice_acknowledged' => 'accepted',
            'privacy_notice_method' => ['required', Rule::in(self::PRIVACY_METHODS)],
            'consent_form_type' => ['nullable', Rule::in(ConsentFormImage::TYPES)],
            'consent_photo' => ConsentFormImage::rule(),

            'first_name' => 'required|string|max:80', 'middle_name' => 'nullable|string|max:80', 'last_name' => 'required|string|max:80', 'suffix' => 'nullable|string|max:30',
            'alias' => 'nullable|string|max:80',
            'birthdate' => 'required|date|before_or_equal:today', 'sex' => 'required|in:Male,Female', 'civil_status' => 'nullable|string|max:80',
            'religion' => 'nullable|string|max:120', 'ip_category' => 'nullable|string|max:120', 'education_attainment' => 'nullable|string|max:120',
            'phone' => ['nullable', 'regex:/^(?:\\+63|0)9\\d{9}$/'], 'disability_evidence' => 'nullable|string|max:100',
            'safe_contact_method' => 'nullable|string|max:100', 'safe_contact_notes' => 'nullable|string|max:1000',
            ...$address(''),
            'permanent_same_as_present' => 'nullable|boolean', ...$address('permanent_'),
            'primary_caregiver_name' => 'nullable|string|max:160', 'primary_caregiver_relationship' => 'nullable|string|max:100', 'primary_caregiver_contact' => 'nullable|string|max:80',
            'caregiver_same_address' => 'nullable|boolean', ...$address('caregiver_'),
            'family_members' => 'nullable|array|max:30',
            'family_members.*.name' => 'nullable|required_with:family_members.*.relationship_type|string|max:160', 'family_members.*.relationship_type' => 'nullable|required_with:family_members.*.name|string|max:100',
            'family_members.*.sex' => 'nullable|string|max:24', 'family_members.*.birthdate' => 'nullable|date|before_or_equal:today',
            'family_members.*.civil_status' => 'nullable|string|max:80', 'family_members.*.education' => 'nullable|string|max:160', 'family_members.*.occupation' => 'nullable|string|max:160',
            'family_members.*.same_address' => 'nullable|boolean', ...$address('family_members.*.'),

            'category' => 'nullable|in:CEFMU', 'case_type' => 'nullable|string|max:100', 'circumstance' => 'nullable|string|max:100',
            ...CefmuIncidence::rules(),
            'education_status_at_intake' => 'nullable|string|max:100', 'pregnancy_parenting_status' => 'nullable|string|max:100', 'pregnancy_status' => 'nullable|string|max:100',
            'functional_difficulty' => 'nullable|string|max:100',
            'mode_of_admission' => 'nullable|string|max:100', 'referred_by' => 'nullable|string|max:255', 'referral_date' => 'nullable|date|before_or_equal:today',
            'priority' => 'required|in:low,normal,high,urgent',

            'risk_indicators' => 'nullable|array|max:40', 'risk_indicators.*' => 'string|max:100',
            'initial_risk_level' => ['required', Rule::in(OptionSets::RISK_LEVELS)],
            'risk_level_reason' => 'nullable|string|max:1000',
            'immediate_safety_concern' => 'nullable|boolean',
            'next_review_at' => 'nullable|date',
            'narrative' => 'nullable|string|max:5000',
            // Fields the administrator added to the intake (Administration > Form options).
            'custom_fields' => 'nullable|array|max:60', 'custom_fields.*' => 'nullable',
            // Earlier intake versions (field-mode intakes saved before this release).
            'union_type' => 'nullable|string|max:60', 'partner_age_band' => 'nullable|string|max:60',
            ...collect(self::LEGACY_TEXT)->mapWithKeys(fn ($field) => [$field => 'nullable|string|max:5000'])->all(),
        ];
    }

    /** Active supplemental intake fields (form_fields). */
    public static function customFields(): array
    {
        return DB::table('form_fields')->where('form', 'intake')->where(fn ($q) => $q->whereNull('active')->orWhere('active', true))->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'key', 'label', 'type', 'required', 'options', 'help_text'])
            ->map(fn ($field) => ['id' => $field->id, 'key' => $field->key, 'label' => $field->label, 'type' => $field->type, 'required' => (bool) $field->required,
                'options' => json_decode($field->options ?? '[]', true) ?: [], 'help_text' => $field->help_text])->all();
    }

    /** Checks the answers to the supplemental fields against their definitions; unknown keys are dropped. */
    private function checkCustomFields(Validator $validator, array $data): void
    {
        $answers = (array) ($data['custom_fields'] ?? []);
        foreach (self::customFields() as $field) {
            $value = $answers[$field['key']] ?? null;
            $empty = $value === null || (is_string($value) && trim($value) === '');
            $error = fn (string $message) => $validator->errors()->add('custom_fields.' . $field['key'], $message);
            if ($empty) {
                if ($field['required']) {
                    $error('Answer "' . $field['label'] . '".');
                }

                continue;
            }
            match ($field['type']) {
                'select' => in_array($value, $field['options'], true) ?: $error('Choose one of the options for "' . $field['label'] . '".'),
                'number' => is_numeric($value) ?: $error('"' . $field['label'] . '" must be a number.'),
                'date' => strtotime((string) $value) !== false ?: $error('"' . $field['label'] . '" must be a date.'),
                'yes_no' => in_array($value, ['yes', 'no'], true) ?: $error('Answer yes or no for "' . $field['label'] . '".'),
                default => (is_string($value) && mb_strlen($value) <= ($field['type'] === 'textarea' ? 2000 : 255)) ?: $error('"' . $field['label'] . '" is too long.'),
            };
        }
    }

    public function after(Validator $validator): void
    {
        CefmuIncidence::after($validator);
        $data = $validator->getData();
        $this->checkCustomFields($validator, $data);
        $suggested = OptionSets::suggestedRiskLevel((array) ($data['risk_indicators'] ?? []));
        $chosen = array_search($data['initial_risk_level'] ?? 'low', OptionSets::RISK_LEVELS, true);
        if ($chosen !== false && $chosen < array_search($suggested['level'], OptionSets::RISK_LEVELS, true) && trim((string) ($data['risk_level_reason'] ?? '')) === '') {
            $validator->errors()->add('risk_level_reason', "The risk factors suggest \"{$suggested['level']}\". Explain why you chose a lower level.");
        }
    }

    /**
     * Creates the client, the case folder, family members and the consent
     * form photo. Run inside a transaction. Returns [case id, case number].
     *
     * @param  array<string, mixed>  $data  validated
     * @param  array{lgu_id?: ?int, location?: ?object, source?: string}  $context
     * @return array{0: int, 1: string}
     */
    public function create(array $data, User $user, array $context = []): array
    {
        $data = $this->normalizeAddresses($data, $context['location'] ?? null);
        $clientId = DB::table('clients')->insertGetId(collect($data)->only(self::CLIENT_FIELDS)->merge(['created_at' => now(), 'updated_at' => now()])->all());

        $suggested = OptionSets::suggestedRiskLevel((array) ($data['risk_indicators'] ?? []));
        $risk = (array) ($data['risk_indicators'] ?? []);
        $reportId = empty($data['narrative']) ? null : DB::table('reports')->insertGetId(['reference_no' => $this->references->nextReport(), 'client_id' => $clientId, 'status' => 'ACCEPTED',
            'narrative' => $data['narrative'], 'priority' => $data['priority'], 'submitted_at' => now(), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $caseNumber = $this->references->nextCase();
        $caseId = DB::table('cases')->insertGetId(CefmuIncidence::columns($data) + [
            'case_number' => $caseNumber, 'client_id' => $clientId, 'lgu_id' => $context['lgu_id'] ?? null, 'report_id' => $reportId, 'assigned_to' => $user->id,
            'status' => 'OPEN', 'stage' => 'intake', 'priority' => $data['priority'], 'category' => 'CEFMU', 'case_type' => $data['case_type'] ?? null,
            'circumstance' => $data['circumstance'] ?? null,
            'education_status_at_intake' => $data['education_status_at_intake'] ?? null, 'pregnancy_parenting_status' => $data['pregnancy_parenting_status'] ?? null,
            'pregnancy_status' => $data['pregnancy_status'] ?? null, 'disability_evidence' => $data['disability_evidence'] ?? null, 'functional_difficulty' => $data['functional_difficulty'] ?? null,
            'mode_of_admission' => $data['mode_of_admission'] ?? null, 'referred_by' => $data['referred_by'] ?? null, 'referral_date' => $data['referral_date'] ?? null,
            'privacy_notice_provided_at' => now(), 'privacy_notice_method' => $data['privacy_notice_method'], 'consent_form_type' => $data['consent_form_type'] ?? null,
            'initial_risk_level' => $data['initial_risk_level'], 'risk_points' => $suggested['points'], 'suggested_risk_level' => $suggested['level'],
            'risk_level_reason' => $data['risk_level_reason'] ?? null, 'risk_indicators' => json_encode(array_values($risk)),
            'immediate_safety_concern' => in_array('immediate_danger', $risk, true) || (bool) ($data['immediate_safety_concern'] ?? false),
            'next_review_at' => $data['next_review_at'] ?? null,
            'custom_fields' => ($custom = collect(self::customFields())->mapWithKeys(fn ($field) => [$field['key'] => $data['custom_fields'][$field['key']] ?? null])->filter(fn ($v) => $v !== null && $v !== '')->all()) ? json_encode($custom) : null,
            'union_type' => $data['union_type'] ?? null, 'partner_age_band' => $data['partner_age_band'] ?? null,
            ...collect(self::LEGACY_TEXT)->mapWithKeys(fn ($field) => [$field => $data[$field] ?? null])->all(),
            'intake_completed_at' => now(), 'opened_at' => now(), 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($data['family_members'] ?? [] as $member) {
            if (empty($member['name']) || empty($member['relationship_type'])) {
                continue;
            }
            $same = (bool) ($member['same_address'] ?? false);
            DB::table('relationships')->insert(['client_id' => $clientId, 'name' => $member['name'], 'relationship_type' => $member['relationship_type'], 'sex' => $member['sex'] ?? null,
                'civil_status' => $member['civil_status'] ?? null, 'birthdate' => $member['birthdate'] ?? null, 'education' => $member['education'] ?? null, 'occupation' => $member['occupation'] ?? null,
                'same_address_as_client' => $same, ...collect(self::ADDRESS)->mapWithKeys(fn ($max, $field) => [$field => $same ? null : ($member[$field] ?? null)])->all(),
                'created_at' => now(), 'updated_at' => now()]);
        }

        if (!empty($data['consent_photo'])) {
            $documentId = $this->consentImages->store($data['consent_photo'], $caseId, $user->id, $data['consent_form_type'] ?? null);
            DB::table('cases')->where('id', $caseId)->update(['consent_document_id' => $documentId]);
        }

        $this->identity->generateForClient($clientId, $user->id);
        DB::table('case_status_history')->insert(['case_id' => $caseId, 'from_status' => null, 'to_status' => 'OPEN', 'changed_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->notifications->send($user->id, 'case_assigned', 'New case assigned', 'A case has been assigned to you: ' . $caseNumber . '.', '/cases/' . $caseId);
        AuditLog::record('case.created', 'case', $caseId, ['status' => 'OPEN', 'source' => $context['source'] ?? 'online_intake', 'initial_risk_level' => $data['initial_risk_level'],
            'suggested_risk_level' => $suggested['level']], $user->id, 'success', ['new_value' => 'OPEN']);
        if (!empty($data['risk_level_reason'])) {
            AuditLog::record('case.risk_level_lowered', 'case', $caseId, ['from' => $suggested['level'], 'to' => $data['initial_risk_level']], $user->id, 'success',
                ['description' => 'Initial risk level set lower than the level suggested by the risk factors']);
        }

        return [$caseId, $caseNumber];
    }

    /** Copies the child's present address where "same address" was chosen; the case LGU is the present address. */
    private function normalizeAddresses(array $data, ?object $location): array
    {
        if ($location) {
            $data['municipality'] = $location->name;
            $data['province'] = $location->province;
            $data['region'] = $location->region;
        }
        $present = collect(self::ADDRESS)->mapWithKeys(fn ($max, $field) => [$field => $data[$field] ?? null])->all();
        $data['permanent_same_as_present'] = (bool) ($data['permanent_same_as_present'] ?? false);
        $data['caregiver_same_address'] = array_key_exists('caregiver_same_address', $data) ? (bool) $data['caregiver_same_address'] : null;
        foreach (['permanent_' => $data['permanent_same_as_present'], 'caregiver_' => (bool) $data['caregiver_same_address']] as $prefix => $same) {
            if ($same) {
                foreach ($present as $field => $value) {
                    $data[$prefix . $field] = $value;
                }
            }
        }

        return $data;
    }
}
