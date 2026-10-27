export interface ClientContext {
    id: number;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    suffix?: string | null;
    birthdate: string | null;
    sex: string | null;
    phone: string | null;
    region: string | null;
    province: string | null;
    municipality: string | null;
    barangay: string | null;
    category: string | null;
    priority: string;
    civil_status?: string | null;
    education_attainment?: string | null;
    safe_contact_method?: string | null;
    safe_contact_notes?: string | null;
    primary_caregiver_name?: string | null;
    primary_caregiver_relationship?: string | null;
    primary_caregiver_contact?: string | null;
    cefmu_classifications?: string | string[] | null;
    union_type?: string | null;
    partner_age_band?: string | null;
    union_forms?: string | string[] | null;
    union_status?: string | null;
    partner_current_age?: number | null;
    union_duration_months?: number | null;
    current_residence?: string | null;
    alias?: string | null;
    education_status_at_intake?: string | null;
    pregnancy_parenting_status?: string | null;
    mode_of_admission?: string | null;
    referred_by?: string | null;
    referral_date?: string | null;
    privacy_notice_method?: string | null;
    privacy_notice_provided_at?: string | null;
    intake_completed_at?: string | null;
}

export interface SupportRelationship {
    id: number;
    name: string;
    relationship_type: string;
    phone: string | null;
    is_guardian: boolean;
    sex?: string | null;
    birthdate?: string | null;
    education?: string | null;
    occupation?: string | null;
}

/** The consent recorded at intake: the signed consent / assent form photo, if any. */
export interface ConsentForm {
    document_id: number | null;
    type: 'consent' | 'assent' | 'consent_and_assent' | null;
    name: string | null;
    added_at: string | null;
    /** True when consent can be used as the basis for a referral. */
    sharing: boolean;
}
