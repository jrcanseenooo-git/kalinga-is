// Referral tracking helpers: lifecycle steps, follow-up due dates and history.
// Presentation only; the server enforces transitions and required fields.
import { shortDate, titleCase, type Tone } from '@/utils/caseDisplay';
import { parseStored } from '@/utils/philippineTime';

export interface ReferralEvent { from_status: string | null; to_status: string; remarks?: string | null; outcome?: string | null; reason?: string | null; actor?: string | null; created_at: string | null }
export interface TrackedReferral {
  id: number; status: string; service_category: string; provider?: string | null; reason?: string | null;
  outcome?: string | null; status_reason?: string | null;
  created_at: string; referred_at?: string | null; accepted_at?: string | null; completed_at?: string | null;
  events?: ReferralEvent[];
}
export interface ReferralTargets { response_days: number; completion_days: number }

export const REFERRAL_STEPS = ['DRAFT', 'PENDING', 'SENT', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED'] as const;
export const REFERRAL_END_STATES = ['DECLINED', 'RETURNED', 'UNAVAILABLE', 'WITHDRAWN'];
export const OUTCOME_REQUIRED = ['COMPLETED'];
export const REASON_REQUIRED = ['DECLINED', 'RETURNED', 'UNAVAILABLE', 'WITHDRAWN'];

const DAY = 86400000;
const days = (from: string, to = new Date()) => Math.floor((to.getTime() - (parseStored(from)?.getTime() ?? to.getTime())) / DAY);

/** Index of the furthest lifecycle step reached (end states keep the step they stopped at). */
export function stepReached(item: TrackedReferral): number {
  const direct = REFERRAL_STEPS.indexOf(item.status as typeof REFERRAL_STEPS[number]);
  if (direct >= 0) return direct;
  if (item.accepted_at) return 3;
  if (item.referred_at) return 2;
  return 1;
}

/** Next follow-up date: a response after sending, completion after acceptance. */
export function followUp(item: TrackedReferral, targets: ReferralTargets, now = new Date()): { label: string; tone: Tone; overdue: boolean } | null {
  let start: string | null | undefined; let limit = 0; let kind = '';
  if (item.status === 'SENT' && item.referred_at) { start = item.referred_at; limit = targets.response_days; kind = 'Response'; }
  else if (['ACCEPTED', 'IN_PROGRESS'].includes(item.status) && item.accepted_at) { start = item.accepted_at; limit = targets.completion_days; kind = 'Completion'; }
  if (!start || !limit) return null;
  const elapsed = days(start, now); const left = limit - elapsed;
  if (left < 0) return { label: `${kind} overdue by ${-left} day${left === -1 ? '' : 's'}`, tone: 'danger', overdue: true };
  if (left <= Math.max(1, Math.round(limit * 0.25))) return { label: `${kind} due in ${left} day${left === 1 ? '' : 's'}`, tone: 'warning', overdue: false };
  return { label: `${kind} due ${shortDate(new Date((parseStored(start)?.getTime() ?? Date.now()) + limit * DAY).toISOString())}`, tone: 'neutral', overdue: false };
}

/** Days from sending to acceptance, when both are recorded. */
export const responseDays = (item: TrackedReferral): number | null => item.referred_at && item.accepted_at ? Math.max(0, days(item.referred_at, parseStored(item.accepted_at) ?? new Date())) : null;

/**
 * Status history. Referrals created before history was recorded show their stored
 * dates instead, labelled as such; nothing is invented.
 */
export function history(item: TrackedReferral): Array<ReferralEvent & { derived?: boolean }> {
  if (item.events?.length) return item.events;
  const derived: Array<ReferralEvent & { derived?: boolean }> = [{ from_status: null, to_status: 'DRAFT', created_at: item.created_at, derived: true }];
  if (item.referred_at) derived.push({ from_status: null, to_status: 'SENT', created_at: item.referred_at, derived: true });
  if (item.accepted_at) derived.push({ from_status: null, to_status: 'ACCEPTED', created_at: item.accepted_at, derived: true });
  if (item.completed_at) derived.push({ from_status: null, to_status: 'COMPLETED', created_at: item.completed_at, outcome: item.outcome, derived: true });
  if (REFERRAL_END_STATES.includes(item.status)) derived.push({ from_status: null, to_status: item.status, created_at: null, reason: item.status_reason, derived: true });
  return derived;
}

export const stepLabel = (status: string) => status === 'IN_PROGRESS' ? 'In progress' : titleCase(status);
