export interface MdtAssignment {
    id: number; purpose: string; status: string; assigned_at: string | null;
    access_expires_at: string | null; pending_actions: number;
}
export interface MdtGrant extends Omit<MdtAssignment, 'pending_actions'> {
    professional_role: string; organization: string; remarks: string | null;
    acknowledged_at: string | null; expire_on_completion: boolean;
    allowed_categories: string;
}
export interface MdtAction {
    id: number; title: string; instructions: string | null; status: string;
    due_at: string | null; responded_at: string | null; completed_at: string | null;
}
export interface MdtNote {
    id: number; category: string; findings: string | null; recommendation: string | null;
    status: string; previous_note_id: number | null; addendum_reason: string | null;
    finalized_at: string | null; created_at: string; created_by: number;
}
export interface MdtAttachment {
    id: number; name: string; purpose_category: string; mime_type: string; size_bytes: number;
    scan_state: string; created_at: string; mdt_action_id: number | null; mdt_note_id: number | null;
}
export interface MdtDiscussion { id: number; body: string; author: string; author_role: string; created_at: string }
export interface MdtCaseData {
    case_number: string; status: string; stage: string; grant: MdtGrant;
    referrer: { id: number; name: string } | null; assignments: MdtAssignment[];
    actions: MdtAction[]; notes: MdtNote[]; attachments: MdtAttachment[]; unread_discussions: number;
    summary?: { category: string | null; priority: string; opened_at: string | null };
    plans?: { id: number; summary: string | null; status: string; review_due_at: string | null }[];
    referrals?: { id: number; service_category: string | null; status: string; referred_at: string | null }[];
}
