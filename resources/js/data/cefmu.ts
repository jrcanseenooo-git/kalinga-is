/**
 * CEFMU classification and CEFMU Incidence options. Codes match
 * App\Services\CefmuIncidence on the server.
 *
 * Definitions are short working descriptions for case managers.
 * OWNER REVIEW: confirm the wording against the Kalinga pilot guidelines.
 */
export type Option = { value: string; label: string; hint?: string };

export const CEFMU_CLASSIFICATIONS: Option[] = [
    { value: 'early_marriage', label: 'Early marriage', hint: 'A formal marriage (civil, religious or customary) in which the child is below 18.' },
    { value: 'early_union', label: 'Early union', hint: 'An informal union or living together as partners, with no marriage, in which the child is below 18.' },
    { value: 'forced_marriage', label: 'Forced marriage', hint: 'A formal marriage the child did not freely and fully agree to, for example because of pressure, threats or an arrangement by others.' },
    { value: 'forced_union', label: 'Forced union', hint: 'An informal union or living together the child did not freely and fully agree to.' },
];
export const MARRIAGE_CLASSIFICATIONS = ['early_marriage', 'forced_marriage'];
export const UNION_CLASSIFICATIONS = ['early_union', 'forced_union'];

export const FORMAL_MARRIAGE_FORMS: Option[] = [
    { value: 'civil_marriage', label: 'Civil marriage', hint: 'Solemnized by a judge, mayor or other authorized officer.' },
    { value: 'religious_marriage', label: 'Church / religious marriage', hint: 'Solemnized by a priest, minister, imam or other religious leader.' },
    { value: 'customary_traditional_marriage', label: 'Traditional, cultural or customary marriage', hint: 'Under the customs of an Indigenous community or a religious or cultural tradition.' },
];
export const INFORMAL_UNION_FORM: Option = { value: 'informal_cohabitation', label: 'Cohabitation', hint: 'Living together as partners without a marriage.' };

export const UNION_STATUSES: Option[] = [
    { value: 'yes_now', label: 'Yes, now', hint: 'Currently married or living with a partner (current CEFMU).' },
    { value: 'yes_before', label: 'Yes, before', hint: 'Was married or living with a partner before, not now (former CEFMU).' },
    { value: 'no', label: 'No', hint: 'Has not been married or living with a partner, for example a planned or arranged union.' },
];

export const RESIDENCES: Option[] = [
    { value: 'parents_house', label: "Living in the parents' house" },
    { value: 'relatives_house', label: "Living in a relative's house" },
    { value: 'partner_parents_house', label: "Living in the house of the partner's parents" },
    { value: 'partner_relatives_house', label: "Living in the house of the partner's relatives" },
    { value: 'with_partner', label: 'Living in with the partner' },
];

const labelOf = (options: Option[], value?: string | null) => options.find(option => option.value === value)?.label;

export const classificationLabels = (codes: string[] = []) => codes.map(code => labelOf(CEFMU_CLASSIFICATIONS, code) ?? code.replaceAll('_', ' '));

export const unionFormLabels = (codes: string[] = []) => codes.map(code => labelOf([...FORMAL_MARRIAGE_FORMS, INFORMAL_UNION_FORM], code) ?? code.replaceAll('_', ' '));

/** "Current CEFMU" / "Former CEFMU" from the married-or-cohabiting answer. */
export const cefmuStatus = (status?: string | null) => status === 'yes_now' ? 'Current CEFMU' : status === 'yes_before' ? 'Former CEFMU' : status === 'no' ? 'Not in a marriage or union' : null;

export function durationText(months?: number | null): string | null {
    if (months === null || months === undefined) return null;
    const years = Math.floor(months / 12);
    const rest = months % 12;
    const parts = [years ? `${years} ${years === 1 ? 'year' : 'years'}` : '', rest ? `${rest} ${rest === 1 ? 'month' : 'months'}` : ''].filter(Boolean);
    return parts.join(' and ') || 'Less than a month';
}

export type IncidenceRecord = {
    union_forms?: string[] | string | null; union_type?: string | null; union_status?: string | null; partner_current_age?: number | null;
    union_duration_months?: number | null; current_residence?: string | null;
};

/** Label / value rows for profiles and printed forms; older records fall back to their union type. */
export function incidenceRows(record: IncidenceRecord): Array<[string, string]> {
    const raw = record.union_forms;
    const forms = Array.isArray(raw) ? raw : typeof raw === 'string' && raw ? (JSON.parse(raw) as string[]) : record.union_type && record.union_type !== 'not_applicable' ? [record.union_type] : [];
    return [
        ['Marriage or union', unionFormLabels(forms).join(', ') || 'Not recorded'],
        ['Married or cohabiting with a partner', [labelOf(UNION_STATUSES, record.union_status), cefmuStatus(record.union_status)].filter(Boolean).join(' - ') || 'Not recorded'],
        ["Partner's age (current)", record.partner_current_age ? `${record.partner_current_age} years old` : 'Not recorded'],
        ['Length of marriage or cohabitation', durationText(record.union_duration_months) ?? 'Not recorded'],
        ['Current residence', labelOf(RESIDENCES, record.current_residence) ?? 'Not recorded'],
    ];
}
