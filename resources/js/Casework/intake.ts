/**
 * The Kalinga Intake (4 steps), shared by the online intake and field mode.
 * Field names match App\Services\CaseIntake on the server, which checks
 * everything again; these checks only guide the case manager step by step.
 */
import { manilaToday } from '@/utils/philippineTime';
import { MARRIAGE_CLASSIFICATIONS } from '@/data/cefmu';
import { isLowerThan, suggestRisk, type IntakeOptions, type RiskLevel } from '@/Casework/intakeRules';

export { intakeFieldId, intakeStepForField, isLowerThan, suggestRisk } from '@/Casework/intakeRules';
export { isEmptyFamilyMember, normalizePhone, tidyName } from '@/Casework/intakeText';
import { isEmptyFamilyMember } from '@/Casework/intakeText';
export type { CustomField, IntakeOptions, RiskFactor, RiskLevel, RiskRule } from '@/Casework/intakeRules';

export const INTAKE_STEPS = ['Privacy notice', 'Child & safe contact', 'Case details', 'Safety triage'] as const;

export type FamilyMember = {
    name: string; relationship_type: string; sex: string; birthdate: string; civil_status: string; education: string; occupation: string;
    same_address: boolean; house_no_street: string; region: string; province: string; municipality: string; barangay: string;
};

export type AssignedLocation = { municipality: string; province: string; region: string } | null | undefined;
export const RISK_LEVELS: Array<{ value: RiskLevel; label: string; hint: string }> = [
    { value: 'low', label: 'Low', hint: 'No immediate safety concern; regular follow-up.' },
    { value: 'moderate', label: 'Moderate', hint: 'Timely follow-up needed.' },
    { value: 'high', label: 'High', hint: 'Urgent protective response needed.' },
    { value: 'critical', label: 'Critical', hint: 'Immediate danger; emergency response now.' },
];

/** Used only when the options could not be loaded (for example field mode on a new device). */
export const DEFAULT_INTAKE_OPTIONS: IntakeOptions = {
    riskFactors: [
        { code: 'immediate_danger', label: "Immediate danger to the child's safety", definition: null, weight: 4 },
        { code: 'planned_or_impending_union', label: 'Planned or impending union', definition: null, weight: 1 },
        { code: 'pressure_after_pregnancy', label: 'Pressure to enter a union after pregnancy', definition: null, weight: 1 },
        { code: 'force_or_control', label: 'Force, coercion, or controlling behaviour', definition: null, weight: 1 },
        { code: 'age_or_power_imbalance', label: 'Age or power imbalance', definition: null, weight: 1 },
        { code: 'family_or_community_pressure', label: 'Family or community pressure', definition: null, weight: 1 },
        { code: 'economic_hardship', label: 'Economic hardship or dependence', definition: null, weight: 1 },
        { code: 'school_interruption', label: 'School interruption or dropout', definition: null, weight: 1 },
        { code: 'health_psychosocial_need', label: 'Health, SRH, or psychosocial support need', definition: null, weight: 1 },
    ],
    riskRule: { moderate: 1, high: 2, critical: 4 },
};

export function emptyIntake(location?: AssignedLocation) {
    return {
        privacy_notice_acknowledged: false, privacy_notice_method: '', consent_form_type: '', consent_photo: '',
        first_name: '', middle_name: '', last_name: '', suffix: '', alias: '', birthdate: '', sex: '', phone: '',
        disability_evidence: '', civil_status: '', religion: '', ip_category: '', education_attainment: '',
        safe_contact_method: 'Not recorded', safe_contact_notes: '',
        house_no_street: '', region: location?.region ?? '', province: location?.province ?? '', municipality: location?.municipality ?? '', barangay: '',
        permanent_same_as_present: false, permanent_house_no_street: '', permanent_region: '', permanent_province: '', permanent_municipality: '', permanent_barangay: '',
        primary_caregiver_name: '', primary_caregiver_relationship: '', primary_caregiver_contact: '',
        caregiver_same_address: false, caregiver_house_no_street: '', caregiver_region: '', caregiver_province: '', caregiver_municipality: '', caregiver_barangay: '',
        family_members: [] as FamilyMember[],
        category: 'CEFMU', cefmu_classifications: [] as string[], union_forms: [] as string[], union_status: '',
        partner_current_age: '' as number | '', union_duration_years: '' as number | '', union_duration_extra_months: '' as number | '', current_residence: '',
        education_status_at_intake: 'not_recorded', pregnancy_parenting_status: 'not_recorded',
        mode_of_admission: '', referred_by: '', referral_date: '', priority: 'normal',
        risk_indicators: [] as string[], initial_risk_level: '' as RiskLevel | '', risk_level_reason: '',
        custom_fields: {} as Record<string, string>,
    };
}
export type IntakeData = ReturnType<typeof emptyIntake>;

