/**
 * How a child's name appears on forms and shared views. Same rules as
 * App\Support\ClientName on the server.
 *
 * Masked: first letter of the first name + "**", first letter of the middle
 * name + "***", first letter of the last name + "**" + its last two letters.
 * "John Santos Dela Cruz" -> "J**S***D**UZ".
 */
export type NameMode = 'masked' | 'alias' | 'full';

export const NAME_MODES: Array<{ value: NameMode; label: string }> = [
    { value: 'masked', label: 'Masked' },
    { value: 'alias', label: 'Alias' },
    { value: 'full', label: 'Full name' },
];

type NameParts = { first_name?: string | null; middle_name?: string | null; last_name?: string | null; suffix?: string | null; alias?: string | null };

const letters = (value?: string | null) => (value ?? '').replace(/[^\p{L}]/gu, '');

export function maskedName(client: NameParts): string {
    const first = letters(client.first_name);
    const middle = letters(client.middle_name);
    const last = letters(client.last_name);
    const parts = [
        first ? `${first[0]}**` : '',
        middle ? `${middle[0]}***` : '',
        last ? `${last[0]}**${last.length > 2 ? last.slice(-2) : ''}` : '',
    ];
    return parts.join('').toUpperCase() || 'Not recorded';
}

export function fullName(client: NameParts): string {
    return [client.first_name, client.middle_name, client.last_name, client.suffix].filter(Boolean).join(' ') || 'Not recorded';
}

export function clientDisplayName(client: NameParts, mode: NameMode): string {
    if (mode === 'full') return fullName(client);
    if (mode === 'alias' && client.alias?.trim()) return client.alias.trim();
    return maskedName(client);
}
