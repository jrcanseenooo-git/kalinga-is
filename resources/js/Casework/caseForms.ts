/** Kalinga forms (App\Services\CaseForms) as sent to the case workspace. */
export type FormType = 'summary' | 'plan' | 'study';
export type FormSection = { key: string; title: string; kind: 'text' | 'auto'; guide: string | null };
/**
 * A row of the Intervention Plan matrix: the printed columns plus, not printed,
 * a stable key (links referrals), a status and target start / end dates for
 * the calendar and monitoring (same rules as App\Services\CaseForms).
 */
export type PlanRow = Record<string, string>;
export const PLAN_ROW_META = ['id', 'status', 'target_start', 'target_end'] as const;
export const PLAN_ROW_STATUSES = { not_started: 'Not started', in_progress: 'In progress', done: 'Done' } as const;
export type PlanRowStatus = keyof typeof PLAN_ROW_STATUSES;
/** The Intervention Plan is submitted within this many days of the intake (App\Services\CaseForms::PLAN_DUE_DAYS). */
export const PLAN_DUE_DAYS = 7;
export type PlanDeadline = { due: string; submitted_at: string | null; days_to_submit: number | null; days_left: number | null; overdue: boolean };
export type FormContent = { sections?: Record<string, string>; rows?: PlanRow[] };
export type FormVersion = { form_type: string; version: number; created_at: string; finalized_by: string | null };
export type CaseForm = { type: FormType; title: string; content: FormContent; status: 'not_started' | 'draft' | 'final'; revision: number; updated_at: string | null; versions: FormVersion[];
  /** Social Case Study Report sections currently showing Case Summary Report text (saved with the next change). */
  carried?: string[] };
export type FormsDefinition = { types: Record<FormType, string>; sequence: Record<string, string>; sections: Record<'summary' | 'study', FormSection[]>; planColumns: Record<string, string> };

const newRowId = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `row-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`);
export const emptyPlanRow = (columns: Record<string, string>): PlanRow => ({ ...Object.fromEntries(Object.keys(columns).map((key) => [key, ''])), id: newRowId(), status: 'not_started', target_start: '', target_end: '' });
/** An older row without the not-printed fields gets them (keeping its text). */
export const normalizePlanRow = (columns: Record<string, string>, row: PlanRow): PlanRow => {
  const base = emptyPlanRow(columns);
  return { ...base, ...row, id: row.id || base.id, status: row.status && row.status in PLAN_ROW_STATUSES ? row.status : 'not_started' };
};
/** True when a printed column of the row has text. */
export const planRowHasText = (row: PlanRow) => Object.entries(row).some(([key, value]) => !(PLAN_ROW_META as readonly string[]).includes(key) && String(value ?? '').trim() !== '');

export function formHasContent(type: FormType, content: FormContent): boolean {
  if (type === 'plan') return (content.rows ?? []).some(planRowHasText);
  return Object.values(content.sections ?? {}).some((value) => String(value).trim() !== '');
}

export const wordCount = (text: string) => (text.trim() ? text.trim().split(/\s+/).length : 0);

/** How complete a narrative form is: written text sections over all text sections. */
export function sectionProgress(sections: FormSection[], content: FormContent): { written: number; total: number } {
  const text = sections.filter((section) => section.kind === 'text');
  return { written: text.filter((section) => (content.sections?.[section.key] ?? '').trim() !== '').length, total: text.length };
}
