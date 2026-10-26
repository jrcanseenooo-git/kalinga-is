export type ReportOffice = { lgu: string | null; province: string | null; region: string | null; name: string | null; short: string | null; address: string | null; contact: string | null; logo: string | null };
export type ReportType = 'intake' | 'notes' | 'plan' | 'summary' | 'study';
export type Person = { name: string; position: string | null };
export type Signatory = { name: string; position: string | null; designation: string | null };
export type FamilyMember = { name: string; relationship_type: string; sex: string | null; civil_status?: string | null; birthdate: string | null; education: string | null; occupation: string | null; area?: string | null; street?: string | null };
export type Referral = { id: number; service_category: string; referred_to: string | null; purpose: string | null; status: string; referred_at: string | null; completed_at: string | null; outcome: string | null; case_note_id: number | null; created_at: string };
export type Service = { id: number; service_type: string; specific_detail: string | null; amount: string | number | null; date_provided: string; provided_by: string | null };
export type GrantItem = { id: number; grant_type: string; type_label: string; amount: number; released_at: string; fund_source: string | null; purpose: string | null; received_by: string | null; voided_at: string | null; void_reason: string | null; recorded_by_name: string | null; created_at: string };
export type SummaryNote = { id: number; type: string | null; at: string | null; body: string; action: string | null; next: string | null; author: string | null };
export type ProgressNote = SummaryNote & { author_position: string | null; addenda: { at: string | null; reason: string | null; body: string; author: string | null }[] };
export type PlanIntervention = { activity: string | null; service_category: string | null; responsible: string | null; start_date: string | null; target_date: string | null; status: string | null; expected_outcome: string | null };
export type PlanGoal = { domain: string; sensitive: boolean; priority: string | null; target_date: string | null; status: string | null; need_problem: string | null; goal_statement: string | null; outcome: string | null; interventions: PlanIntervention[] };

/** Props common to every report (App\Services\CaseReports::build). */
export interface ReportBase {
    type: ReportType; title: string; reportNo: string; generatedAt: string; caseId: number;
    office: ReportOffice; preparedBy: Person; notedBy: Signatory | null;
    caseInfo: Record<string, any>; client: Record<string, any>; family: FamilyMember[];
}
export interface ReportProps extends ReportBase {
    // summary / study
    notes?: SummaryNote[]; referrals?: Referral[]; services?: Service[];
    assessment?: { date: string | null; sections: { key: string; label: string; text: string }[] };
    risk?: { overall_risk_level: string; assessed_at: string; required_action: string | null } | null;
    // summary / study use a light plan; the intervention plan report uses the full one
    plan?: any;
    // intake
    intake?: { privacy_notice_method: string | null; privacy_notice_at: string | null; consent_status: string | null; data_sharing_basis: string | null; immediate_safety_concern: boolean; risk_indicators: string[]; next_review_at: string | null; remarks: string | null; health_recorded: boolean };
    // Kalinga forms written by the case manager (summary / plan / study)
    form?: { status: string; version: number | null; content: { sections?: Record<string, string>; rows?: Record<string, string>[] }; hasContent: boolean;
        sections: { key: string; title: string; kind: 'text' | 'auto'; guide: string | null }[]; planColumns: Record<string, string> };
    planMatrix?: Record<string, string>[] | null;
    kavat?: { administered_at: string; occasion: string; empowerment_rate: string | number; safety_rate: string | number; final_score: string | number; priority: string; priority_label: string } | null;
    grants?: { items: GrantItem[]; total: number; count: number } | null;
    // progress notes
    period?: { from: string | null; to: string | null }; progressNotes?: ProgressNote[];
}
