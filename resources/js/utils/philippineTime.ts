/**
 * Dates and times in Philippine time (Asia/Manila), whatever the device's own
 * time zone. Use these helpers instead of `new Date(value)` on server values:
 * the database returns timestamps in UTC without a zone ("2026-10-22 03:04:31"),
 * which `new Date()` would wrongly read as device-local time.
 */
const zone = 'Asia/Manila';

/** Reads a server value: "YYYY-MM-DD" (a calendar date), a UTC timestamp without zone, or ISO with offset. */
export function parseStored(value: string | Date | null | undefined): Date | null {
    if (!value) return null;
    if (value instanceof Date) return Number.isFinite(value.getTime()) ? value : null;
    const text = String(value).trim();
    const iso = /^\d{4}-\d{2}-\d{2}$/.test(text) ? `${text}T00:00:00+08:00` : text.replace(' ', 'T');
    const parsed = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(iso) ? iso : `${iso}Z`);
    return Number.isFinite(parsed.getTime()) ? parsed : null;
}

const PRESETS = {
    date: { month: 'short', day: 'numeric', year: 'numeric' },            // Oct 22, 2026
    dateLong: { month: 'long', day: 'numeric', year: 'numeric' },         // October 22, 2026
    dateShort: { month: 'short', day: 'numeric' },                        // Oct 22
    dateTime: { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' },
    dateTimeShort: { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }, // Oct 22, 3:00 PM
    time: { hour: 'numeric', minute: '2-digit' },                         // 3:00 PM
    weekdayLong: { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' },
    weekdayDate: { weekday: 'long', month: 'long', day: 'numeric' },
    weekdayShortDate: { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' },
    day: { day: 'numeric' },
    monthShort: { month: 'short' },
    monthYear: { month: 'long', year: 'numeric' },
    monthYearShort: { month: 'short', year: 'numeric' },
} satisfies Record<string, Intl.DateTimeFormatOptions>;
export type DatePreset = keyof typeof PRESETS;

const formatters = new Map<DatePreset, Intl.DateTimeFormat>();
/** Formats a server value in Philippine time; returns `empty` when missing or invalid. */
export function formatPh(value: string | Date | null | undefined, preset: DatePreset = 'date', empty = ''): string {
    const date = parseStored(value);
    if (!date) return empty;
    if (!formatters.has(preset)) formatters.set(preset, new Intl.DateTimeFormat('en-PH', { timeZone: zone, ...PRESETS[preset] }));
    return formatters.get(preset)!.format(date);
}

/** Calendar day key "YYYY-MM-DD" in Philippine time. */
export function manilaDayKey(value: string | Date = new Date()): string {
    const date = parseStored(value) ?? new Date();
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
    const part = (key: Intl.DateTimeFormatPartTypes) => parts.find(item => item.type === key)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}`;
}

/** Age in whole years on a date (default today, Philippine calendar) from a "YYYY-MM-DD" birthdate. */
export function ageFromBirthdate(birthdate: string | null | undefined, on: string | Date = new Date()): number | null {
    if (!birthdate || !/^\d{4}-\d{2}-\d{2}/.test(birthdate)) return null;
    const [by, bm, bd] = birthdate.slice(0, 10).split('-').map(Number);
    const [ty, tm, td] = manilaDayKey(on).split('-').map(Number);
    return ty - by - (tm < bm || (tm === bm && td < bd) ? 1 : 0);
}

/** Hour of day (0-23) in Philippine time. */
export const manilaHour = (value: Date = new Date()) => Number(new Intl.DateTimeFormat('en', { timeZone: zone, hour: 'numeric', hourCycle: 'h23' }).format(value));

/** Today's date "YYYY-MM-DD" in the Philippines (not the UTC date, which lags until 8 AM). */
export const manilaToday = (value: Date = new Date()) => manilaDateTime(value).slice(0, 10);

/** "YYYY-MM-DDTHH:MM" for a datetime-local input, in Philippine time. */
export function manilaDateTime(value: Date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(value);
    const part = (key: Intl.DateTimeFormatPartTypes) => parts.find(item => item.type === key)?.value ?? '';
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
}

/** A datetime-local value typed in Philippine time, as a UTC ISO string for the server. */
export function manilaInputToUtc(value: string): string {
    if (!value) return '';
    const parsed = new Date(`${value}+08:00`);
    return Number.isFinite(parsed.getTime()) ? parsed.toISOString() : '';
}

/** A stored timestamp as a datetime-local input value in Philippine time. */
export function storedUtcToManilaInput(value: string | null | undefined): string {
    if (!value) return '';
    const iso = value.replace(' ', 'T');
    const parsed = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/.test(iso) ? iso : `${iso}Z`);
    return Number.isFinite(parsed.getTime()) ? manilaDateTime(parsed) : '';
}

/** Timestamp for records: "Oct 22, 2026, 11:04 AM". Historical explicit offsets are preserved. */
export function philippineTimestamp(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    const parsed = parseStored(value);
    return parsed ? new Intl.DateTimeFormat('en-PH', { timeZone: zone, dateStyle: 'medium', timeStyle: 'short' }).format(parsed) : 'Time unavailable';
}

/** A calendar date ("YYYY-MM-DD" or the date part of a timestamp) without time-zone shifting. */
export function calendarDateLabel(value: string): string {
    const parsed = new Date(`${value.slice(0, 10)}T00:00:00Z`);
    return Number.isFinite(parsed.getTime()) ? new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', dateStyle: 'medium' }).format(parsed) : 'Date unavailable';
}
