/**
 * Text size for the whole app (a per-device reading preference, not personal
 * data, so it is kept in this browser's localStorage). Every size in Kalinga
 * is relative to the page font size, so changing it enlarges text, fields and
 * buttons together; the layout switches to the phone layout earlier when the
 * text is bigger.
 */
export type TextSize = 'normal' | 'large' | 'larger';
export const TEXT_SIZES: Array<{ value: TextSize; label: string; scale: string }> = [
    { value: 'normal', label: 'Normal text', scale: '100%' },
    { value: 'large', label: 'Large text', scale: '112.5%' },
    { value: 'larger', label: 'Larger text', scale: '125%' },
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
    document.documentElement.style.fontSize = entry.value === 'normal' ? '' : entry.scale;
    document.documentElement.dataset.textSize = entry.value;
}

export function setTextSize(size: TextSize): void {
    applyTextSize(size);
    try { localStorage.setItem(KEY, size); } catch { /* Private mode: applies for this visit only. */ }
}