export const emptyFamilyMember = (): FamilyMember => ({ name: '', relationship_type: '', sex: '', birthdate: '', civil_status: '', education: '', occupation: '',
    same_address: false, house_no_street: '', region: '', province: '', municipality: '', barangay: '' });

const phoneOk = (value: string) => !value || /^(?:\+63|0)9\d{9}$/.test(value);
const todayIso = () => manilaToday();

/** Problems on one step, keyed by field name. */
export function validateIntakeStep(step: number, data: IntakeData, options: IntakeOptions): Record<string, string> {
    const errors: Record<string, string> = {};
    if (step === 1) {
        if (!data.privacy_notice_acknowledged) errors.privacy_notice_acknowledged = 'Confirm that the privacy notice was provided, or will be at the next practical opportunity.';
        if (!data.privacy_notice_method) errors.privacy_notice_method = 'Record how the privacy notice was provided.';
        if (data.consent_photo && !data.consent_form_type) errors.consent_form_type = 'Say whether the photo is a consent form, an assent form or both.';
    }
    if (step === 2) {
        if (!data.first_name.trim()) errors.first_name = 'First name is required.';
        if (!data.last_name.trim()) errors.last_name = 'Last name is required.';
        if (!data.birthdate) errors.birthdate = 'Date of birth is required.';
        else if (data.birthdate > todayIso()) errors.birthdate = 'Date of birth cannot be in the future.';
        if (!data.sex) errors.sex = "Select the child's sex.";
        if (!phoneOk(data.phone)) errors.phone = 'Use 09XXXXXXXXX or +639XXXXXXXXX.';
        data.family_members.forEach((member, index) => {
            if (isEmptyFamilyMember(member)) return;
            if (!member.name.trim()) errors[`family_members.${index}.name`] = `Family member ${index + 1}: add the name, or remove this person.`;
            if (!member.relationship_type) errors[`family_members.${index}.relationship_type`] = `Family member ${index + 1}: choose the relationship to the child.`;
        });
    }
    if (step === 3) {
        if (!data.cefmu_classifications.length) errors.cefmu_classifications = 'Select at least one CEFMU classification.';
        const formal = data.union_forms.some(form => form !== 'informal_cohabitation');
        if (formal && !data.cefmu_classifications.some(code => MARRIAGE_CLASSIFICATIONS.includes(code))) errors.union_forms = 'A formal marriage type needs "Early marriage" or "Forced marriage" in the classification.';
        const age = data.partner_current_age;
        if (age !== '' && (Number(age) < 10 || Number(age) > 99)) errors.partner_current_age = "Enter the partner's age in years (10 to 99).";
        const months = durationMonths(data);
        if (months !== null && (months < 0 || months > 600)) errors.union_duration_years = 'Enter the length in years and months.';
        if (data.referral_date && data.referral_date > todayIso()) errors.referral_date = 'Referral date cannot be in the future.';
    }
    if (step === 4) {
        if (!data.initial_risk_level) errors.initial_risk_level = 'Select the initial risk level.';
        const suggested = suggestRisk(data.risk_indicators, options);
        if (data.initial_risk_level && isLowerThan(data.initial_risk_level, suggested.level) && !data.risk_level_reason.trim()) {
            errors.risk_level_reason = `The risk factors suggest "${suggested.level}". Explain why you chose a lower level.`;
        }
    }
    return errors;
}

export function durationMonths(data: Pick<IntakeData, 'union_duration_years' | 'union_duration_extra_months'>): number | null {
    if (data.union_duration_years === '' && data.union_duration_extra_months === '') return null;
    return Number(data.union_duration_years || 0) * 12 + Number(data.union_duration_extra_months || 0);
}

/** What the server receives: trimmed text and the union length in months. */
export function intakePayload(data: IntakeData): Record<string, unknown> {
    const { union_duration_years: _years, union_duration_extra_months: _months, ...rest } = data;
    const trimmed = Object.fromEntries(Object.entries(rest).map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value]));
    return {
        ...trimmed,
        consent_photo: data.consent_photo || null,
        consent_form_type: data.consent_photo ? data.consent_form_type || 'consent' : null,
        partner_current_age: data.union_status === 'no' || data.partner_current_age === '' ? null : Number(data.partner_current_age),
        union_duration_months: data.union_status === 'no' ? null : durationMonths(data),
        family_members: data.family_members.filter(member => !isEmptyFamilyMember(member)),
    };
}
