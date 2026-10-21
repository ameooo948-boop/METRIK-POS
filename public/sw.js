/* Metrik POS PWA service worker. Never cache authenticated HTML or API responses. */
const STATIC_CACHE = 'metrik-pos-static-v1';
const ASSET_CACHE = 'metrik-pos-assets-v1';
const STATIC_FILES = [
    '/offline.html',
    '/offline-pos.js',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
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

    // Dynamic authenticated page responses are always network-only. On failure,
    // show the locally cached offline POS shell; never persist tenant HTML in Cache API.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => {
            // Only route POS navigation to the offline cashier shell. Do not show
            // cached store data when someone attempts to open login or unrelated pages.
            if (url.pathname !== '/pos' && url.pathname !== '/offline.html') {
                return new Response('Metrik POS is offline. Reconnect and retry.', {
                    status: 503,
                    headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                });
            }
            const cached = await caches.match('/offline.html');
            return cached || new Response('Metrik POS offline', {
                status: 503,
                headers: { 'Content-Type': 'text/plain; charset=utf-8' },
            });
        }));
        return;
    }

    // Only cache versioned Vite build assets and explicit public PWA assets.
    const isBuildAsset = url.pathname.startsWith('/build/assets/');
    const isPwaStatic = ['/offline.html', '/offline-pos.js', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png'].includes(url.pathname);
    if (!isBuildAsset && !isPwaStatic) return;

    event.respondWith(caches.match(request).then(async (cached) => {
        if (cached) return cached;
        const response = await fetch(request);
        if (response && response.ok && (isBuildAsset || isPwaStatic)) {
            const cache = await caches.open(isBuildAsset ? ASSET_CACHE : STATIC_CACHE);
            cache.put(request, response.clone());
        }
        return response;
    }));
});
