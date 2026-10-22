export const FIELD_CACHE = 'kalinga-field-shell-v15';
const PREPARATION_TIMEOUT_MS = 20_000;
let preparation: Promise<void> | null = null;

async function waitForControl(registration: ServiceWorkerRegistration): Promise<void> {
    if (navigator.serviceWorker.controller) return;
    await new Promise<void>((resolve, reject) => {
        const finish = () => {
            if (!navigator.serviceWorker.controller) return;
            clearTimeout(timeout);
            navigator.serviceWorker.removeEventListener('controllerchange', finish);
            resolve();
        };
        const timeout = setTimeout(() => {
            navigator.serviceWorker.removeEventListener('controllerchange', finish);
            reject(new Error('Field page is not controlled yet. Retry preparation while online.'));
        }, 15_000);
        navigator.serviceWorker.addEventListener('controllerchange', finish);
        registration.active?.postMessage({ type: 'CLAIM_CLIENTS' });
        finish();
    });
}

export async function fieldAppReady(): Promise<boolean> {
    if (!('serviceWorker' in navigator) || !navigator.serviceWorker.controller || !('caches' in window)) return false;
    try {
        const shell = await (await caches.open(FIELD_CACHE)).match(new URL('/offline-workspace', location.origin).href);
        return Boolean(shell?.headers.get('Content-Type')?.includes('text/html'));
    } catch { return false; }
}

export function prepareFieldApp(pageProps: { auth?: unknown }): Promise<void> {
    const auth = pageProps.auth as { user?: unknown; permissions?: string[] } | undefined;
    if (!auth?.user || !auth.permissions?.includes('offline.use')) return Promise.resolve();
    if (!('serviceWorker' in navigator)) return Promise.reject(new Error('This browser does not support the field app.'));
    if (preparation) return preparation;
    preparation = (async () => {
        const registration = await navigator.serviceWorker.register('/sw.js');
        registration.waiting?.postMessage({ type: 'SKIP_WAITING' });
        const ready = await new Promise<ServiceWorkerRegistration>((resolve, reject) => {
            const timeout = setTimeout(() => reject(new Error('Field worker activation timed out. Retry while online.')), PREPARATION_TIMEOUT_MS);
            navigator.serviceWorker.ready.then(value => { clearTimeout(timeout); resolve(value); }, error => { clearTimeout(timeout); reject(error); });
        });
        await waitForControl(ready);
        if (navigator.onLine && 'caches' in window) {
            const workspace = await fetch('/offline-workspace', { credentials: 'same-origin', cache: 'no-store' });
            if (!workspace.ok || new URL(workspace.url).pathname !== '/offline-workspace'
                || !workspace.headers.get('Content-Type')?.includes('text/html') || workspace.headers.has('X-Inertia')) throw new Error('Authenticated field HTML is unavailable.');
            await (await caches.open(FIELD_CACHE)).put(new URL('/offline-workspace', location.origin).href, workspace);
        }
    })().finally(() => { preparation = null; });
    return preparation;
}
