const CACHE = 'kalinga-field-shell-v17';
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




// Phone notifications. The push has no content; ask Kalinga (signed-in
// session) for the newest unread notifications and show them. If the phone
// is signed out or the request fails, show a plain prompt to open Kalinga.
self.addEventListener('push', (event) => event.waitUntil((async () => {
    let items = [];
    try {
        const response = await fetch('/notifications/latest', { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
        if (response.ok) items = (await response.json()).items || [];
    } catch (_) { /* Fall back to a generic prompt below. */ }
    if (!items.length) {
        await self.registration.showNotification('Kalinga', { body: 'You have a new notification. Open Kalinga to see it.', icon: '/icons/kalinga-192.png', badge: '/icons/kalinga-192.png', tag: 'kalinga', data: { url: '/notifications' } });
        return;
    }
    for (const item of items.slice(0, 3)) {
        await self.registration.showNotification(item.title, { body: item.body, icon: '/icons/kalinga-192.png', badge: '/icons/kalinga-192.png', tag: 'kalinga-' + item.id, data: { url: item.action_url || '/notifications' } });
    }
})()));

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/notifications', self.location.origin);
    if (target.origin !== self.location.origin) return;
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
        if (open) { await open.focus(); return open.navigate(target.href); }
        return self.clients.openWindow(target.href);
    })());
});
