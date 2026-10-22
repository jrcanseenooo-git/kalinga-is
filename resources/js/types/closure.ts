import type { ContinuityRecord } from './planning';

export interface ClosureRecord {
    id: number | null; version: number | null; source: 'authored_record' | 'legacy_case_summary';
    summary?: string | null; recorded_by: number | null; recorded_at: string | null;
    reviewed_by: number | null; reviewed_at: string | null; closed_at: string | null;
    superseded_at?: string | null; created_at: string | null; author_name?: string | null; reviewer_name?: string | null;
}
export interface ClosureWorkspace {
    case: { id: number; case_number: string; status: string; offline_version: number; closed_at: string | null; retention_review_at: string | null };
    current: ClosureRecord | null; surviving_projection: ClosureRecord | null; projection_drift: boolean;
    history: { data: ClosureRecord[]; current_page: number; last_page: number; total: number };
    readiness: { critical_blockers: { code: string }[]; warnings: { code: string }[]; policy_validation_required: boolean };
    allowed_actions: { record: boolean; review: boolean; close: boolean; reopen: boolean };
    aftercare: ContinuityRecord[]; reintegration: ContinuityRecord[];
}
