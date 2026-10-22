import { formatPh } from '@/utils/philippineTime';

// Shared display helpers so statuses and priorities read the same everywhere.
// They only change presentation; stored values and workflow rules are untouched.
export type Tone = 'neutral' | 'brand' | 'danger' | 'warning' | 'success' | 'info';

export const titleCase = (value: string | null | undefined): string =>
    String(value ?? '').toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

const STATUS_TONES: Record<string, Tone> = {
    OPEN: 'brand',
    IN_PROGRESS: 'info',
    AWAITING_REFERRAL: 'warning',
    FOR_FOLLOW_UP: 'info',
    FOR_CLOSURE_REVIEW: 'warning',
    CLOSED: 'success',
    REOPENED: 'brand',
};

export const statusTone = (status: string | null | undefined): Tone => STATUS_TONES[String(status ?? '').toUpperCase()] ?? 'neutral';

export const priorityTone = (priority: string | null | undefined): Tone => {
    const value = String(priority ?? '').toLowerCase();
    if (value === 'urgent' || value === 'high') return 'danger';
    if (value === 'normal' || value === 'medium') return 'warning';
    if (value === 'low') return 'success';
    return 'neutral';
};

export const riskTone = (risk: string | null | undefined): Tone => {
    const value = String(risk ?? '').toLowerCase();
    if (value === 'critical' || value === 'high') return 'danger';
    if (value === 'moderate' || value === 'medium') return 'warning';
    if (value === 'low') return 'success';
    return 'neutral';
};

// Referral lifecycle: DRAFT -> PENDING -> SENT -> ACCEPTED -> IN_PROGRESS -> COMPLETED,
// with DECLINED, WITHDRAWN, UNAVAILABLE and RETURNED as end states.
export const REFERRAL_ACTIVE = ['DRAFT', 'PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS'];
export const REFERRAL_CLOSED = ['DECLINED', 'WITHDRAWN', 'UNAVAILABLE', 'RETURNED'];
export const referralTone = (status: string | null | undefined): Tone => {
    const value = String(status ?? '').toUpperCase();
    if (value === 'COMPLETED') return 'success';
    if (value === 'ACCEPTED' || value === 'IN_PROGRESS') return 'info';
    if (value === 'PENDING' || value === 'SENT') return 'warning';
    if (REFERRAL_CLOSED.includes(value)) return 'neutral';
    return 'brand';
};

// Goal, intervention and visit progress values (lower-case snake case in storage).
export const progressTone = (value: string | null | undefined): Tone => {
    const key = String(value ?? '').toLowerCase();
    if (['completed', 'achieved', 'met', 'cleared'].includes(key)) return 'success';
    if (['in_progress', 'ongoing', 'active', 'partially_met', 'partially_achieved'].includes(key)) return 'info';
    if (['not_started', 'pending', 'scheduled', 'draft', 'awaiting_scan'].includes(key)) return 'warning';
    if (['discontinued', 'not_met', 'cancelled', 'quarantined', 'rejected'].includes(key)) return 'danger';
    return 'neutral';
};

export const shortDate = (value: string | null | undefined): string => {
    if (!value) return '';
    return formatPh(value, 'date', String(value));
};
