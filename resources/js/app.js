import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp();

// Register the same-origin service worker only for built assets. Keep the PWA
// disabled under Vite's dev server to avoid stale service-worker caches while developing.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
            console.error('Metrik POS service worker registration failed.', error);
        });
    }, { once: true });
}

else if ('serviceWorker' in navigator && import.meta.env.DEV) {
    // Avoid stale production caches interfering with Vite HMR on the same local origin.
    navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => registration.unregister());
    }).catch(() => {});
}
