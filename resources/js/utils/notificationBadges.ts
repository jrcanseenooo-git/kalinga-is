/**
 * Badges that make calendar changes stand out in the bell and on the
 * Notifications page (owner rule 2026-10-28: a rescheduled or cancelled
 * activity must be unmistakable).
 */
export function changeBadge(type?: string | null): { label: string; tone: string } | null {
  if (type === 'calendar.cancelled') return { label: 'Cancelled', tone: 'bg-rose-700 text-white' };
  if (type === 'calendar.rescheduled') return { label: 'Rescheduled', tone: 'bg-amber-200 text-amber-950' };
  return null;
}

/** The title without the "Cancelled: " / "Rescheduled: " prefix when the badge already says it. */
export function badgeTitle(type: string | null | undefined, title: string): string {
  return changeBadge(type) ? title.replace(/^(Cancelled|Rescheduled): /, '') : title;
}
