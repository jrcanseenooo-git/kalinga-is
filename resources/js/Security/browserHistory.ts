import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

export const accessCheckPending = ref(false);
const unsavedWork = new Set<() => boolean>();

export function registerUnsavedCaseWork(check: () => boolean): () => void {
    unsavedWork.add(check);
    return () => { unsavedWork.delete(check); };
}

function hasUnsavedWork(): boolean { return [...unsavedWork].some(check => check()); }
function confirmLeaving(): boolean {
    return !hasUnsavedWork() || window.confirm('This page has unsaved casework. Leave and discard those entries?');
}

// Confirmed denial must never keep a stale sensitive editor visible. Only the
// framework history key is cleared; encrypted field packs/queues are untouched.
export function reloadAfterAccessDenial(): void {
    accessCheckPending.value = true;
    document.title = 'Kalinga IS - checking access';
    router.clearHistory();
    window.history.replaceState(null, '');
    window.location.reload();
}

// Encryption protects stored history, not authorization freshness. Every
// visited Inertia history entry must obtain current server/controlled-shell
// authorization rather than restoring previously authorized case props.
export function protectBrowserHistory(): void {
    const hasPage = (state: unknown): state is { page: unknown } => Boolean(state && typeof state === 'object' && 'page' in state && state.page);
    const safeState = (state: unknown): unknown => hasPage(state) && state.page instanceof ArrayBuffer ? state : null;
    if (hasPage(window.history.state) && !(window.history.state.page instanceof ArrayBuffer)) window.history.replaceState(null, '');
    let knownUrl = window.location.href;
    let knownState = safeState(window.history.state);
    router.on('navigate', () => { knownUrl = window.location.href; knownState = safeState(window.history.state); });
    router.on('before', event => {
        if (accessCheckPending.value) return;
        const visit = event.detail.visit;
        const leavingPage = visit.method === 'get' && (visit.url.href !== window.location.href || visit.preserveState !== true);
        const signingOut = visit.url.pathname === new URL(route('logout'), window.location.origin).pathname;
        if ((leavingPage || signingOut) && !confirmLeaving()) event.preventDefault();
    });
    window.addEventListener('beforeunload', event => {
        if (!accessCheckPending.value && hasUnsavedWork()) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('popstate', event => {
        if (!hasPage(event.state)) return;
        event.stopImmediatePropagation();
        if (!confirmLeaving()) {
            window.history.pushState(knownState, '', knownUrl);
            return;
        }
        reloadAfterAccessDenial();
    }, { capture: true });
}
