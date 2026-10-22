const stepFields: Record<number, readonly string[]> = {
    1: ['privacy_notice_acknowledged', 'privacy_notice_method'],
    2: ['initial_risk_level', 'risk_indicators', 'immediate_safety_concern', 'immediate_actions'],
    3: ['first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'sex', 'phone', 'disability_evidence', 'safe_contact_method', 'safe_contact_notes', 'primary_caregiver_name', 'primary_caregiver_relationship', 'primary_caregiver_contact', 'region', 'province', 'municipality', 'barangay'],
    4: ['category', 'cefmu_classifications', 'union_type', 'partner_age_band', 'education_status_at_intake', 'pregnancy_parenting_status', 'cefmu_context', 'mode_of_admission', 'priority', 'referred_by', 'referral_date'],
    5: ['problem_presented', 'initial_assessment', 'safety_plan', 'next_review_at'],
};
export function intakeStepForError(field: string): number {
    const root = field.split('.')[0];
    return Number(Object.keys(stepFields).find(step => stepFields[Number(step)].includes(root)) ?? 5);
}
export function intakeFieldId(field: string): string {
    return `intake-${field.split('.')[0].replace(/[^a-zA-Z0-9_-]/g, '-')}`;
}
