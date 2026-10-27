/** Small text helpers for the Kalinga Intake (no imports, so they are unit-tested directly). */

/** True when nothing was typed or chosen for this person (the card is then simply dropped). */
export function isEmptyFamilyMember(member: object): boolean {
    return Object.entries(member).every(([key, value]) => key === 'same_address' || (typeof value === 'string' ? !value.trim() : !value));
}

/** "+63 917-123 4567" or "0917 123 4567" -> "09171234567"; anything else is left as typed. */
export function normalizePhone(value: string): string {
    const digits = value.replace(/[\s().-]/g, '');
    if (/^\+639\d{9}$/.test(digits)) return '0' + digits.slice(3);
    if (/^639\d{9}$/.test(digits)) return '0' + digits.slice(2);
    return /^09\d{9}$/.test(digits) ? digits : value.trim();
}

/** "MARIA cruz" -> "Maria Cruz" when the name was typed all in capitals or all in small letters. */
export function tidyName(value: string): string {
    const trimmed = value.replace(/\s+/g, ' ').trim();
    if (!trimmed || (trimmed !== trimmed.toUpperCase() && trimmed !== trimmed.toLowerCase())) return trimmed;
    return trimmed.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (_match, start: string, letter: string) => start + letter.toUpperCase());
}
