/**
 * Smart case search: reads plain phrases typed in the case search box and turns
 * them into list filters, so "urgent follow-up opened this month" works without
 * opening the filter panel. Rule-based and offline; nothing is sent anywhere
 * except the resulting filters. Words it does not recognise stay as a name or
 * case-number search.
 */
export type CaseFilters = { search: string; status: string; priority: string; active: boolean; attention: boolean; from: string; to: string };
export type Understood = { label: string; value: string };

const STATUS_PHRASES: [RegExp, string, string][] = [
    [/\b(for\s+)?follow[\s-]?ups?\b/, 'FOR_FOLLOW_UP', 'For follow-up'],
    [/\b(for\s+)?closure\s+review\b/, 'FOR_CLOSURE_REVIEW', 'For closure review'],
    [/\bawaiting\s+referrals?\b/, 'AWAITING_REFERRAL', 'Awaiting referral'],
    [/\bin\s+progress\b/, 'IN_PROGRESS', 'In progress'],
    [/\breopened\b/, 'REOPENED', 'Reopened'],
    [/\bclosed\b/, 'CLOSED', 'Closed'],
    [/\bnew\s+cases?\b|\bstatus\s+open\b/, 'OPEN', 'Open'],
];
const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function dateRange(text: string, today: Date): { from: string; to: string; label: string; match: RegExp } | null {
    const y = today.getFullYear(), m = today.getMonth();
    const rules: [RegExp, () => [Date, Date], string][] = [
        [/\b(opened\s+)?today\b/, () => [today, today], 'Opened today'],
        [/\b(opened\s+)?this\s+week\b/, () => { const s = new Date(y, m, today.getDate() - today.getDay()); return [s, today]; }, 'Opened this week'],
        [/\b(opened\s+)?last\s+week\b/, () => { const s = new Date(y, m, today.getDate() - today.getDay() - 7); return [s, new Date(y, m, s.getDate() + 6)]; }, 'Opened last week'],
        [/\b(opened\s+)?this\s+month\b/, () => [new Date(y, m, 1), today], 'Opened this month'],
        [/\b(opened\s+)?last\s+month\b/, () => [new Date(y, m - 1, 1), new Date(y, m, 0)], 'Opened last month'],
        [/\b(opened\s+)?this\s+year\b/, () => [new Date(y, 0, 1), today], 'Opened this year'],
    ];
    for (const [match, range, label] of rules) if (match.test(text)) { const [a, b] = range(); return { from: iso(a), to: iso(b), label, match }; }
    return null;
}

export function understandCaseSearch(input: string, today = new Date()): { filters: Partial<CaseFilters>; understood: Understood[] } {
    let text = ` ${input.toLowerCase().replace(/\s+/g, ' ')} `;
    const filters: Partial<CaseFilters> = {};
    const understood: Understood[] = [];
    const take = (pattern: RegExp) => { text = text.replace(pattern, ' '); };

    if (/\b(needs?\s+(action|attention)|overdue|due|attention)\b/.test(text)) { filters.attention = true; understood.push({ label: 'Show', value: 'Needs action' }); take(/\b(needs?\s+(action|attention)|overdue|due|attention)\b/g); }
    for (const [pattern, value, label] of STATUS_PHRASES) if (pattern.test(text)) { filters.status = value; understood.push({ label: 'Status', value: label }); take(pattern); break; }
    if (!filters.status && /\b(active|open)\b/.test(text)) { filters.active = true; understood.push({ label: 'Show', value: 'Active cases' }); take(/\b(active|open)\b/g); }
    const priority = PRIORITIES.find(word => new RegExp(`\\b${word}(\\s+priority)?\\b`).test(text));
    if (priority) { filters.priority = priority; understood.push({ label: 'Priority', value: priority[0].toUpperCase() + priority.slice(1) }); take(new RegExp(`\\b${priority}(\\s+priority)?\\b`, 'g')); }
    const range = dateRange(text, today);
    if (range) { filters.from = range.from; filters.to = range.to; understood.push({ label: 'When', value: range.label }); take(range.match); }

    // Leftover words are a name or case-number search; drop filler words.
    const rest = text.replace(/\b(cases?|show|me|my|all|with|and|the|priority|opened|status)\b/g, ' ').replace(/\s+/g, ' ').trim();
    filters.search = rest;
    return { filters, understood };
}
