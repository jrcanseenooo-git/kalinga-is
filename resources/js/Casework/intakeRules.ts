/**
 * Intake rules without dependencies (unit tested in tests/frontend):
 * which step holds a field, and the risk level suggested by the risk points.
 */
export type RiskFactor = { code: string; label: string; definition: string | null; weight: number };
export type RiskRule = { moderate: number; high: number; critical: number };
/** A field the administrator added to the intake (Administration > Form options). */
export type CustomField = { id: number; key: string; label: string; type: 'text' | 'textarea' | 'select' | 'date' | 'number' | 'yes_no'; required: boolean; options: string[]; help_text: string | null };
export type IntakeOptions = { riskFactors: RiskFactor[]; riskRule: RiskRule; customFields?: CustomField[] };
export type RiskLevel = 'low' | 'moderate' | 'high' | 'critical';

const LEVEL_ORDER: RiskLevel[] = ['low', 'moderate', 'high', 'critical'];

export function suggestRisk(codes: string[], options: IntakeOptions): { level: RiskLevel; points: number } {
    const points = options.riskFactors.filter(factor => codes.includes(factor.code)).reduce((sum, factor) => sum + Number(factor.weight || 0), 0);
    const rule = options.riskRule;
    const level: RiskLevel = points >= rule.critical ? 'critical' : points >= rule.high ? 'high' : points >= rule.moderate ? 'moderate' : 'low';
    return { level, points };
}
export const isLowerThan = (chosen: string, suggested: RiskLevel) => LEVEL_ORDER.indexOf(chosen as RiskLevel) > -1 && LEVEL_ORDER.indexOf(chosen as RiskLevel) < LEVEL_ORDER.indexOf(suggested);

const STEP_FIELDS: Record<number, readonly string[]> = {
    1: ['privacy_notice_acknowledged', 'privacy_notice_method', 'consent_form_type', 'consent_photo'],
    2: ['first_name', 'middle_name', 'last_name', 'suffix', 'alias', 'birthdate', 'sex', 'phone', 'disability_evidence', 'civil_status', 'religion', 'ip_category', 'education_attainment',
        'safe_contact_method', 'safe_contact_notes', 'house_no_street', 'region', 'province', 'municipality', 'barangay', 'permanent_same_as_present', 'permanent_house_no_street',
        'permanent_region', 'permanent_province', 'permanent_municipality', 'permanent_barangay', 'primary_caregiver_name', 'primary_caregiver_relationship', 'primary_caregiver_contact',
        'caregiver_same_address', 'caregiver_house_no_street', 'caregiver_region', 'caregiver_province', 'caregiver_municipality', 'caregiver_barangay', 'family_members'],
    3: ['category', 'cefmu_classifications', 'union_forms', 'union_status', 'partner_current_age', 'union_duration_months', 'union_duration_years', 'current_residence',
        'education_status_at_intake', 'pregnancy_parenting_status', 'mode_of_admission', 'referred_by', 'referral_date', 'priority'],
    4: ['risk_indicators', 'initial_risk_level', 'risk_level_reason'],
};
export const intakeStepForField = (field: string) => Number(Object.keys(STEP_FIELDS).find(step => STEP_FIELDS[Number(step)].includes(field.split('.')[0])) ?? 4);
export const intakeFieldId = (field: string) => `intake-${field.split('.')[0].replace(/[^a-zA-Z0-9_-]/g, '-')}`;

