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
    education_status_at_intake?: string | null;
    pregnancy_parenting_status?: string | null;
    mode_of_admission?: string | null;
    referred_by?: string | null;
    referral_date?: string | null;
    privacy_notice_method?: string | null;
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

export interface ConsentEntry {
    id: number;
    lawful_basis: string;
    purposes: string | string[];
    sharing_allowed: boolean;
    granted_at: string;
    withdrawn_at: string | null;
}
