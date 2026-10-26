const CACHE = 'kalinga-field-shell-v16';
// The PSGC location list is needed by the intake address fields with no signal.
const SHELL = ['/manifest.webmanifest', '/offline.html', '/icons/kalinga.svg', '/build/manifest.json', '/data/psgc-2q-2026.json'];
const WORKSPACE = '/offline-workspace';
const WORKSPACE_URL = new URL(WORKSPACE, self.location.origin).href;
const WORKSPACE_PATHS = new Set([WORKSPACE, '/offline']);
// Opening Kalinga (home screen icon, bookmark or sign-in page) with no signal goes
// straight to Field mode when it is saved on this device, instead of a dead end.
const ENTRY_PATHS = new Set(['/', '/login', '/dashboard']);

async function cacheFrontendBundle(cache) {
    const response = await fetch('/build/manifest.json', { cache: 'no-store' });
    if (!response.ok) return;
    const manifest = await response.json();
    const files = new Set();
    Object.values(manifest).forEach((entry) => {
        if (entry.file) files.add(`/build/${entry.file}`);
        (entry.css || []).forEach((file) => files.add(`/build/${file}`));
        (entry.assets || []).forEach((file) => files.add(`/build/${file}`));
    });
    await Promise.all([...files].map(async (file) => {
        try { const asset = await fetch(file, { cache: 'no-store' }); if (asset.ok) await cache.put(file, asset); } catch (_) { /* The next online load retries this asset. */ }
    }));
}

self.addEventListener('install', (event) => event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    await cache.addAll(SHELL);
    await cacheFrontendBundle(cache);
    await self.skipWaiting();
})()));

self.addEventListener('activate', (event) => event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith('kalinga-') && key !== CACHE).map((key) => caches.delete(key)));
    await self.clients.claim();
})()));

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
    if (event.data?.type === 'CLAIM_CLIENTS') event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);
    const local = url.origin === self.location.origin;
    const isWorkspace = local && WORKSPACE_PATHS.has(url.pathname);
    const isStaticAsset = local && (url.pathname.startsWith('/build/') || url.pathname.startsWith('/data/') || url.pathname === '/manifest.webmanifest' || url.pathname.startsWith('/icons/'));

    // The authenticated workspace is the field app shell. Cache every successful
    // online response, including the background prefetch from app.ts, so a worker
    // who has provisioned the device does not fall through to the generic page.
    if (isWorkspace) {
        event.respondWith(fetch(event.request).then(async (response) => {
            if (response.ok && new URL(response.url).pathname === WORKSPACE
                && response.headers.get('Content-Type')?.includes('text/html') && !response.headers.has('X-Inertia')) {
                await (await caches.open(CACHE)).put(WORKSPACE_URL, response.clone());
            }
            return response;
        }).catch(async () => (await caches.match(WORKSPACE_URL)) || await caches.match('/offline.html')));
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(fetch(event.request).catch(async () => {
            if (local && ENTRY_PATHS.has(url.pathname) && await caches.match(WORKSPACE_URL)) return Response.redirect(WORKSPACE_URL, 302);
            return (await caches.match(event.request)) || await caches.match('/offline.html');
        }));
        return;
    }

    if (isStaticAsset) {
        event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request).then(async (response) => {
            if (response.ok) (await caches.open(CACHE)).put(event.request, response.clone());
            return response;
        })));
    }
});



