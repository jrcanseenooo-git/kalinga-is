/** Kalinga forms (App\Services\CaseForms) as sent to the case workspace. */
export type FormType = 'summary' | 'plan' | 'study';
export type FormSection = { key: string; title: string; kind: 'text' | 'auto'; guide: string | null };
export type PlanRow = Record<string, string>;
export type FormContent = { sections?: Record<string, string>; rows?: PlanRow[] };
export type FormVersion = { form_type: string; version: number; created_at: string; finalized_by: string | null };
export type CaseForm = { type: FormType; title: string; content: FormContent; status: 'not_started' | 'draft' | 'final'; revision: number; updated_at: string | null; versions: FormVersion[];
  /** Social Case Study Report sections currently showing Case Summary Report text (saved with the next change). */
  carried?: string[] };
export type FormsDefinition = { types: Record<FormType, string>; sequence: Record<string, string>; sections: Record<'summary' | 'study', FormSection[]>; planColumns: Record<string, string> };

export const emptyPlanRow = (columns: Record<string, string>): PlanRow => Object.fromEntries(Object.keys(columns).map((key) => [key, '']));

export function formHasContent(type: FormType, content: FormContent): boolean {
  if (type === 'plan') return (content.rows ?? []).some((row) => Object.values(row).some((value) => String(value).trim() !== ''));
  return Object.values(content.sections ?? {}).some((value) => String(value).trim() !== '');
}

export const wordCount = (text: string) => (text.trim() ? text.trim().split(/\s+/).length : 0);

/** How complete a narrative form is: written text sections over all text sections. */
export function sectionProgress(sections: FormSection[], content: FormContent): { written: number; total: number } {
  const text = sections.filter((section) => section.kind === 'text');
  return { written: text.filter((section) => (content.sections?.[section.key] ?? '').trim() !== '').length, total: text.length };
}
