/**
 * Kalinga's own confirmation dialog, used instead of the browser's plain
 * confirm() box. Call askConfirm() and await the answer:
 *
 *   if (!(await askConfirm({ title: 'Remove this row?', tone: 'danger', confirmLabel: 'Remove' }))) return;
 *
 * One ConfirmDialogHost (in the page layouts) shows the request. Pages that
 * have no host (very rare) fall back to the browser box so nothing is
 * skipped silently.
 */
import { reactive } from 'vue';

export type ConfirmRequest = {
    title: string;
    message?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** danger: removes, discards or ends something. */
    tone?: 'danger' | 'default';
};

export const confirmState = reactive<{ request: ConfirmRequest | null; hosts: number }>({ request: null, hosts: 0 });
let resolver: ((value: boolean) => void) | null = null;

export function askConfirm(request: ConfirmRequest | string): Promise<boolean> {
    const value = typeof request === 'string' ? { title: request } : request;
    if (confirmState.hosts === 0) {
        return Promise.resolve(window.confirm([value.title, value.message].filter(Boolean).join('\n\n')));
    }
    resolver?.(false);
    return new Promise(resolve => {
        resolver = resolve;
        confirmState.request = value;
    });
}

/** Called by the host when the person answers (or closes the dialog). */
export function settleConfirm(answer: boolean): void {
    const resolve = resolver;
    resolver = null;
    confirmState.request = null;
    resolve?.(answer);
}
