/* Metrik POS PWA service worker. Never cache authenticated HTML or API responses. */
const STATIC_CACHE = 'metrik-pos-static-v4';
const ASSET_CACHE = 'metrik-pos-assets-v4';
const STATIC_FILES = [
    '/offline.html',
    '/offline-unavailable.html',
    '/offline-pos.js',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/images/metrik-pos-logo.svg',
    '/images/metrik-pos-logo-light.svg',
    '/images/metrik-pos-mark.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_FILES))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys
                .filter((key) => key.startsWith('metrik-pos-') && ![STATIC_CACHE, ASSET_CACHE].includes(key))
                .map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => {
            const path = url.pathname.replace(/\/+$/, '') || '/';
            // Only the POS route opens the cached offline cashier. Login, reports,
            // dashboard and other pages use a clear offline explanation instead.
            const fallbackPath = path === '/pos' || path === '/offline.html'
                ? '/offline.html'
                : '/offline-unavailable.html';
            const cached = await caches.match(fallbackPath);
            return cached || new Response('Metrik POS: connection unavailable.', {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' },
            });
        }));
        return;
    }

    const isBuildAsset = url.pathname.startsWith('/build/assets/');
    const isPwaStatic = STATIC_FILES.includes(url.pathname);
    if (!isBuildAsset && !isPwaStatic) return;

    event.respondWith(caches.match(request).then(async (cached) => {
        if (cached) return cached;
        const response = await fetch(request);
        if (response && response.ok) {
            const cache = await caches.open(isBuildAsset ? ASSET_CACHE : STATIC_CACHE);
            await cache.put(request, response.clone());
        }
        return response;
    }));
});
