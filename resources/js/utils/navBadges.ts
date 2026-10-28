import { router } from '@inertiajs/vue3';

let pending: ReturnType<typeof setTimeout> | null = null;
/**
 * Refresh the menu badges (unread messages, calendar) after messages were
 * read on this page. Only the small navCounts prop is reloaded.
 */
export function refreshNavBadges(): void {
    if (pending) clearTimeout(pending);
    pending = setTimeout(() => { pending = null; router.reload({ only: ['navCounts'] }); }, 400);
}
