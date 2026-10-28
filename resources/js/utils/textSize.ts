/**
 * Text size for the forms (Kalinga Intake, KaVAT, Kalinga forms) only; the
 * sidebar, header and other pages keep their size (owner instruction
 * 2026-10-28). A per-device reading preference, not personal data, so it is
 * kept in this browser's localStorage. Forms marked .kalinga-form are scaled
 * with the --form-zoom value set here.
 */
export type TextSize = 'normal' | 'large' | 'larger';
export const TEXT_SIZES: Array<{ value: TextSize; label: string; scale: string }> = [
    { value: 'normal', label: 'Normal text', scale: '1' },
    { value: 'large', label: 'Large text', scale: '1.12' },
    { value: 'larger', label: 'Larger text', scale: '1.25' },
];
const KEY = 'kalinga.textSize';

export function savedTextSize(): TextSize {
    try {
        const value = localStorage.getItem(KEY);
        return TEXT_SIZES.some(size => size.value === value) ? (value as TextSize) : 'normal';
    } catch {
        return 'normal';
    }
}

export function applyTextSize(size: TextSize): void {
    const entry = TEXT_SIZES.find(item => item.value === size) ?? TEXT_SIZES[0];
    document.documentElement.style.fontSize = '';
    document.documentElement.style.setProperty('--form-zoom', entry.scale);
    document.documentElement.dataset.textSize = entry.value;
}

export function setTextSize(size: TextSize): void {
    applyTextSize(size);
    try { localStorage.setItem(KEY, size); } catch { /* Private mode: applies for this visit only. */ }
}
