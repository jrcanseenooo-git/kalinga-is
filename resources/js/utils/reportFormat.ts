/**
 * Formatting shared by the printable case reports. Dates are Philippine time,
 * written the way government documents write them ("October 22, 2026").
 */
import { ageFromBirthdate, parseStored } from '@/utils/philippineTime';

const TZ = 'Asia/Manila';
const parse = (value: string) => parseStored(value) ?? new Date(NaN);

export const longDate = (value?: string | null) => value ? new Intl.DateTimeFormat('en-PH', { timeZone: TZ, year: 'numeric', month: 'long', day: 'numeric' }).format(parse(value)) : 'Not recorded';
export const shortDate = (value?: string | null) => value ? new Intl.DateTimeFormat('en-PH', { timeZone: TZ, year: 'numeric', month: 'short', day: 'numeric' }).format(parse(value)) : '-';
export const dateTime = (value?: string | null) => value ? new Intl.DateTimeFormat('en-PH', { timeZone: TZ, year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }).format(parse(value)) : '-';

/** "IN_PROGRESS" / "customary_traditional_marriage" to "In progress" / "Customary traditional marriage". */
export const human = (value?: string | null) => { if (!value) return 'Not recorded'; const text = value.replaceAll('_', ' ').toLowerCase(); return text.charAt(0).toUpperCase() + text.slice(1); };
export const peso = (value: string | number | null | undefined) => value === null || value === undefined || value === '' ? '-' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));

/** First letter upper case, rest unchanged ('mother' to 'Mother'). */
export const capitalize = (value?: string | null) => value ? value.charAt(0).toUpperCase() + value.slice(1) : '-';

/** Age in whole years on a date (Philippine calendar). */
export const ageOn = (birth?: string | null, on: string | Date = new Date()): number | null => ageFromBirthdate(birth, on);

const WORDS = ['no', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];
/** Government style counts: "two (2) referrals", "one (1) referral", "no referrals". */
export function counted(n: number, singular: string, plural = `${singular}s`): string {
    if (n === 0) return `no ${plural}`;
    const word = WORDS[n] ?? String(n);
    return `${word} (${n}) ${n === 1 ? singular : plural}`;
}

/** "Maria Santos Dela Cruz" to "M. S. D. C."; empty parts are skipped. */
export const initialsOf = (...parts: (string | null | undefined)[]) => parts.filter(Boolean).flatMap(part => String(part).trim().split(/\s+/)).map(part => `${part[0].toUpperCase()}.`).join(' ');

/** Shows a phone number only when full details are revealed. */
export const maskContact = (value: string | null | undefined, reveal: boolean) => !value ? 'Not recorded' : reveal ? value : `*******${value.replace(/\D/g, '').slice(-3)}`;

export const CLASSIFICATION_LABELS: Record<string, string> = { early_union: 'Early union', forced_marriage: 'Forced marriage' };
export const classificationText = (items: string[]) => items.map(item => CLASSIFICATION_LABELS[item] ?? human(item)).join(', ');

/** Care-plan domains, as labelled in the plan workspace. */
export const DOMAIN_LABELS: Record<string, string> = {
    safety_protection: 'Safety and protection', health: 'Health', reproductive_health: 'Reproductive health', mental_health_psychosocial: 'Mental health and psychosocial',
    education: 'Education', family_strengthening: 'Family strengthening', economic_social_protection: 'Economic and social protection', legal_justice: 'Legal and justice',
    child_development: 'Child development', community_support: 'Community support', reintegration: 'Reintegration', aftercare: 'Aftercare', other: 'Other',
};
