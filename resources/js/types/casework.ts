export interface CaseActionRow {
    task_id: number;
    case_id: number;
    next_action: string;
    due_at: string | null;
    case_number: string;
    stage: string;
    status: string;
    priority: string;
}

export interface CaseListItem {
    id: number;
    case_number: string;
    category: string | null;
    priority: string;
    status: string;
    opened_at: string;
    created_at: string;
    first_name: string;
    last_name: string;
    municipality: string | null;
    province: string | null;
    region: string | null;
    worker_name: string | null;
    /** Rule-based next step (SmartSuggestions), null when nothing is due. */
    next_step?: { kind: string; tone: 'urgent' | 'action' | 'info'; title: string; section: string } | null;
}

export interface CaseListPage {
    data: CaseListItem[];
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface CaseTask {
    id: number;
    case_id: number | null;
    assigned_to: number;
    title: string;
    due_at: string | null;
    status: string;
    source_type?: string | null;
    source_id?: number | null;
}

export interface CaseWorkspaceSummary {
    id: number;
    case_number: string;
    first_name: string | null;
    last_name: string | null;
    category: string | null;
    status: string;
    priority: string | null;
    worker_name: string | null;
    current_risk_level?: string | null;
}
