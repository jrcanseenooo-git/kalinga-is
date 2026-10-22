import type { PlanningData } from './planning';

export interface ConferenceSummary {
    id: number; conference_type: string; status: string; scheduled_at: string | null;
    finalized_at: string | null; next_review_at: string | null; record_type: string;
    previous_conference_id: number | null;
}
export interface ConferenceRecord extends ConferenceSummary {
    purpose: string | null; other_type_reason: string | null; venue_mode: string | null;
    facilitator_name: string | null; recorder_name: string | null;
    case_updates: string | null; key_issues: string | null; family_perspective: string | null;
    professional_observations: string | null; child_participation_status: string | null;
    child_participation_record_id: number | null; risk_assessment_id: number | null;
    confidentiality: string; review_comments: string | null; addendum_reason: string | null;
}
export interface ConferenceParticipant { id: number; name: string; professional_role: string | null; organization: string | null; attendance_status: string; participation_mode: string | null; conference_role: string | null }
export interface ConferenceDecision { id: number; decision_type: string; statement: string }
export interface ConferenceAction { id: number; action: string; category: string | null; responsible_office: string | null; target_at: string | null; priority: string; local_status: string; source_type: string | null; source_id: number | null; operational_status?: string | null; outcome_remarks?: string | null }
export interface ConferenceHistory { id: number; event: string; actor_name: string | null; actor_role: string | null; comments: string | null; created_at: string; prior_scheduled_at: string | null; new_scheduled_at: string | null }
export interface ConferenceDetail {
    conference: ConferenceRecord; participants: ConferenceParticipant[]; decisions: ConferenceDecision[];
    actions: ConferenceAction[]; history: ConferenceHistory[]; allowed_actions: Record<string, boolean>;
}
export interface ConferenceSource { id: number; title: string | null; service_category?: string }
export interface ConferenceIndex {
    caseStatus?:string;
    conferences: ConferenceSummary[]; sources: Record<string, ConferenceSource[]>;
    riskReferences: {id:number;assessed_at:string}[]; participationReferences:{id:number;version:number}[];
}
export interface ConferenceOperation { url:string; method:'post'|'patch'; body:PlanningData; key?:string; defaults?:PlanningData; selectCreated?:boolean; success:string }
